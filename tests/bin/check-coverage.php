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

// Test code: refuse to run from a web request.
PHP_SAPI === 'cli' || exit(1);

/*
 * Parse a PHPUnit Clover XML report, print measured line coverage and the
 * top uncovered files, and fail if statement coverage is below the minimum.
 *
 *   php tests/bin/check-coverage.php coverage/clover.xml 85
 *
 * Must parse on PHP 8.0 (linted by entry-point-lint).
 */

$cloverPath = isset($argv[1]) ? (string) $argv[1] : 'coverage/clover.xml';
$minPercent = isset($argv[2]) && is_numeric($argv[2]) ? (float) $argv[2] : 85.0;

if (!is_file($cloverPath)) {
	fwrite(STDERR, 'Coverage file not found: ' . $cloverPath . PHP_EOL);
	exit(1);
}

$xml = simplexml_load_file($cloverPath);

if ($xml === false || !isset($xml->project->metrics)) {
	fwrite(STDERR, 'Failed to parse Clover XML: ' . $cloverPath . PHP_EOL);
	exit(1);
}

$metrics    = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered    = (int) $metrics['coveredstatements'];
$percent    = $statements > 0 ? round(($covered * 100.0) / $statements, 2) : 0.0;

printf("Measured line coverage: %d / %d (%.2f%%)\n", $covered, $statements, $percent);

$files = array();
$nodes = $xml->xpath('//file');

if (is_array($nodes)) {
	foreach ($nodes as $file) {
		if (!isset($file->metrics)) {
			continue;
		}

		$s = (int) $file->metrics['statements'];
		$c = (int) $file->metrics['coveredstatements'];

		if ($s > $c) {
			$files[] = array(
				'name'      => basename((string) $file['name']),
				'covered'   => $c,
				'total'     => $s,
				'uncovered' => $s - $c,
				'pct'       => $s > 0 ? round(($c * 100.0) / $s, 1) : 100.0,
			);
		}
	}
}

usort($files, function ($a, $b) {
	return $b['uncovered'] <=> $a['uncovered'];
});

foreach (array_slice($files, 0, 20) as $row) {
	printf("  %-32s %4d / %4d (%5.1f%%) uncovered=%d\n", $row['name'], $row['covered'], $row['total'], $row['pct'], $row['uncovered']);
}

if ($percent < $minPercent) {
	fwrite(STDERR, sprintf("Line coverage %.2f%% is below required minimum %.2f%%\n", $percent, $minPercent));
	exit(1);
}

printf("Coverage gate passed (>= %.2f%%)\n", $minPercent);
