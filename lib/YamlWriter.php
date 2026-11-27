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
 * Deterministic writer for the safe YAML subset parsed by YamlReader.
 */
final class YamlWriter {
	/**
	 * @param array<array-key, mixed> $data
	 */
	public static function write(array $data): string {
		if ($data === []) {
			throw new ConfigException('An empty document cannot be written');
		}

		$out = [];
		self::collection($data, 0, $out, false);

		return implode("\n", $out) . "\n";
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @param list<string>            $out
	 */
	private static function collection(array $data, int $indent, array &$out, bool $firstInline): void {
		$pad   = str_repeat(' ', $indent);
		$first = $firstInline;

		if (array_is_list($data)) {
			foreach ($data as $item) {
				$prefix = $first ? '' : $pad;
				$first  = false;

				if (is_array($item)) {
					if ($item === [] || array_is_list($item)) {
						throw new ConfigException('A list item must be a scalar or a non-empty map');
					}

					$block = [];
					self::collection($item, $indent + 2, $block, true);
					$block[0] = $prefix . '- ' . $block[0];
					array_push($out, ...$block);

					continue;
				}

				$out[] = $prefix . '- ' . self::scalar($item);
			}

			return;
		}

		foreach ($data as $key => $value) {
			$prefix = $first ? '' : $pad;
			$first  = false;
			$name   = self::scalar((string) $key);

			if (is_array($value)) {
				if ($value === []) {
					throw new ConfigException(sprintf('"%s" is empty; omit the key instead', (string) $key));
				}

				$out[] = $prefix . $name . ':';
				self::collection($value, $indent + 2, $out, false);

				continue;
			}

			$out[] = $prefix . $name . ': ' . self::scalar($value);
		}
	}

	private static function scalar(mixed $value): string {
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (is_int($value)) {
			return (string) $value;
		}

		if (!is_string($value)) {
			throw new ConfigException('Only strings, integers, and booleans can be written');
		}

		if (preg_match('//u', $value) !== 1) {
			throw new ConfigException('A value is not valid UTF-8');
		}

		if (YamlScalar::plainSafe($value)) {
			return $value;
		}

		if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
			return '"' . str_replace(['\\', '"', "\n", "\t", "\r"], ['\\\\', '\\"', '\\n', '\\t', '\\r'], $value) . '"';
		}

		return "'" . str_replace("'", "''", $value) . "'";
	}
}
