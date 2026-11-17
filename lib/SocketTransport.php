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
 * HTTP/1.1 over stream_socket_client() with its own bounded reader, the
 * pattern of plugin_cloud ADR 0009. One GET per connection, certificate and
 * host name verification on for HTTPS, no redirects followed, the connect
 * and TLS handshake inside the caller's deadline, at most 64 KiB of headers,
 * a body cap, strict Content-Length, and strict chunked decoding: PHP's
 * dechunk filter passes a malformed chunk through as data. Plain TCP is used
 * only for an Endpoint that already passed the loopback rule.
 */
final class SocketTransport implements HttpTransport {
	private const MAX_HEADER_BYTES = 65536;

	/**
	 * @param ?string $caFile a CA bundle for tests only; production uses
	 *                        OpenSSL's configured trust store
	 */
	public function __construct(
		private readonly int $maxBytes,
		private readonly float $connectTimeout = 5.0,
		private readonly ?string $caFile = null,
	) {
	}

	public function get(HttpRequest $request, float $deadline): HttpResponse {
		$endpoint = $request->endpoint;

		if (preg_match('#^(/[A-Za-z0-9._~-]+)+/?$#', $request->path) !== 1) {
			throw new TransportError(ErrorClass::Refused, 'path');
		}

		$query = [];

		foreach ($request->query as $name => $value) {
			if (preg_match('/^[a-z_]+$/', $name) !== 1 || $value < 0) {
				throw new TransportError(ErrorClass::Refused, 'query');
			}

			$query[] = $name . '=' . $value;
		}

		$target = $request->path . ($query === [] ? '' : '?' . implode('&', $query));

		// A CR or LF in a name or value would let a caller inject headers or
		// a second request.
		$raw = 'GET ' . $target . " HTTP/1.1\r\nHost: " . $endpoint->hostHeader() . "\r\nConnection: close\r\nAccept: application/json\r\n";

		foreach ($request->headers as $name => $value) {
			if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1 || preg_match('/[\r\n\x00]/', $value) === 1) {
				throw new TransportError(ErrorClass::Refused, 'header');
			}

			$raw .= "$name: $value\r\n";
		}

		$raw .= "\r\n";

		$ssl = [
			'verify_peer'         => true,
			'verify_peer_name'    => true,
			'allow_self_signed'   => false,
			'peer_name'           => $endpoint->host,
			'SNI_enabled'         => true,
			'disable_compression' => true,
		];

		if ($this->caFile !== null) {
			$ssl['cafile'] = $this->caFile;
		}

		$ctx = stream_context_create(['ssl' => $ssl]);

		$connectBudget = min($this->connectTimeout, $deadline - microtime(true));

		if ($connectBudget <= 0) {
			throw new TransportError(ErrorClass::Timeout, 'deadline');
		}

		$warnings = [];
		set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
			$warnings[] = $message;

			return true;
		});

		try {
			$fp = stream_socket_client($endpoint->socketAddress(), $errno, $errstr, $connectBudget, STREAM_CLIENT_CONNECT, $ctx);
		} finally {
			restore_error_handler();
		}

		if ($fp === false) {
			$message = implode(' | ', array_merge($warnings, ["$errno $errstr"]));

			throw new TransportError(str_contains($message, 'timed out') ? ErrorClass::Timeout : ErrorClass::Unreachable, self::classify($message));
		}

		try {
			// A peer that stops reading must not hold the write past the deadline.
			for ($off = 0; $off < strlen($raw); $off += $n) {
				$this->limit($fp, $deadline);
				$n = @fwrite($fp, substr($raw, $off, 65536));

				if (stream_get_meta_data($fp)['timed_out']) {
					throw new TransportError(ErrorClass::Timeout, 'write');
				}

				if ($n === false || $n === 0) {
					throw new TransportError(ErrorClass::Unreachable, 'write failed');
				}
			}

			// Informational (1xx) responses come before the final one and are
			// skipped; their bytes count towards the same header limit.
			$buffer    = '';
			$headBytes = 0;

			while (true) {
				$headEnd = strpos($buffer, "\r\n\r\n");

				while ($headEnd === false) {
					if ($headBytes + strlen($buffer) > self::MAX_HEADER_BYTES) {
						throw new TransportError(ErrorClass::TooLarge, 'headers');
					}

					if (feof($fp)) {
						throw new TransportError(ErrorClass::Malformed, 'connection closed in headers');
					}

					$buffer .= $this->readSome($fp, $deadline);
					$headEnd = strpos($buffer, "\r\n\r\n");
				}

				$headBytes += $headEnd + 4;

				if ($headBytes > self::MAX_HEADER_BYTES) {
					throw new TransportError(ErrorClass::TooLarge, 'headers');
				}

				[$status, $headers] = self::parseHead(substr($buffer, 0, $headEnd));
				$rest               = substr($buffer, $headEnd + 4);

				if ($status === 101) {
					throw new TransportError(ErrorClass::Malformed, 'unexpected 101');
				}

				if ($status >= 200) {
					break;
				}

				$buffer = $rest;
			}

			// These responses end with their headers whatever Content-Length
			// says (RFC 9112 section 6.3).
			if ($status === 204 || $status === 304) {
				return new HttpResponse($status, $headers, '');
			}

			if ($status >= 300 && $status < 400) {
				throw new TransportError(ErrorClass::Refused, 'redirect ' . $status);
			}

			$chunked = str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked');
			$length  = null;

			if (!$chunked && isset($headers['content-length'])) {
				if (preg_match('/^\d{1,12}$/', $headers['content-length']) !== 1) {
					throw new TransportError(ErrorClass::Malformed, 'content-length');
				}

				$length = (int) $headers['content-length'];

				if ($length > $this->maxBytes) {
					throw new TransportError(ErrorClass::TooLarge, 'content-length');
				}
			}

			// The raw (still chunked) body is capped too, so chunk framing
			// cannot be used to stream an unbounded amount through.
			while (!feof($fp) && ($length === null || strlen($rest) < $length)) {
				$rest .= $this->readSome($fp, $deadline);

				if (strlen($rest) > $this->maxBytes + ($chunked ? 65536 : 0)) {
					throw new TransportError(ErrorClass::TooLarge, 'body');
				}
			}

			if ($length !== null && strlen($rest) < $length) {
				throw new TransportError(ErrorClass::Malformed, 'body shorter than content-length');
			}

			$body = $chunked ? self::dechunk($rest) : ($length === null ? $rest : substr($rest, 0, $length));

			if (strlen($body) > $this->maxBytes) {
				throw new TransportError(ErrorClass::TooLarge, 'body');
			}

			return new HttpResponse($status, $headers, $body);
		} finally {
			fclose($fp);
		}
	}

	/**
	 * @param resource $fp
	 */
	private function limit($fp, float $deadline): void {
		$left = $deadline - microtime(true);

		if ($left <= 0) {
			throw new TransportError(ErrorClass::Timeout, 'deadline');
		}

		stream_set_timeout($fp, (int) $left, (int) (fmod($left, 1) * 1e6));
	}

	/**
	 * fread() on a socket returns whatever has arrived, so checking the
	 * deadline between reads bounds a server that sends one byte at a time.
	 *
	 * @param resource $fp
	 */
	private function readSome($fp, float $deadline): string {
		$this->limit($fp, $deadline);

		// A peer that closes without TLS close_notify raises a warning on
		// some builds; the caller sees EOF either way.
		$chunk = @fread($fp, 8192);

		if (stream_get_meta_data($fp)['timed_out']) {
			throw new TransportError(ErrorClass::Timeout, 'read');
		}

		return $chunk === false ? '' : $chunk;
	}

	/**
	 * @return array{0: int, 1: array<string, string>}
	 */
	private static function parseHead(string $head): array {
		$lines = explode("\r\n", $head);

		if (preg_match('#^HTTP/1\.[01] (\d{3})(?: |$)#', (string) array_shift($lines), $m) !== 1) {
			throw new TransportError(ErrorClass::Malformed, 'status line');
		}

		$headers = [];

		foreach ($lines as $line) {
			$pos = strpos($line, ':');

			if ($pos === false || $pos === 0 || $line[0] === ' ' || $line[0] === "\t") {
				throw new TransportError(ErrorClass::Malformed, 'header line');
			}

			$name  = strtolower(substr($line, 0, $pos));
			$value = trim(substr($line, $pos + 1), " \t");

			// Two Content-Length values that disagree are a framing attack.
			if ($name === 'content-length' && isset($headers[$name]) && $headers[$name] !== $value) {
				throw new TransportError(ErrorClass::Malformed, 'conflicting content-length');
			}

			$headers[$name] = $value;
		}

		return [(int) $m[1], $headers];
	}

	/**
	 * Strict RFC 9112 chunked decoding. Trailer fields are checked for
	 * shape and ignored; the empty line that ends them is required.
	 */
	private static function dechunk(string $raw): string {
		$out = '';
		$pos = 0;

		while (true) {
			$eol = strpos($raw, "\r\n", $pos);

			if ($eol === false || preg_match('/^([0-9A-Fa-f]{1,8})(?:;[^\r\n]*)?$/', substr($raw, $pos, $eol - $pos), $m) !== 1) {
				throw new TransportError(ErrorClass::Malformed, 'chunk size');
			}

			// At most eight hex digits, so the value always fits an int.
			$size = (int) hexdec($m[1]);
			$pos  = $eol + 2;

			if ($size === 0) {
				while (true) {
					$eol = strpos($raw, "\r\n", $pos);

					if ($eol === false) {
						throw new TransportError(ErrorClass::Malformed, 'chunked terminator');
					}

					$line = substr($raw, $pos, $eol - $pos);
					$pos  = $eol + 2;

					if ($line === '') {
						return $out;
					}

					if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+:/', $line) !== 1) {
						throw new TransportError(ErrorClass::Malformed, 'trailer line');
					}
				}
			}

			if (strlen($raw) < $pos + $size + 2 || substr($raw, $pos + $size, 2) !== "\r\n") {
				throw new TransportError(ErrorClass::Malformed, 'chunk data');
			}

			$out .= substr($raw, $pos, $size);
			$pos += $size + 2;
		}
	}

	/**
	 * A fixed word for the connect failure, so no peer-supplied text is
	 * carried into the log.
	 */
	private static function classify(string $message): string {
		return match (true) {
			str_contains($message, 'certificate verify failed') => 'tls certificate not trusted',
			str_contains($message, 'did not match expected')    => 'tls name mismatch',
			str_contains($message, 'timed out')                 => 'connect timed out',
			str_contains($message, 'refused')                   => 'connection refused',
			default                                             => 'connect failed',
		};
	}
}
