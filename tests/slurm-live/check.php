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
 * Runs the plugin's parser over output captured from the live Slurm cluster
 * in CI (tests/slurm-live/fetch.sh) and asserts what states.sh created, for
 * every data parser the release serves, through both transports' output.
 * The same expectations run in the unit suite against the recorded
 * captures in tests/fixtures/slurm/live (LiveFixturesTest).
 *
 *   php tests/slurm-live/check.php <plugin root> <fetch output directory>
 */

[, $root, $dir] = $argv + [null, null, null];

if (!is_string($root) || !is_string($dir) || !is_dir($dir)) {
	fwrite(STDERR, "usage: php tests/slurm-live/check.php <plugin root> <fetch output directory>\n");
	exit(2);
}

require $root . '/include/autoload.php';
require $root . '/tests/Support/LiveExpectations.php';

if (!function_exists('__')) {
	function __(string $text, mixed ...$args): string {
		return $text;
	}
}

$failed = 0;

foreach (array_filter(array_map('trim', (array) file("$dir/parsers.txt"))) as $parser) {
	foreach (\Somethingwithproof\Hpcsched\Tests\LiveExpectations::check($dir, $parser) as [$label, $pass, $got]) {
		$failed += $pass ? 0 : 1;
		printf("%s %s%s\n", $pass ? 'ok  ' : 'FAIL', $label, $pass ? '' : ' got ' . json_encode($got));
	}
}

exit($failed === 0 ? 0 : 1);
