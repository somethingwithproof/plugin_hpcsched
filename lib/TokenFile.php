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
 * Reads a slurmrestd token from the file a cluster names (ADR 0009).
 *
 * The path must be absolute, the file a regular file and not a symbolic
 * link, not writable by anyone but its owner, and small. The file is opened
 * once and every check after the link test is made on that open handle, so a
 * file swapped between check and read is not read. A file other users can
 * read is still used, because refusing it would stop collection over a
 * permission the administrator can fix, but the state is recorded and the
 * health page warns (REQ-CRED-003). The check pattern is plugin_cloud's
 * SecureFile.
 *
 * The file holds the token itself, or SLURM_JWT=<token> as `scontrol token`
 * prints it.
 */
final class TokenFile {
	public const MAX_BYTES = 16384;

	public static function pathAcceptable(string $path): bool {
		return $path !== '' && strlen($path) <= 255 && $path[0] === '/' && preg_match('/[\x00-\x20\x7f]/', $path) !== 1 && !str_contains($path, '/../') && !str_ends_with($path, '/..');
	}

	/**
	 * @throws CredentialError
	 */
	public static function read(string $path): Token {
		if (!self::pathAcceptable($path)) {
			throw new CredentialError(TokenState::Missing, 'the token file path is not an absolute path');
		}

		clearstatcache(true, $path);

		$link = is_link($path) || file_exists($path) ? @lstat($path) : false;

		if ($link === false) {
			throw new CredentialError(TokenState::Missing, 'the token file does not exist or cannot be read');
		}

		// Checked before opening too: opening a FIFO without a writer blocks.
		if (($link['mode'] & 0170000) !== 0100000) {
			throw new CredentialError(TokenState::Refused, 'the token file is a symbolic link or not a regular file');
		}

		$handle = is_readable($path) ? @fopen($path, 'rb') : false;

		if ($handle === false) {
			throw new CredentialError(TokenState::Missing, 'the token file does not exist or cannot be read');
		}

		try {
			$stat = fstat($handle);

			if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['dev'] !== $link['dev'] || $stat['ino'] !== $link['ino']) {
				throw new CredentialError(TokenState::Refused, 'the token file changed while it was checked');
			}

			if (($stat['mode'] & 0022) !== 0) {
				throw new CredentialError(TokenState::Refused, 'the token file is writable by other users');
			}

			if ($stat['size'] > self::MAX_BYTES) {
				throw new CredentialError(TokenState::Refused, 'the token file is larger than allowed');
			}

			$content = stream_get_contents($handle, self::MAX_BYTES + 1);
		} finally {
			fclose($handle);
		}

		if (!is_string($content) || strlen($content) > self::MAX_BYTES) {
			throw new CredentialError(TokenState::Refused, 'the token file is larger than allowed');
		}

		$value = trim($content);

		if (str_starts_with($value, 'SLURM_JWT=')) {
			$value = trim(substr($value, 10));
		}

		// A header value cannot carry a line break; anything else is the
		// server's to judge.
		if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
			throw new CredentialError(TokenState::Empty, 'the token file is empty or holds more than one line');
		}

		return new Token(new Secret($value), self::state($stat), self::expiry($value));
	}

	/**
	 * @param array<array-key, int> $stat
	 */
	private static function state(array $stat): TokenState {
		$uid = function_exists('posix_geteuid') ? posix_geteuid() : null;

		return match (true) {
			($stat['mode'] & 0004) !== 0             => TokenState::WorldReadable,
			($stat['mode'] & 0040) !== 0             => TokenState::GroupReadable,
			$uid !== null && $stat['uid'] !== $uid   => TokenState::Owner,
			default                                  => TokenState::Ok,
		};
	}

	/**
	 * The exp claim of a JWT, or 0 when the token is not a JWT or carries no
	 * expiry. The signature is not checked: the time only feeds a warning.
	 */
	public static function expiry(string $token): int {
		$parts = explode('.', $token);

		if (count($parts) !== 3 || strlen($parts[1]) > 4096) {
			return 0;
		}

		$payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
		$claims  = is_string($payload) ? json_decode($payload, true, 4) : null;
		$exp     = is_array($claims) ? ($claims['exp'] ?? null) : null;

		return is_int($exp) && $exp > 0 ? $exp : 0;
	}
}
