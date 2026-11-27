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
 * Computes per-partition Slurm queue wait-time percentiles (p50, p95, p99),
 * backfill scheduling efficiency, and head-of-line starvation indicators.
 *
 * Grounded in Yoo, Jette & Grondona (SLURM: Simple Linux Utility for Resource
 * Management, JSSPP 2003). Percentiles use deterministic Hyndman-Fan type-7
 * linear interpolation so metrics stay reproducible across PHP versions.
 */
final class BackfillMetrics {
	/** Default pending wait duration (in seconds) above which a job counts as starving. */
	public const DEFAULT_STARVATION_SECONDS = 3600;

	/**
	 * Pending wait threshold in seconds for head-of-line starvation counting.
	 */
	private readonly int $starvationSeconds;

	/**
	 * Construct a Slurm queue latency and backfill efficiency analyzer.
	 *
	 * @param int $starvationSeconds wait-time threshold in seconds (>= 1)
	 */
	public function __construct(int $starvationSeconds = self::DEFAULT_STARVATION_SECONDS) {
		$this->starvationSeconds = max(1, $starvationSeconds);
	}

	/**
	 * Evaluate per-partition and cluster-wide queue wait-time percentiles and
	 * backfill efficiency.
	 *
	 * Each job record captures either a started job (non-null startTime) or a
	 * currently pending job (null startTime, evaluated against $now).
	 *
	 * @param list<array{
	 *   partition: string,
	 *   submitTime: int,
	 *   startTime: ?int,
	 *   endTime?: ?int,
	 *   allocatedCpus?: int,
	 *   backfilled?: bool
	 * }> $jobs scheduler job timing and backfill observations
	 * @param array<string, float> $idleHoleCpuSeconds partition name => available backfill hole CPU-seconds
	 * @param int                  $now                observation timestamp in Unix seconds
	 *
	 * @return array{
	 *   cluster: array{
	 *     samples: int,
	 *     started_jobs: int,
	 *     pending_jobs: int,
	 *     backfilled_jobs: int,
	 *     backfill_job_ratio: float,
	 *     backfill_cpu_seconds: float,
	 *     idle_hole_cpu_seconds: float,
	 *     backfill_efficiency: float,
	 *     starving_jobs: int,
	 *     wait_p50: float,
	 *     wait_p95: float,
	 *     wait_p99: float,
	 *     wait_mean: float,
	 *     wait_max: float
	 *   },
	 *   partitions: array<string, array{
	 *     samples: int,
	 *     started_jobs: int,
	 *     pending_jobs: int,
	 *     backfilled_jobs: int,
	 *     backfill_job_ratio: float,
	 *     backfill_cpu_seconds: float,
	 *     idle_hole_cpu_seconds: float,
	 *     backfill_efficiency: float,
	 *     starving_jobs: int,
	 *     wait_p50: float,
	 *     wait_p95: float,
	 *     wait_p99: float,
	 *     wait_mean: float,
	 *     wait_max: float
	 *   }>
	 * }
	 */
	public function analyze(array $jobs, array $idleHoleCpuSeconds = [], int $now = 0): array {
		/** @var array<string, array{waits: list<float>, started: int, pending: int, backfilled: int, backfillCpuSec: float, starving: int}> $byPartition */
		$byPartition    = [];
		$clusterWaits   = [];
		$clusterStarted = 0;
		$clusterPending = 0;
		$clusterBfJobs  = 0;
		$clusterBfCpu   = 0.0;
		$clusterStarve  = 0;

		foreach ($jobs as $job) {
			$partition = $job['partition'] !== '' ? $job['partition'] : 'default';

			if (!isset($byPartition[$partition])) {
				$byPartition[$partition] = [
					'waits'          => [],
					'started'        => 0,
					'pending'        => 0,
					'backfilled'     => 0,
					'backfillCpuSec' => 0.0,
					'starving'       => 0,
				];
			}

			$submitTime = max(0, $job['submitTime']);
			$startTime  = $job['startTime'];
			$cpus       = max(0, $job['allocatedCpus'] ?? 0);
			$backfilled = $job['backfilled'] ?? false;

			if ($startTime !== null) {
				$wait = (float) max(0, $startTime - $submitTime);
				$byPartition[$partition]['started']++;
				$clusterStarted++;

				if ($backfilled) {
					$byPartition[$partition]['backfilled']++;
					$clusterBfJobs++;

					$endTime = $job['endTime'] ?? null;
					$runEnd  = $endTime !== null ? $endTime : max($now, $startTime);
					$runSec  = (float) max(0, $runEnd - $startTime);
					$cpuSec  = $runSec * $cpus;

					$byPartition[$partition]['backfillCpuSec'] += $cpuSec;
					$clusterBfCpu                              += $cpuSec;
				}
			} else {
				$wait = (float) max(0, $now - $submitTime);
				$byPartition[$partition]['pending']++;
				$clusterPending++;

				if ($wait >= $this->starvationSeconds) {
					$byPartition[$partition]['starving']++;
					$clusterStarve++;
				}
			}

			$byPartition[$partition]['waits'][] = $wait;
			$clusterWaits[]                     = $wait;
		}

		// Ensure partitions that only report idle holes still appear deterministically.
		foreach ($idleHoleCpuSeconds as $partition => $holeSec) {
			if ($partition !== '' && !isset($byPartition[$partition])) {
				$byPartition[$partition] = [
					'waits'          => [],
					'started'        => 0,
					'pending'        => 0,
					'backfilled'     => 0,
					'backfillCpuSec' => 0.0,
					'starving'       => 0,
				];
			}
		}

		ksort($byPartition);

		$partitionMetrics = [];
		$clusterHoleCpu   = 0.0;

		foreach ($byPartition as $partition => $data) {
			$rawHole = $idleHoleCpuSeconds[$partition] ?? 0.0;
			$holeCpu = (is_finite($rawHole) && $rawHole > 0.0) ? $rawHole : 0.0;

			$clusterHoleCpu              += $holeCpu;
			$partitionMetrics[$partition] = $this->summarize(
				$data['waits'],
				$data['started'],
				$data['pending'],
				$data['backfilled'],
				$data['backfillCpuSec'],
				$holeCpu,
				$data['starving'],
			);
		}

		return [
			'cluster'    => $this->summarize(
				$clusterWaits,
				$clusterStarted,
				$clusterPending,
				$clusterBfJobs,
				$clusterBfCpu,
				$clusterHoleCpu,
				$clusterStarve,
			),
			'partitions' => $partitionMetrics,
		];
	}

	/**
	 * Compute an interpolated percentile (Hyndman-Fan type 7) from a list of
	 * numeric samples.
	 *
	 * @param list<float> $samples unsorted non-negative wait durations in seconds
	 * @param float       $p       percentile in [0.0, 100.0]
	 *
	 * @return float interpolated percentile value, or 0.0 when empty
	 */
	public static function percentile(array $samples, float $p): float {
		$count = count($samples);

		if ($count === 0) {
			return 0.0;
		}

		$sorted = $samples;
		sort($sorted, SORT_NUMERIC);

		$clamped = max(0.0, min(100.0, $p));

		if ($count === 1 || $clamped <= 0.0) {
			return round($sorted[0], 4);
		}

		if ($clamped >= 100.0) {
			return round($sorted[$count - 1], 4);
		}

		$rank  = ($clamped / 100.0) * ($count - 1);
		$lower = (int) floor($rank);
		$upper = (int) ceil($rank);
		$frac  = $rank - $lower;

		return round($sorted[$lower] + ($frac * ($sorted[$upper] - $sorted[$lower])), 4);
	}

	/**
	 * Build a metrics summary array for either one partition or the full cluster.
	 *
	 * @param list<float> $waits          wait durations in seconds
	 * @param int         $started        count of started jobs
	 * @param int         $pending        count of pending jobs
	 * @param int         $backfilled     count of backfilled started jobs
	 * @param float       $backfillCpuSec CPU-seconds executed by backfilled jobs
	 * @param float       $idleHoleCpuSec available idle-hole CPU-seconds
	 * @param int         $starving       count of pending jobs exceeding starvation threshold
	 *
	 * @return array{
	 *   samples: int,
	 *   started_jobs: int,
	 *   pending_jobs: int,
	 *   backfilled_jobs: int,
	 *   backfill_job_ratio: float,
	 *   backfill_cpu_seconds: float,
	 *   idle_hole_cpu_seconds: float,
	 *   backfill_efficiency: float,
	 *   starving_jobs: int,
	 *   wait_p50: float,
	 *   wait_p95: float,
	 *   wait_p99: float,
	 *   wait_mean: float,
	 *   wait_max: float
	 * }
	 */
	private function summarize(
		array $waits,
		int $started,
		int $pending,
		int $backfilled,
		float $backfillCpuSec,
		float $idleHoleCpuSec,
		int $starving,
	): array {
		$samples    = count($waits);
		$jobRatio   = $started > 0 ? round($backfilled / $started, 6) : 0.0;
		$efficiency = $idleHoleCpuSec > 0.0
			? round(min(1.0, max(0.0, $backfillCpuSec / $idleHoleCpuSec)), 6)
			: ($backfillCpuSec > 0.0 ? 1.0 : 0.0);

		return [
			'samples'               => $samples,
			'started_jobs'          => $started,
			'pending_jobs'          => $pending,
			'backfilled_jobs'       => $backfilled,
			'backfill_job_ratio'    => $jobRatio,
			'backfill_cpu_seconds'  => round($backfillCpuSec, 4),
			'idle_hole_cpu_seconds' => round($idleHoleCpuSec, 4),
			'backfill_efficiency'   => $efficiency,
			'starving_jobs'         => $starving,
			'wait_p50'              => self::percentile($waits, 50.0),
			'wait_p95'              => self::percentile($waits, 95.0),
			'wait_p99'              => self::percentile($waits, 99.0),
			'wait_mean'             => $samples > 0 ? round(array_sum($waits) / $samples, 4) : 0.0,
			'wait_max'              => $samples > 0 ? round(max($waits), 4) : 0.0,
		];
	}
}
