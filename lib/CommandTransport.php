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
 * Reads Slurm through its client commands, the default transport (decision
 * #7, provisional). Authentication is the poller host's own munge or
 * auth/slurm setup; no credential passes through the plugin.
 *
 * Every argument vector is fixed here: the binary is an absolute path from
 * the global settings, the arguments are constants, and nothing an operator
 * or the scheduler supplies becomes an argument. Commands run without a
 * shell, with an environment of PATH, LC_ALL=C and, when the cluster names
 * one, SLURM_CONF, and within a time and output bound. When prlimit is
 * configured each command runs under it with an address-space limit.
 * Standard error is kept only to classify a failure and is never logged.
 * No vector calls update, reconfigure, requeue, or token (REQ-BACKEND-005,
 * REQ-CRED-004).
 */
final class CommandTransport implements SlurmTransport {
	/**
	 * Section => [binary setting, arguments], with {p} the data parser.
	 */
	public const COMMANDS = [
		'jobs'         => ['squeue', ['--all', '--json={p}']],
		'nodes'        => ['scontrol', ['--json={p}', 'show', 'node']],
		'partitions'   => ['scontrol', ['--json={p}', 'show', 'partition']],
		'reservations' => ['scontrol', ['--json={p}', 'show', 'reservation']],
		'shares'       => ['sshare', ['--json={p}']],
	];

	private int $calls = 0;

	private bool $limited = true;

	public function __construct(
		private readonly CommandRunner $runner,
		private readonly Settings $settings,
		private readonly string $slurmConf,
	) {
	}

	public function transport(): Transport {
		return Transport::Commands;
	}

	public function dataParser(float $deadline): string {
		return DataParser::VERSION;
	}

	public function calls(): int {
		return $this->calls;
	}

	public function memoryLimited(): bool {
		return $this->limited && $this->calls > 0;
	}

	/**
	 * The argument vector for one section, prlimit included.
	 *
	 * @return list<string>
	 *
	 * @throws TransportError
	 */
	public function argv(Section $section, string $dataParser): array {
		if (preg_match('/^v\d\.\d\.\d{1,3}$/', $dataParser) !== 1) {
			throw new TransportError(ErrorClass::Config, 'data parser');
		}

		[$binary, $args] = self::COMMANDS[$section->value];

		$path = match ($binary) {
			'squeue'   => $this->settings->squeue,
			'scontrol' => $this->settings->scontrol,
			default    => $this->settings->sshare,
		};

		if (!Settings::pathAcceptable($path)) {
			throw new TransportError(ErrorClass::Config, $binary . ' path');
		}

		$argv = array_merge([$path], array_map(static fn (string $a): string => str_replace('{p}', $dataParser, $a), $args));

		if ($this->settings->prlimit !== '' && Settings::pathAcceptable($this->settings->prlimit) && is_executable($this->settings->prlimit)) {
			return array_merge([$this->settings->prlimit, '--as=' . ($this->settings->childMemoryMib * 1048576), '--'], $argv);
		}

		$this->limited = false;

		return $argv;
	}

	/**
	 * @return array<string, string>
	 */
	public function environment(): array {
		$env = ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C'];

		if ($this->slurmConf !== '') {
			$env['SLURM_CONF'] = $this->slurmConf;
		}

		return $env;
	}

	public function fetch(Section $section, string $dataParser, float $deadline): string {
		if ($this->slurmConf !== '' && !Settings::pathAcceptable($this->slurmConf)) {
			throw new TransportError(ErrorClass::Config, 'slurm.conf path');
		}

		$argv = $this->argv($section, $dataParser);

		$timeout = min((float) $this->settings->callTimeout, $deadline - microtime(true));

		if ($timeout <= 0) {
			throw new TransportError(ErrorClass::Timeout, 'budget');
		}

		$this->calls++;

		$result = $this->runner->run($argv, $this->environment(), $timeout, $this->settings->maxOutputBytes());

		if (!$result->started) {
			throw new TransportError(ErrorClass::Config, 'the command is missing or not executable');
		}

		if ($result->timedOut) {
			throw new TransportError(ErrorClass::Timeout, sprintf('%.1f s', $result->duration));
		}

		if ($result->tooLarge) {
			throw new TransportError(ErrorClass::TooLarge, 'output');
		}

		if ($result->exitCode !== 0) {
			throw new TransportError(self::classify($result->stderr, $result->exitCode), 'exit ' . (int) $result->exitCode);
		}

		return $result->stdout;
	}

	/**
	 * Reduce a failed command's standard error to a class. The text itself
	 * goes no further.
	 */
	public static function classify(string $stderr, ?int $exit): ErrorClass {
		$text = strtolower($stderr);

		return match (true) {
			$exit === 137 || $exit === null                                                     => ErrorClass::Failed,
			str_contains($text, 'rate limit') || str_contains($text, 'too many')               => ErrorClass::RateLimited,
			str_contains($text, 'output not supported') || str_contains($text, 'data_parser') || str_contains($text, 'invalid parser') => ErrorClass::Config,
			str_contains($text, 'credential') || str_contains($text, 'munge') || str_contains($text, 'authentication') => ErrorClass::Credential,
			str_contains($text, 'access denied') || str_contains($text, 'permission denied') || str_contains($text, 'not authorized') => ErrorClass::Access,
			str_contains($text, 'unable to contact') || str_contains($text, 'connection refused') || str_contains($text, 'socket timed out') || str_contains($text, 'communication connection failure') => ErrorClass::Unreachable,
			str_contains($text, 'cannot allocate memory') || str_contains($text, 'out of memory') || str_contains($text, 'memory allocation') => ErrorClass::TooLarge,
			default                                                                             => ErrorClass::Failed,
		};
	}
}
