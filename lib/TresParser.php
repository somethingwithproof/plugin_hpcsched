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
 * Parses Slurm GRES ("gres", "gres_used") and TRES ("tres_str", "tres",
 * "tres_used") descriptors into GPU counts and memory quantities in MiB.
 *
 * Handles both model-qualified GRES ("gpu:h100:8(S:0-1)", "gpu:a100:4(IDX:0-3)")
 * and TRES strings ("cpu=128,mem=512000M,gres/gpu=8,gres/gpu:h100=8") without
 * double-counting aggregate and model-specific entries.
 */
final class TresParser {
	/**
	 * Extract total or allocated GPU count from a Slurm GRES or TRES string.
	 *
	 * @param string $spec raw gres, gres_used, tres, or tres_used string
	 *
	 * @return int non-negative GPU count
	 */
	public static function parseGpuCount(string $spec): int {
		$spec = trim($spec);

		if ($spec === '' || $spec === '(null)' || strcasecmp($spec, 'none') === 0) {
			return 0;
		}

		// TRES syntax carries "key=value" tokens such as "gres/gpu=8" or "gres/gpu:h100=4".
		if (str_contains($spec, '=')) {
			return self::parseTresGpus($spec);
		}

		return self::parseGresGpus($spec);
	}

	/**
	 * Extract memory in MiB from a Slurm TRES string (for example
	 * "cpu=64,mem=512000M,gres/gpu=8" or "mem=1T").
	 *
	 * @param string $tres raw TRES string
	 *
	 * @return int memory in MiB (0 when absent or invalid)
	 */
	public static function parseMemMib(string $tres): int {
		$tres = trim($tres);

		if ($tres === '' || !str_contains($tres, 'mem=')) {
			return 0;
		}

		foreach (explode(',', $tres) as $part) {
			$part = trim($part);

			if (!str_starts_with($part, 'mem=')) {
				continue;
			}

			$val = substr($part, 4);

			if (preg_match('/^([0-9]+(?:\.[0-9]+)?)([KkMmGgTtPp])?$/', $val, $m) !== 1) {
				return 0;
			}

			$num  = (float) $m[1];
			$unit = strtoupper($m[2] ?? 'M');

			$mib = match ($unit) {
				'K'     => $num / 1024.0,
				'M'     => $num,
				'G'     => $num * 1024.0,
				'T'     => $num * 1048576.0,
				'P'     => $num * 1073741824.0,
				default => $num,
			};

			return $mib >= 0.0 && $mib <= PHP_INT_MAX / 2 ? (int) round($mib) : 0;
		}

		return 0;
	}

	/**
	 * Parse TRES entries for GPU counts, preferring the aggregate "gres/gpu=N"
	 * entry when present so model-qualified sub-entries ("gres/gpu:h100=N")
	 * are never double-counted.
	 *
	 * @param string $tres comma-separated TRES key=value list
	 *
	 * @return int
	 */
	private static function parseTresGpus(string $tres): int {
		$aggregate = null;
		$byModel   = 0;

		foreach (explode(',', $tres) as $token) {
			$token = trim($token);

			if ($token === '' || !str_contains($token, '=')) {
				continue;
			}

			[$key, $val] = explode('=', $token, 2);
			$key         = strtolower(trim($key));
			$val         = trim($val);

			if (!ctype_digit($val)) {
				continue;
			}

			$count = (int) $val;

			if ($key === 'gres/gpu') {
				$aggregate = $count;
			} elseif (str_starts_with($key, 'gres/gpu:')) {
				$byModel += $count;
			}
		}

		return $aggregate ?? $byModel;
	}

	/**
	 * Parse Slurm GRES list entries such as "gpu:h100:8(S:0-1),gpu:a100:2,nvme:4"
	 * or "gpu:4(IDX:0-3)" into total GPU count.
	 *
	 * @param string $gres comma-separated GRES list
	 *
	 * @return int
	 */
	private static function parseGresGpus(string $gres): int {
		$total = 0;

		foreach (self::splitOutsideParens($gres) as $entry) {
			// Strip optional socket/index annotations like "(S:0-1)" or "(IDX:0-3)".
			$paren = strpos($entry, '(');
			$clean = $paren !== false ? substr($entry, 0, $paren) : $entry;
			$clean = trim($clean);

			if ($clean === '') {
				continue;
			}

			$parts = explode(':', $clean);

			if (strtolower($parts[0]) !== 'gpu') {
				continue;
			}

			$last = $parts[count($parts) - 1];

			if (ctype_digit($last)) {
				$total += (int) $last;
			}
		}

		return $total;
	}

	/**
	 * Split a GRES string on top-level commas without breaking parenthesized
	 * index ranges such as "gpu:h100:4(IDX:0,2-4),nvme:1".
	 *
	 * @param string $input raw GRES string
	 *
	 * @return list<string>
	 */
	private static function splitOutsideParens(string $input): array {
		if (!str_contains($input, '(')) {
			return explode(',', $input);
		}

		$parts = [];
		$buf   = '';
		$depth = 0;
		$len   = strlen($input);

		for ($i = 0; $i < $len; $i++) {
			$c = $input[$i];

			if ($c === '(') {
				$depth++;
				$buf .= $c;
			} elseif ($c === ')') {
				$depth = max(0, $depth - 1);
				$buf  .= $c;
			} elseif ($c === ',' && $depth === 0) {
				$parts[] = $buf;
				$buf     = '';
			} else {
				$buf .= $c;
			}
		}

		if ($buf !== '') {
			$parts[] = $buf;
		}

		return $parts;
	}
}
