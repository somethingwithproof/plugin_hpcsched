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
 * Normalized Slurm controller diagnostics from sdiag or slurmrestd /diag:
 * slurmctld RPC queue depth, active server thread count, backfill scheduler
 * cycle runtime (microseconds) and queue depth, and slurmdbd rollup lag
 * (seconds).
 */
final readonly class ControllerDiagnostics {
	/** Default RPC queue depth warning threshold. */
	public const DEFAULT_RPC_QUEUE_WARN = 1000;

	/** Default RPC queue depth critical threshold. */
	public const DEFAULT_RPC_QUEUE_CRIT = 5000;

	/** Default server thread count warning threshold (near Slurm's 256 limit). */
	public const DEFAULT_SERVER_THREADS_WARN = 180;

	/** Default server thread count critical threshold. */
	public const DEFAULT_SERVER_THREADS_CRIT = 240;

	/** Default backfill cycle max runtime warning threshold in microseconds (30 s). */
	public const DEFAULT_BF_MAX_WARN_US = 30000000;

	/** Default backfill cycle max runtime critical threshold in microseconds (120 s). */
	public const DEFAULT_BF_MAX_CRIT_US = 120000000;

	/** Default slurmdbd rollup lag warning threshold in seconds (1 hour). */
	public const DEFAULT_ROLLUP_LAG_WARN_SEC = 3600;

	/** Default slurmdbd rollup lag critical threshold in seconds (6 hours). */
	public const DEFAULT_ROLLUP_LAG_CRIT_SEC = 21600;

	/**
	 * Construct an immutable controller diagnostics record.
	 *
	 * @param int          $rpcQueueDepth        combined slurmctld RPC queue depth (agent + dbd)
	 * @param int          $agentQueueSize       outgoing slurmctld agent RPC queue depth
	 * @param int          $dbdAgentQueueSize    queued RPCs waiting for slurmdbd
	 * @param int          $serverThreadCount    active slurmctld server thread count
	 * @param int          $bfCycleMeanUs        mean backfill cycle duration in microseconds
	 * @param int          $bfCycleMaxUs         max backfill cycle duration in microseconds
	 * @param int          $bfCycleLastUs        most recent backfill cycle duration in microseconds
	 * @param int          $bfDepthMean          mean backfill cycle job depth
	 * @param int          $bfQueueLen           backfill queue length
	 * @param int          $slurmdbdRollupLagSec slurmdbd rollup lag in seconds
	 * @param string       $health               overall controller health ('healthy', 'degraded', or 'critical')
	 * @param list<string> $warnings             sorted diagnostic warning codes
	 */
	public function __construct(
		public int $rpcQueueDepth,
		public int $agentQueueSize,
		public int $dbdAgentQueueSize,
		public int $serverThreadCount,
		public int $bfCycleMeanUs,
		public int $bfCycleMaxUs,
		public int $bfCycleLastUs,
		public int $bfDepthMean,
		public int $bfQueueLen,
		public int $slurmdbdRollupLagSec,
		public string $health = 'healthy',
		public array $warnings = [],
	) {
	}

	/**
	 * Classify controller diagnostics into health severity and warning codes.
	 *
	 * @param int $rpcQueueDepth        combined RPC queue depth
	 * @param int $serverThreadCount    active server threads
	 * @param int $bfCycleMaxUs         max backfill cycle runtime in microseconds
	 * @param int $slurmdbdRollupLagSec slurmdbd rollup lag in seconds
	 *
	 * @return array{health: string, warnings: list<string>}
	 */
	public static function classify(
		int $rpcQueueDepth,
		int $serverThreadCount,
		int $bfCycleMaxUs,
		int $slurmdbdRollupLagSec,
	): array {
		$level    = 0;
		$warnings = [];

		if ($rpcQueueDepth >= self::DEFAULT_RPC_QUEUE_CRIT) {
			$level      = max($level, 2);
			$warnings[] = 'rpc_queue_critical';
		} elseif ($rpcQueueDepth >= self::DEFAULT_RPC_QUEUE_WARN) {
			$level      = max($level, 1);
			$warnings[] = 'rpc_queue_high';
		}

		if ($serverThreadCount >= self::DEFAULT_SERVER_THREADS_CRIT) {
			$level      = max($level, 2);
			$warnings[] = 'server_threads_critical';
		} elseif ($serverThreadCount >= self::DEFAULT_SERVER_THREADS_WARN) {
			$level      = max($level, 1);
			$warnings[] = 'server_threads_high';
		}

		if ($bfCycleMaxUs >= self::DEFAULT_BF_MAX_CRIT_US) {
			$level      = max($level, 2);
			$warnings[] = 'backfill_cycle_critical';
		} elseif ($bfCycleMaxUs >= self::DEFAULT_BF_MAX_WARN_US) {
			$level      = max($level, 1);
			$warnings[] = 'backfill_cycle_slow';
		}

		if ($slurmdbdRollupLagSec >= self::DEFAULT_ROLLUP_LAG_CRIT_SEC) {
			$level      = max($level, 2);
			$warnings[] = 'slurmdbd_rollup_lag_critical';
		} elseif ($slurmdbdRollupLagSec >= self::DEFAULT_ROLLUP_LAG_WARN_SEC) {
			$level      = max($level, 1);
			$warnings[] = 'slurmdbd_rollup_lag_high';
		}

		sort($warnings);

		$health = match ($level) {
			2       => 'critical',
			1       => 'degraded',
			default => 'healthy',
		};

		return ['health' => $health, 'warnings' => $warnings];
	}

	/**
	 * Convert the diagnostics snapshot to a plain associative array.
	 *
	 * @return array{
	 *   rpc_queue_depth: int,
	 *   agent_queue_size: int,
	 *   dbd_agent_queue_size: int,
	 *   server_thread_count: int,
	 *   bf_cycle_mean_us: int,
	 *   bf_cycle_max_us: int,
	 *   bf_cycle_last_us: int,
	 *   bf_depth_mean: int,
	 *   bf_queue_len: int,
	 *   slurmdbd_rollup_lag_sec: int,
	 *   health: string,
	 *   warnings: list<string>
	 * }
	 */
	public function toArray(): array {
		return [
			'rpc_queue_depth'         => $this->rpcQueueDepth,
			'agent_queue_size'        => $this->agentQueueSize,
			'dbd_agent_queue_size'    => $this->dbdAgentQueueSize,
			'server_thread_count'     => $this->serverThreadCount,
			'bf_cycle_mean_us'        => $this->bfCycleMeanUs,
			'bf_cycle_max_us'         => $this->bfCycleMaxUs,
			'bf_cycle_last_us'        => $this->bfCycleLastUs,
			'bf_depth_mean'           => $this->bfDepthMean,
			'bf_queue_len'            => $this->bfQueueLen,
			'slurmdbd_rollup_lag_sec' => $this->slurmdbdRollupLagSec,
			'health'                  => $this->health,
			'warnings'                => $this->warnings,
		];
	}
}
