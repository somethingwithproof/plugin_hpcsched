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

use Somethingwithproof\Hpcsched\Tests\LiveExpectations;

/*
 * The parser on output recorded from a live Slurm cluster in the CI job
 * (tests/slurm-live), for both transports: client commands and
 * slurmrestd. Captured from slurm-docker-cluster 3.0.2 running 26.05.5 and
 * 25.11.8; 25.05 and earlier remain on the synthetic fixtures.
 */

require_once dirname(__DIR__) . '/Support/LiveExpectations.php';

dataset('live captures', [
	'26.05.5 v0.0.43' => ['26.05', 'v0.0.43'],
	'26.05.5 v0.0.45' => ['26.05', 'v0.0.45'],
	'25.11.8 v0.0.43' => ['25.11', 'v0.0.43'],
]);

it('reads the states, reservation, and jobs the live cluster was given', function (string $release, string $parser) {
	$failed = [];

	foreach (LiveExpectations::check(dirname(__DIR__) . '/fixtures/slurm/live/' . $release, $parser) as [$label, $pass, $got]) {
		if (!$pass) {
			$failed[$label] = $got;
		}
	}

	expect($failed)->toBe([]);
})->with('live captures');

it('counts no finished job, though slurmrestd still lists completed, failed, and cancelled ones', function (string $release, string $parser) {
	$dir  = dirname(__DIR__) . '/fixtures/slurm/live/' . $release;
	$rest = json_decode((string) file_get_contents("$dir/rest-$parser-jobs.json"), true);
	$cli  = json_decode((string) file_get_contents("$dir/squeue.$parser.json"), true);

	$states = static fn (array $doc): array => array_values(array_unique(array_merge(...array_map(static fn (array $job): array => $job['job_state'], $doc['jobs']))));

	expect($states($rest))->toContain('COMPLETED')->toContain('FAILED')
		->and($states($cli))->not->toContain('COMPLETED')->not->toContain('FAILED')
		->and(\Somethingwithproof\Hpcsched\SlurmParser::jobs($rest)->rows())->toBe(\Somethingwithproof\Hpcsched\SlurmParser::jobs($cli)->rows());
})->with('live captures');
