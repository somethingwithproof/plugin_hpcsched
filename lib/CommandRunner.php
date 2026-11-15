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
 * Runs one argument vector through proc_open() with an array command, so no
 * shell parses anything. The child gets /dev/null on stdin and exactly the
 * environment it is given; its stdout is cut off and the child killed past
 * $maxBytes or $timeout seconds. This is the only class in the plugin that
 * starts a process (tests/Security/SourceRulesTest.php).
 */
final class CommandRunner {
	private const CHUNK       = 65536;
	private const STDERR_KEEP = 2048;

	/**
	 * @param list<string>          $argv absolute binary path first
	 * @param array<string, string> $env  the whole environment of the child
	 */
	public function run(array $argv, array $env, float $timeout, int $maxBytes): ProcessResult {
		if ($argv === [] || !str_starts_with($argv[0], '/') || !is_file($argv[0]) || !is_executable($argv[0])) {
			return ProcessResult::notStarted();
		}

		$pipes   = [];
		$process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', $env);

		if (!is_resource($process) || !isset($pipes[1], $pipes[2]) || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
			return ProcessResult::notStarted();
		}

		$started = microtime(true);
		$stdout  = $pipes[1];
		$stderr  = $pipes[2];
		$out     = '';
		$err     = '';
		$open    = [1 => $stdout, 2 => $stderr];
		$exit    = null;

		stream_set_blocking($stdout, false);
		stream_set_blocking($stderr, false);

		while (true) {
			$elapsed = microtime(true) - $started;

			if ($elapsed > $timeout || strlen($out) > $maxBytes) {
				return $this->kill($process, $stdout, $stderr, $err, $elapsed > $timeout && strlen($out) <= $maxBytes, strlen($out) > $maxBytes, $started);
			}

			if ($open !== []) {
				$read   = array_values($open);
				$write  = null;
				$except = null;

				if (@stream_select($read, $write, $except, 0, 100000) === false) {
					usleep(20000);
				}

				foreach ($read as $stream) {
					$fd    = $stream === $stdout ? 1 : 2;
					$chunk = fread($stream, self::CHUNK);

					if ($chunk === false || ($chunk === '' && feof($stream))) {
						unset($open[$fd]);

						continue;
					}

					if ($fd === 1) {
						$out .= $chunk;
					} elseif (strlen($err) < self::STDERR_KEEP) {
						$err .= substr($chunk, 0, self::STDERR_KEEP - strlen($err));
					}
				}
			} else {
				usleep(10000);
			}

			if ($exit === null) {
				$status = proc_get_status($process);

				if (!$status['running']) {
					$exit = $status['exitcode'];
				}
			}

			if ($exit !== null && $open === []) {
				fclose($stdout);
				fclose($stderr);
				proc_close($process);

				return new ProcessResult($exit, $out, $err, false, false, true, microtime(true) - $started);
			}
		}
	}

	/**
	 * @param resource $process
	 * @param resource $stdout
	 * @param resource $stderr
	 */
	private function kill(mixed $process, mixed $stdout, mixed $stderr, string $err, bool $timedOut, bool $tooLarge, float $started): ProcessResult {
		proc_terminate($process, 9);
		fclose($stdout);
		fclose($stderr);
		proc_close($process);

		// A cut-off answer is never handed on as if it were whole.
		return new ProcessResult(null, '', $err, $timedOut, $tooLarge, true, microtime(true) - $started);
	}
}
