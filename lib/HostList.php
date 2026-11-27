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
 * Bounded Slurm hostlist expression expander (for example "c[01-04,08]" or
 * "gpu01,gpu02") used to determine which compute nodes are covered by a
 * maintenance reservation.
 */
final class HostList {
	/** Maximum expansion. */
	public const MAX_EXPANSION = 10000;

	/**
	 * @return list<string>
	 */
	public static function expand(string $expression, int $limit = self::MAX_EXPANSION): array {
		$expression = trim($expression);

		if ($expression === '') {
			return [];
		}

		$tokens = self::splitTopLevel($expression);
		$out    = [];

		foreach ($tokens as $token) {
			foreach (self::expandToken($token, $limit - count($out)) as $name) {
				$out[] = $name;

				if (count($out) >= $limit) {
					return array_values(array_unique($out));
				}
			}
		}

		return array_values(array_unique($out));
	}

	/**
	 * Split comma-separated tokens while keeping bracketed ranges intact.
	 *
	 * @return list<string>
	 */
	private static function splitTopLevel(string $expr): array {
		$parts = [];
		$buf   = '';
		$depth = 0;
		$len   = strlen($expr);

		for ($i = 0; $i < $len; $i++) {
			$c = $expr[$i];

			if ($c === '[') {
				$depth++;
				$buf .= $c;
			} elseif ($c === ']') {
				$depth = max(0, $depth - 1);
				$buf  .= $c;
			} elseif ($c === ',' && $depth === 0) {
				if (trim($buf) !== '') {
					$parts[] = trim($buf);
				}

				$buf = '';
			} else {
				$buf .= $c;
			}
		}

		if (trim($buf) !== '') {
			$parts[] = trim($buf);
		}

		return $parts;
	}

	/**
	 * @return list<string>
	 */
	private static function expandToken(string $token, int $remaining): array {
		if ($remaining <= 0) {
			return [];
		}

		$open  = strpos($token, '[');
		$close = $open !== false ? strpos($token, ']', $open) : false;

		if ($open === false || $close === false) {
			return [$token];
		}

		$prefix = substr($token, 0, $open);
		$inner  = substr($token, $open + 1, $close - $open - 1);
		$suffix = substr($token, $close + 1);
		$values = [];

		foreach (explode(',', $inner) as $part) {
			$part = trim($part);

			if (preg_match('/^([0-9]+)-([0-9]+)$/', $part, $m) === 1) {
				$start = (int) $m[1];
				$end   = (int) $m[2];
				$width = strlen($m[1]);

				if ($end < $start || ($end - $start) > $remaining) {
					continue;
				}

				for ($n = $start; $n <= $end && count($values) < $remaining; $n++) {
					$values[] = str_pad((string) $n, $width, '0', STR_PAD_LEFT);
				}
			} elseif (preg_match('/^[A-Za-z0-9_-]+$/', $part) === 1) {
				$values[] = $part;
			}
		}

		$out = [];

		foreach ($values as $val) {
			foreach (self::expandToken($prefix . $val . $suffix, $remaining - count($out)) as $expanded) {
				$out[] = $expanded;

				if (count($out) >= $remaining) {
					return $out;
				}
			}
		}

		return $out;
	}
}
