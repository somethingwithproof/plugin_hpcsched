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

namespace Somethingwithproof\Hpcsched\Tests;

// Test code: refuse to run from a web request.
PHP_SAPI === 'cli' || exit(1);

require_once __DIR__ . '/Scratch.php';

/**
 * Runs tests/Support/fake-slurmrestd.php on a loopback port for one test
 * file, with certificates from make-certs.php, and removes everything again.
 */
final class FakeRestd {
	public readonly Scratch $scratch;

	public readonly int $port;

	/** @var resource */
	private mixed $process;

	public function __construct(public readonly string $mode, string $pem = 'good.pem') {
		$this->scratch = new Scratch();

		foreach (glob(dirname(__DIR__) . '/fixtures/slurm/25.11/*.json') ?: [] as $file) {
			$this->scratch->file(basename($file), (string) file_get_contents($file));
		}

		if ($mode === 'tls') {
			self::php([__DIR__ . '/make-certs.php', $this->scratch->dir]);
		}

		$this->port = random_int(20000, 59999);
		$command    = [PHP_BINARY, __DIR__ . '/fake-slurmrestd.php', $mode, (string) $this->port, $this->scratch->dir, $this->log(), $this->scratch->dir . '/' . $pem];
		$process    = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

		if (!is_resource($process)) {
			throw new \RuntimeException('cannot start the fake slurmrestd');
		}

		$this->process = $process;

		// Refused connections are expected until the server listens.
		set_error_handler(static fn (): bool => true);

		try {
			for ($i = 0; $i < 100; $i++) {
				$probe = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 0.2);

				if ($probe !== false) {
					fclose($probe);

					return;
				}

				usleep(50000);
			}
		} finally {
			restore_error_handler();
		}

		throw new \RuntimeException('the fake slurmrestd did not start');
	}

	public function url(): string {
		return ($this->mode === 'tls' ? 'https' : 'http') . '://127.0.0.1:' . $this->port;
	}

	public function ca(): string {
		return $this->scratch->dir . '/ca.pem';
	}

	public function log(): string {
		return $this->scratch->dir . '/requests.log';
	}

	/**
	 * @return list<array{method: string, target: string, token: bool, token_sha: string}>
	 */
	public function requests(): array {
		$out = [];

		foreach (is_file($this->log()) ? (file($this->log(), FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
			$out[] = json_decode($line, true);
		}

		return $out;
	}

	public function stop(): void {
		proc_terminate($this->process, 9);
		proc_close($this->process);
		$this->scratch->remove();
	}

	/**
	 * @param list<string> $args
	 */
	private static function php(array $args): void {
		$process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

		if (!is_resource($process) || proc_close($process) !== 0) {
			throw new \RuntimeException('cannot write test certificates');
		}
	}
}
