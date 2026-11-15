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

/*
 * Stand-in for slurmrestd in tests; it never contacts a cluster.
 *
 *   php fake-slurmrestd.php <tls|plain> <port> <fixture dir> <log file> [pem]
 *
 * Serves the fixture files at the read endpoints of every data parser the
 * plugin supports (the same synthetic body for each). The request's
 * X-SLURM-USER-NAME selects a scenario, so one server covers every case.
 * Each request line and whether a token header arrived are appended to the
 * log, never the token itself. Sequential on purpose: the client runs one
 * case at a time. The 120 second cap only stops an orphan.
 */

$mode     = $argv[1] ?? '';
$port     = (int) ($argv[2] ?? 0);
$fixtures = $argv[3] ?? '';
$logFile  = $argv[4] ?? '';
$deadline = time() + 120;
$ctx      = stream_context_create(['ssl' => ['local_cert' => $argv[5] ?? '']]);
$server   = @stream_socket_server(($mode === 'tls' ? 'ssl' : 'tcp') . '://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);

if ($server === false) {
	fwrite(STDERR, "listen failed: $errstr\n");
	exit(1);
}

$routes = [];

foreach (['v0.0.43', 'v0.0.44', 'v0.0.45'] as $parser) {
	$routes += [
		"/slurm/$parser/jobs/"         => 'jobs',
		"/slurm/$parser/nodes/"        => 'nodes',
		"/slurm/$parser/partitions/"   => 'partitions',
		"/slurm/$parser/reservations/" => 'reservations',
		"/slurm/$parser/shares"        => 'shares',
	];
}

function respond($conn, int $status, string $body, string $extra = ''): void {
	@fwrite($conn, "HTTP/1.1 $status X\r\nConnection: close\r\nContent-Type: application/json\r\n$extra" . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
}

while (time() < $deadline) {
	$conn = @stream_socket_accept($server, 1);

	if ($conn === false) {
		continue;
	}

	stream_set_timeout($conn, 5);
	$head = '';

	while (!str_contains($head, "\r\n\r\n") && strlen($head) < 65536) {
		$line = fgets($conn, 8192);

		if ($line === false) {
			break;
		}

		$head .= $line;
	}

	if ($head === '') {
		fclose($conn);

		continue;
	}

	$lines             = explode("\r\n", trim($head));
	[$method, $target] = explode(' ', $lines[0]) + ['', '', ''];
	$headers           = [];

	foreach (array_slice($lines, 1) as $h) {
		[$name, $value]                    = explode(':', $h, 2) + ['', ''];
		$headers[strtolower(trim($name))] = trim($value);
	}

	$scenario = $headers['x-slurm-user-name'] ?? 'ok';
	$token    = $headers['x-slurm-user-token'] ?? '';
	$section  = $routes[$target] ?? null;

	file_put_contents($logFile, json_encode(['method' => $method, 'target' => $target, 'token' => $token !== '', 'token_sha' => hash('sha256', $token)]) . "\n", FILE_APPEND);

	if ($method !== 'GET' || $section === null) {
		respond($conn, 404, '{"errors":[{"error":"Unable to find requested URL"}]}');
	} else {
		match ($scenario) {
			'notmodified' => @fwrite($conn, "HTTP/1.1 304 Not Modified\r\nConnection: close\r\nContent-Length: 40\r\n\r\n"),
			'unauth'      => respond($conn, 401, '{"errors":[{"error":"Authentication failure"}]}'),
			'forbidden'   => respond($conn, 403, '{"errors":[{"error":"Access denied"}]}'),
			'error'       => respond($conn, 500, '{"errors":[{"error":"Unable to contact slurmctld"}]}'),
			'ratelimit'   => respond($conn, 429, '{"errors":[{"error":"Rate limit"}]}'),
			'redirect'    => @fwrite($conn, "HTTP/1.1 302 Found\r\nLocation: https://127.0.0.1:1/x\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"),
			'malformed'   => respond($conn, 200, '{"jobs": [ {"job_state": '),
			'big'         => respond($conn, 200, '{"jobs":[' . str_repeat('{"job_state":["RUNNING"]},', 200000) . '{}]}'),
			'slow'        => (function () use ($conn): void {
				@fwrite($conn, "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: 100\r\n\r\n");
				sleep(10);
			})(),
			default       => respond($conn, 200, (string) file_get_contents($fixtures . '/' . $section . '.json')),
		};
	}

	@fclose($conn);
}
