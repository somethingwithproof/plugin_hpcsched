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

use Somethingwithproof\Hpcsched\BackfillMetrics;
use Somethingwithproof\Hpcsched\DominantResourceFairness;
use Somethingwithproof\Hpcsched\ShareRecord;

it('matches the canonical Ghodsi et al. NSDI 2011 DRF 9-CPU / 18-GB allocation shares', function () {
	// User A holds 3 tasks of <1 CPU, 4 GB> = <3 CPU, 12 GB> -> dominant share 12/18 = 2/3 (memory).
	// User B holds 2 tasks of <3 CPU, 1 GB> = <6 CPU, 2 GB>  -> dominant share 6/9   = 2/3 (cpu).
	$drf    = new DominantResourceFairness();
	$result = $drf->evaluate(
		['cpu' => 9.0, 'memory_gb' => 18.0],
		[
			'user_a' => ['cpu' => 3.0, 'memory_gb' => 12.0],
			'user_b' => ['cpu' => 6.0, 'memory_gb' => 2.0],
		],
	);

	expect($result['jains_fairness_index'])->toBe(1.0)
		->and($result['dominant_share_gap'])->toBe(0.0)
		->and($result['accounts'][0]['account'])->toBe('user_a')
		->and($result['accounts'][0]['dominant_resource'])->toBe('memory_gb')
		->and($result['accounts'][0]['dominant_share'])->toBe(round(2.0 / 3.0, 6))
		->and($result['accounts'][1]['account'])->toBe('user_b')
		->and($result['accounts'][1]['dominant_resource'])->toBe('cpu')
		->and($result['accounts'][1]['dominant_share'])->toBe(round(2.0 / 3.0, 6));
});

it('detects heterogeneous GPU dominance diverging from Slurm Fair-Share and orders DRF priority', function () {
	$drf = new DominantResourceFairness();

	$shares = [
		new ShareRecord('ml_team', 'gpu', 'root', 100.0, 0.1, 0.12, 0.88, 0.90),
		new ShareRecord('physics', 'cpu', 'root', 800.0, 0.6, 0.55, 0.35, 0.40),
		new ShareRecord('bio', 'cpu', 'root', 50.0, 0.05, 0.04, 0.95, 0.98),
	];

	$result = $drf->evaluate(
		['cpu' => 1000.0, 'gpu' => 16.0, 'memory_mb' => 4096000.0],
		[
			'ml_team' => ['cpu' => 32.0, 'gpu' => 12.0, 'memory_mb' => 512000.0],
			'physics' => ['cpu' => 400.0, 'gpu' => 0.0, 'memory_mb' => 1024000.0],
			'bio'     => ['cpu' => 40.0, 'gpu' => 1.0, 'memory_mb' => 128000.0],
		],
		$shares,
	);

	// Dominant shares: bio = 1/16 (0.0625), physics = 400/1000 (0.4), ml_team = 12/16 (0.75).
	expect($result['priority_order'])->toBe(['bio', 'physics', 'ml_team'])
		->and($result['max_dominant_share'])->toBe(0.75)
		->and($result['min_dominant_share'])->toBe(0.0625)
		->and($result['dominant_share_gap'])->toBe(0.6875)
		->and($result['jains_fairness_index'])->toBeLessThan(0.8)
		->and($result['divergent_accounts'])->toBe(['ml_team']);

	$byName = [];

	foreach ($result['accounts'] as $row) {
		$byName[$row['account']] = $row;
	}

	expect($byName['ml_team']['dominant_resource'])->toBe('gpu')
		->and($byName['ml_team']['fairshare_alignment'])->toBe('drf_heavy_fairshare_high')
		->and($byName['physics']['dominant_resource'])->toBe('cpu')
		->and($byName['physics']['fairshare_alignment'])->toBe('aligned')
		->and($byName['bio']['dominant_resource'])->toBe('gpu')
		->and($byName['bio']['fairshare_alignment'])->toBe('aligned');
});

it('handles empty capacities, zero allocations, and invalid inputs in DRF safely', function () {
	$drf = new DominantResourceFairness();

	$empty = $drf->evaluate(['cpu' => 0.0, 'gpu' => -1.0], []);
	expect($empty['accounts'])->toBe([])
		->and($empty['jains_fairness_index'])->toBe(1.0)
		->and($empty['priority_order'])->toBe([]);

	$zero = $drf->evaluate(['cpu' => 64.0], ['a' => ['cpu' => 0.0], 'b' => ['cpu' => -4.0]]);
	expect($zero['jains_fairness_index'])->toBe(1.0)
		->and($zero['priority_order'])->toBe(['a', 'b']);
});

it('computes exact per-partition wait percentiles, backfill efficiency, and starvation counts', function () {
	$metrics = new BackfillMetrics(starvationSeconds: 1800);
	$now     = 1800010000;

	$jobs = [
		// Partition batch: waits = [10, 20, 30, 40, 2000 (pending starving)]
		['partition' => 'batch', 'submitTime' => $now - 110, 'startTime' => $now - 100, 'endTime' => $now, 'allocatedCpus' => 8, 'backfilled' => true],
		['partition' => 'batch', 'submitTime' => $now - 220, 'startTime' => $now - 200, 'endTime' => $now, 'allocatedCpus' => 4, 'backfilled' => false],
		['partition' => 'batch', 'submitTime' => $now - 330, 'startTime' => $now - 300, 'endTime' => $now, 'allocatedCpus' => 8, 'backfilled' => true],
		['partition' => 'batch', 'submitTime' => $now - 440, 'startTime' => $now - 400, 'endTime' => $now, 'allocatedCpus' => 16, 'backfilled' => false],
		['partition' => 'batch', 'submitTime' => $now - 2000, 'startTime' => null, 'allocatedCpus' => 64, 'backfilled' => false],
		// Partition gpu: waits = [60, 120]
		['partition' => 'gpu', 'submitTime' => $now - 260, 'startTime' => $now - 200, 'endTime' => $now - 100, 'allocatedCpus' => 16, 'backfilled' => true],
		['partition' => 'gpu', 'submitTime' => $now - 220, 'startTime' => $now - 100, 'endTime' => $now, 'allocatedCpus' => 16, 'backfilled' => false],
	];

	// Backfill CPU-seconds:
	// batch: (100 * 8) + (300 * 8) = 3200 CPU-s against 4000 hole -> 0.8 efficiency.
	// gpu:   (100 * 16) = 1600 CPU-s against 2000 hole -> 0.8 efficiency.
	$report = $metrics->analyze($jobs, ['batch' => 4000.0, 'gpu' => 2000.0, 'idle_only' => 500.0], $now);

	$batch = $report['partitions']['batch'];
	$gpu   = $report['partitions']['gpu'];
	$idle  = $report['partitions']['idle_only'];

	expect($batch['samples'])->toBe(5)
		->and($batch['started_jobs'])->toBe(4)
		->and($batch['pending_jobs'])->toBe(1)
		->and($batch['backfilled_jobs'])->toBe(2)
		->and($batch['backfill_job_ratio'])->toBe(0.5)
		->and($batch['backfill_cpu_seconds'])->toBe(3200.0)
		->and($batch['backfill_efficiency'])->toBe(0.8)
		->and($batch['starving_jobs'])->toBe(1)
		->and($batch['wait_p50'])->toBe(30.0)
		->and($batch['wait_p95'])->toBe(1608.0)
		->and($batch['wait_p99'])->toBe(1921.6)
		->and($batch['wait_max'])->toBe(2000.0);

	expect($gpu['samples'])->toBe(2)
		->and($gpu['backfill_job_ratio'])->toBe(0.5)
		->and($gpu['backfill_efficiency'])->toBe(0.8)
		->and($gpu['wait_p50'])->toBe(90.0)
		->and($gpu['wait_p95'])->toBe(117.0)
		->and($gpu['wait_p99'])->toBe(119.4);

	expect($idle['samples'])->toBe(0)
		->and($idle['backfill_efficiency'])->toBe(0.0);

	// Cluster total backfill CPU-s = 4800 against 6500 total hole CPU-s.
	expect($report['cluster']['samples'])->toBe(7)
		->and($report['cluster']['starving_jobs'])->toBe(1)
		->and($report['cluster']['backfill_cpu_seconds'])->toBe(4800.0)
		->and($report['cluster']['backfill_efficiency'])->toBe(round(4800.0 / 6500.0, 6));
});

it('handles percentile boundary cases cleanly', function () {
	expect(BackfillMetrics::percentile([], 50.0))->toBe(0.0)
		->and(BackfillMetrics::percentile([42.0], 99.0))->toBe(42.0)
		->and(BackfillMetrics::percentile([10.0, 20.0, 30.0], 0.0))->toBe(10.0)
		->and(BackfillMetrics::percentile([10.0, 20.0, 30.0], 100.0))->toBe(30.0);
});
