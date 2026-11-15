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
 * update_time is never sent: slurmrestd answers 304 when nothing changed,
 * and the plugin wants a whole snapshot every run. A 304 anyway is recorded
 * as not_modified with the section failed, so the previous data stays and
 * is never read as empty.
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

	private int $calls = 0;

	public function __construct(
		private readonly HttpTransport $http,
		private readonly Endpoint $endpoint,
		private readonly string $user,
		private readonly Token $token,
		private readonly float $callTimeout,
	) {
	}

	public function transport(): Transport {
		return Transport::Rest;
	}

	public function dataParser(float $deadline): string {
		return DataParser::VERSION;
	}

	public function calls(): int {
		return $this->calls;
	}

	public function memoryLimited(): bool {
		return true;
	}

	/**
	 * Whether a path is one the plugin may request, for the parser given.
	 */
	public static function allowed(string $path, string $dataParser): bool {
		foreach (self::PATHS as $template) {
			if ($path === str_replace('{p}', $dataParser, $template)) {
				return true;
			}
		}

		return false;
	}

	public function fetch(Section $section, string $dataParser, float $deadline): string {
		if (preg_match('/^v\d\.\d\.\d{1,3}$/', $dataParser) !== 1) {
			throw new TransportError(ErrorClass::Config, 'data parser');
		}

		$path = str_replace('{p}', $dataParser, self::PATHS[$section->value]);

		if (!self::allowed($path, $dataParser)) {
			throw new TransportError(ErrorClass::Refused, 'path not on the allowlist');
		}

		$headers = ['X-SLURM-USER-TOKEN' => $this->token->secret->reveal()];

		if ($this->user !== '') {
			$headers['X-SLURM-USER-NAME'] = $this->user;
		}

		if (microtime(true) >= $deadline) {
			throw new TransportError(ErrorClass::Timeout, 'budget');
		}

		$this->calls++;

		$response = $this->http->get(new HttpRequest($this->endpoint, $this->endpoint->basePath . $path, $headers), min($deadline, microtime(true) + $this->callTimeout));

		return match (true) {
			$response->status === 200 => $response->body,
			$response->status === 304 => throw new TransportError(ErrorClass::NotModified, '304'),
			$response->status === 401 => throw new TransportError(ErrorClass::Credential, '401'),
			$response->status === 403 => throw new TransportError(ErrorClass::Access, '403'),
			$response->status === 404 => throw new TransportError(ErrorClass::Config, '404: the data parser or endpoint is not offered'),
			$response->status === 429 => throw new TransportError(ErrorClass::RateLimited, '429'),
			$response->status === 502, $response->status === 503, $response->status === 504 => throw new TransportError(ErrorClass::Unreachable, (string) $response->status),
			default                   => throw new TransportError(ErrorClass::Failed, (string) $response->status),
		};
	}
}
