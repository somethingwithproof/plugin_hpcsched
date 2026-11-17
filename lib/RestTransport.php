<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_hpcsched, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_hpcsched
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Hpcsched;

/**
 * Reads Slurm through slurmrestd (ADR 0004).
 *
 * Requests are GETs to an exact allowlist of paths (REQ-BACKEND-005). The
 * allowlist matters because slurmrestd routes some GETs to operations that
 * change the cluster: GET /slurm/{parser}/reconfigure/ calls
 * slurm_reconfigure(), and 26.05 adds a GET requeue route
 * (docs/research/slurmrestd.md). The allowlist only limits the plugin's own
 * requests; it does not restrict slurmrestd.
 *
 * The data parser is discovered from the daemon (REQ-REST-002): the newest
 * supported version whose ping/ path it serves, tried newest first. A 404
 * costs no controller call, and a 200 one ping. The reader passes the
 * parser of the cluster's last completed run as $cached, so discovery runs
 * on the first run, after a run that did not complete (a 404 among them),
 * and when the release the daemon reports ships a newer supported parser.
 * The setting in Settings applies to client commands only.
 *
 * update_time is sent only where an unchanged answer is unambiguous
 * (REQ-REST-005, since()): /nodes/ from 26.05.3, which answers 304 with
 * an empty body. A 304 to that request leaves the stored rows as they are and
 * counts as a fresh read. /jobs/ answers an unchanged request with 200, a
 * warning, and no jobs, which would read as an empty queue; partitions and
 * reservations have no unchanged answer; shares take no update_time. A 304
 * to a request without update_time is recorded as not_modified with the
 * section failed, so the previous data stays and is never read as empty.
 */
final class RestTransport implements SlurmTransport {
	/**
	 * Section => path under the base URL, with {p} the data parser.
	 */
	public const PATHS = [
		'jobs'         => '/slurm/{p}/jobs/',
		'nodes'        => '/slurm/{p}/nodes/',
		'partitions'   => '/slurm/{p}/partitions/',
		'reservations' => '/slurm/{p}/reservations/',
		'shares'       => '/slurm/{p}/shares',
	];

	/** Discovery's path: one read-only ping of the controllers. */
	public const PING = '/slurm/{p}/ping/';

	/**
	 * The first release whose /nodes/ answers an unchanged request with 304.
	 * Before 26.05.3 the handler treats an unchanged answer as a failure,
	 * and passes update_time to its partition read too, so a node change
	 * with unchanged partitions fails with HTTP 500 (fixed in 26.05.3).
	 */
	public const CONDITIONAL_NODES_FROM = '26.05.3';

	/**
	 * A conditional read is used only while the stored node rows are younger
	 * than this. On 26.05 the 304 follows node changes only, so a node moved
	 * between partitions without other change would keep its old partitions;
	 * a full read at least this often bounds that.
	 */
	public const FULL_READ_SECONDS = 600;

	private int $calls = 0;

	private string $parser = '';

	public function __construct(
		private readonly HttpTransport $http,
		private readonly Endpoint $endpoint,
		private readonly string $user,
		private readonly Token $token,
		private readonly float $callTimeout,
		private readonly string $cached = '',
	) {
	}

	public function transport(): Transport {
		return Transport::Rest;
	}

	public function dataParser(float $deadline): string {
		if ($this->parser === '') {
			$this->parser = DataParser::supported($this->cached) ? $this->cached : $this->discover($deadline);
		}

		return $this->parser;
	}

	public function calls(): int {
		return $this->calls;
	}

	public function memoryLimited(): bool {
		return true;
	}

	/**
	 * Whether a path is one the plugin may request, for the parser given.
	 * Only a supported parser has paths, and each is matched exactly.
	 */
	public static function allowed(string $path, string $dataParser): bool {
		if (!DataParser::supported($dataParser)) {
			return false;
		}

		foreach (array_merge(self::PATHS, [self::PING]) as $template) {
			if ($path === str_replace('{p}', $dataParser, $template)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The update_time to send for a section, or 0 for an unconditional read.
	 * Only /nodes/ from 26.05.3, after a completed read whose rows are younger
	 * than FULL_READ_SECONDS. The time is the previous observation less the
	 * clock skew the freshness rules allow, so a change the controller
	 * stamped just before the previous read is never taken as old.
	 */
	public function since(Section $section, ?SectionRecord $previous, string $release, int $now): int {
		if ($section !== Section::Nodes || $previous === null || $previous->state !== SectionState::Completed) {
			return 0;
		}

		if (!DataParser::atLeast($release, self::CONDITIONAL_NODES_FROM)) {
			return 0;
		}

		if ($previous->observedAt <= 0 || $now - $previous->ingestedAt > self::FULL_READ_SECONDS) {
			return 0;
		}

		return max(0, $previous->observedAt - FreshnessEvaluator::SKEW_ALLOWANCE);
	}

	public function fetch(Section $section, string $dataParser, float $deadline, int $since = 0): string {
		if (!DataParser::supported($dataParser)) {
			throw new TransportError(ErrorClass::Config, 'data parser');
		}

		$path = str_replace('{p}', $dataParser, self::PATHS[$section->value]);

		if (!self::allowed($path, $dataParser)) {
			throw new TransportError(ErrorClass::Refused, 'path not on the allowlist');
		}

		$response = $this->get($path, $deadline, $since > 0 ? ['update_time' => $since] : []);

		if ($response->status === 304 && $since > 0) {
			throw new Unchanged();
		}

		return $response->status === 200 ? $response->body : throw self::error($response->status);
	}

	/**
	 * The newest supported data parser the daemon serves.
	 */
	private function discover(float $deadline): string {
		foreach (DataParser::newestFirst() as $version) {
			$status = $this->get(str_replace('{p}', $version, self::PING), $deadline)->status;

			if ($status === 200) {
				return $version;
			}

			if ($status !== 404) {
				throw self::error($status);
			}
		}

		throw new TransportError(ErrorClass::Config, 'slurmrestd serves none of the data parsers ' . implode(', ', DataParser::VERSIONS));
	}

	/**
	 * @param array<string, int> $query
	 */
	private function get(string $path, float $deadline, array $query = []): HttpResponse {
		$headers = ['X-SLURM-USER-TOKEN' => $this->token->secret->reveal()];

		if ($this->user !== '') {
			$headers['X-SLURM-USER-NAME'] = $this->user;
		}

		if (microtime(true) >= $deadline) {
			throw new TransportError(ErrorClass::Timeout, 'budget');
		}

		$this->calls++;

		return $this->http->get(new HttpRequest($this->endpoint, $this->endpoint->basePath . $path, $headers, $query), min($deadline, microtime(true) + $this->callTimeout));
	}

	private static function error(int $status): TransportError {
		return match (true) {
			$status === 304 => new TransportError(ErrorClass::NotModified, '304'),
			$status === 401 => new TransportError(ErrorClass::Credential, '401'),
			$status === 403 => new TransportError(ErrorClass::Access, '403'),
			$status === 404 => new TransportError(ErrorClass::Config, '404: the data parser or endpoint is not offered'),
			$status === 429 => new TransportError(ErrorClass::RateLimited, '429'),
			$status === 502, $status === 503, $status === 504 => new TransportError(ErrorClass::Unreachable, (string) $status),
			default         => new TransportError(ErrorClass::Failed, (string) $status),
		};
	}
}
