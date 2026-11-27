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
 * Value readers for Slurm data parser output.
 *
 * Numbers arrive either plain or as {set, infinite, number} objects for
 * values that can be unset, and which form a field uses is not fixed by the
 * source read (docs/research/slurm-client-output.md), so every reader takes
 * both. An unset or infinite value is null, never zero. Unknown keys are
 * ignored, as the REST guide tells clients to do.
 */
final class SlurmJson {
	/** Depth constant for SlurmJson. */
	public const DEPTH = 64;

	/**
	 * Decode one response document. Anything but a JSON object, and any
	 * document whose errors list is not empty, is a failed section.
	 *
	 * @return array<array-key, mixed>
	 *
	 * @throws TransportError
	 */
	public static function decode(string $json): array {
		try {
			$doc = json_decode($json, true, self::DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
		} catch (\JsonException) {
			throw new TransportError(ErrorClass::Malformed, 'json');
		}

		if (!is_array($doc) || array_is_list($doc)) {
			throw new TransportError(ErrorClass::Malformed, 'not an object');
		}

		$errors = $doc['errors'] ?? [];

		if (!is_array($errors)) {
			throw new TransportError(ErrorClass::Malformed, 'errors');
		}

		if ($errors !== []) {
			$text = '';

			foreach ($errors as $error) {
				if (is_array($error)) {
					$text .= ' ' . self::string($error, 'error') . ' ' . self::string($error, 'description');
				}
			}

			throw new TransportError(CommandTransport::classify($text, 1), 'the response reported errors');
		}

		return $doc;
	}

	/**
	 * The record list under $key, or a malformed section when it is missing.
	 *
	 * @param array<array-key, mixed> $doc
	 *
	 * @return list<array<array-key, mixed>>
	 *
	 * @throws TransportError
	 */
	public static function records(array $doc, string $key): array {
		$list = $doc[$key] ?? null;

		if (!is_array($list) || !array_is_list($list)) {
			throw new TransportError(ErrorClass::Malformed, "no $key list");
		}

		return array_values(array_filter($list, 'is_array'));
	}

	/**
	 * The scheduler release from the response metadata, or '' when absent.
	 *
	 * @param array<array-key, mixed> $doc
	 */
	public static function release(array $doc): string {
		$slurm = is_array($doc['meta'] ?? null) && is_array($doc['meta']['slurm'] ?? null) ? $doc['meta']['slurm'] : [];
		$text  = self::string($slurm, 'release');

		if ($text === '' && is_array($slurm['version'] ?? null)) {
			$v    = $slurm['version'];
			$text = sprintf('%s.%s.%s', self::string($v, 'major'), self::string($v, 'minor'), self::string($v, 'micro'));
		}

		return preg_match('/^\d{1,4}\.\d{1,4}(?:\.\d{1,4})?(?:-[A-Za-z0-9.]{1,16})?$/', $text) === 1 ? $text : '';
	}

	/**
	 * @param array<array-key, mixed> $record
	 */
	public static function string(array $record, string $key): string {
		$value = $record[$key] ?? null;

		return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
	}

	/**
	 * @param array<array-key, mixed> $record
	 */
	public static function int(array $record, string $key): ?int {
		$value = self::number($record[$key] ?? null);

		return $value === null || $value < 0 || $value > PHP_INT_MAX / 2 ? null : (int) $value;
	}

	/**
	 * @param array<array-key, mixed> $record
	 */
	public static function float(array $record, string $key): ?float {
		return self::number($record[$key] ?? null);
	}

	/**
	 * A list of strings, from a JSON list or a comma-separated string.
	 *
	 * @param array<array-key, mixed> $record
	 *
	 * @return list<string>
	 */
	public static function strings(array $record, string $key): array {
		$value = $record[$key] ?? null;

		if (is_string($value)) {
			$value = explode(',', $value);
		}

		if (!is_array($value)) {
			return [];
		}

		$out = [];

		foreach ($value as $item) {
			if (is_string($item) && trim($item) !== '') {
				$out[] = trim($item);
			}
		}

		return $out;
	}

	/**
	 * Number.
	 *
	 * @param mixed $value
	 * @return ?float
	 */
	private static function number(mixed $value): ?float {
		if (is_array($value)) {
			if (($value['set'] ?? true) === false || ($value['infinite'] ?? false) === true) {
				return null;
			}

			$value = $value['number'] ?? null;
		}

		if (is_int($value) || is_float($value)) {
			$float = (float) $value;
		} elseif (is_string($value) && is_numeric($value)) {
			$float = (float) $value;
		} else {
			return null;
		}

		return is_finite($float) ? $float : null;
	}

	/**
	 * Number of tasks in a Slurm array expression such as "0-99",
	 * "1,3,5-9", "1-99:2", or "0-31%4" (the % suffix is a throttle, not a
	 * task). An expression the plugin cannot read counts as one task, so a
	 * pending array is never counted as none.
	 */
	public static function arrayTasks(string $expression): int {
		$expression = (string) preg_replace('/%\d+$/', '', trim($expression));

		if ($expression === '' || strlen($expression) > 65536) {
			return 1;
		}

		$total = 0;

		foreach (explode(',', $expression) as $part) {
			if (preg_match('/^(\d{1,9})(?:-(\d{1,9})(?::(\d{1,9}))?)?$/', $part, $m) !== 1) {
				return 1;
			}

			$first = (int) $m[1];
			$last  = isset($m[2]) ? (int) $m[2] : $first;
			$step  = isset($m[3]) ? max(1, (int) $m[3]) : 1;

			if ($last < $first) {
				return 1;
			}

			$total += intdiv($last - $first, $step) + 1;
		}

		return max(1, min($total, 4000001));
	}
}
