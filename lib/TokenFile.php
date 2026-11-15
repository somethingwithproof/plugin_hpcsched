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
 * The file must lie inside one of the token directories an administrator
 * set on Cacti's Settings page, be reached without a symbolic link in its
 * directory path, be a regular file and not a link, belong to the poller's
 * effective user, be writable by no one else, and be small. The directory
 * path is resolved once when the file is checked; the guarantee holds as
 * long as the token directories are writable only by root and the poller
 * user, as the setting asks. The file is opened without blocking, so a FIFO
 * put in its place cannot stall the reader, and every check after the link
 * test is made on the open handle, which must be the same file the link
 * test saw. A file the group or other users can read is still used, because
 * refusing it would stop collection over a permission the administrator can
 * fix, but the state is recorded and the health page warns (REQ-CRED-003).
 * The check pattern is plugin_cloud's SecureFile.
 * The file holds the token itself, or SLURM_JWT=<token> as `scontrol token`
 * prints it.
 */
final class TokenFile {
	public const MAX_BYTES = 16384;

	public static function pathAcceptable(string $path): bool {
		return $path !== '' && strlen($path) <= 255 && $path[0] === '/' && preg_match('/[\x00-\x20\x7f]/', $path) !== 1 && !str_contains($path, '/../') && !str_ends_with($path, '/..');
	}

	/**
	 * @param list<string> $directories absolute token directories (Settings::directories)
	 *
	 * @throws CredentialError
	 */
	public static function read(string $path, array $directories): Token {
		if (!self::pathAcceptable($path)) {
			throw new CredentialError(TokenState::Missing, 'the token file path is not an absolute path');
		}

		if ($directories === []) {
			throw new CredentialError(TokenState::Refused, 'no token file directories are set in Settings');
		}

		clearstatcache(true, $path);

		$parent = realpath(dirname($path));

		if ($parent === false) {
			throw new CredentialError(TokenState::Missing, 'the token file does not exist or cannot be read');
		}

		if ($parent !== dirname($path)) {
			throw new CredentialError(TokenState::Refused, 'the token file path passes through a symbolic link');
		}

		if (!self::inside($parent, $directories)) {
			throw new CredentialError(TokenState::Refused, 'the token file is not inside a token file directory set in Settings');
		}

		$link = is_link($path) || file_exists($path) ? @lstat($path) : false;

		if ($link === false) {
			throw new CredentialError(TokenState::Missing, 'the token file does not exist or cannot be read');
		}

		if (($link['mode'] & 0170000) !== 0100000) {
			throw new CredentialError(TokenState::Refused, 'the token file is a symbolic link or not a regular file');
		}

		// "n" opens with O_NONBLOCK: a FIFO swapped in after lstat() opens at
		// once instead of waiting for a writer, and fstat() below refuses it.
		$handle = is_readable($path) ? @fopen($path, 'rbn') : false;

		if ($handle === false) {
			throw new CredentialError(TokenState::Missing, 'the token file does not exist or cannot be read');
		}

		try {
			$stat = fstat($handle);

			if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['dev'] !== $link['dev'] || $stat['ino'] !== $link['ino']) {
				throw new CredentialError(TokenState::Refused, 'the token file changed while it was checked');
			}

			$uid = function_exists('posix_geteuid') ? posix_geteuid() : null;

			if ($uid === null || $stat['uid'] !== $uid) {
				throw new CredentialError(TokenState::Owner, 'the token file is not owned by the poller user');
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
	 * Whether a resolved directory is one of the token directories or below
	 * one. Each configured directory is resolved too, so it may itself be
	 * given through a link.
	 *
	 * @param list<string> $directories
	 */
	private static function inside(string $dir, array $directories): bool {
		foreach ($directories as $allowed) {
			$real = realpath($allowed);

			if ($real !== false && $real !== '/' && ($dir === $real || str_starts_with($dir, $real . '/'))) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<array-key, int> $stat
	 */
	private static function state(array $stat): TokenState {
		return match (true) {
			($stat['mode'] & 0004) !== 0 => TokenState::WorldReadable,
			($stat['mode'] & 0040) !== 0 => TokenState::GroupReadable,
			default                      => TokenState::Ok,
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
