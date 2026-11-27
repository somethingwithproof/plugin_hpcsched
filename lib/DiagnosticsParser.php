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
 * Parses Slurm controller diagnostics from slurmrestd GET /slurm/{p}/diag
 * JSON documents and classic "sdiag" text output into ControllerDiagnostics.
 */
final class DiagnosticsParser {
	/**
	 * Parse a decoded slurmrestd /diag JSON payload (or sdiag --json output).
	 *
	 * @param array<array-key, mixed> $doc decoded Slurm JSON object
	 * @param ?int                    $now reference Unix timestamp for rollup lag calculation
	 *
	 * @return ControllerDiagnostics
	 *
	 * @throws TransportError
	 */
	public static function parseRestDiag(array $doc, ?int $now = null): ControllerDiagnostics {
		$stats = $doc['statistics'] ?? null;

		if (!is_array($stats) || array_is_list($stats)) {
			throw new TransportError(ErrorClass::Malformed, 'no statistics object');
		}

		$agentQueue    = SlurmJson::int($stats, 'agent_queue_size') ?? 0;
		$dbdAgentQueue = SlurmJson::int($stats, 'dbd_agent_queue_size') ?? 0;
		$explicitRpc   = SlurmJson::int($stats, 'rpc_queue_depth');
		$rpcQueueDepth = $explicitRpc ?? ($agentQueue + $dbdAgentQueue);
		$threads       = SlurmJson::int($stats, 'server_thread_count') ?? 0;
		$bfMeanUs      = SlurmJson::int($stats, 'bf_cycle_mean') ?? 0;
		$bfMaxUs       = SlurmJson::int($stats, 'bf_cycle_max') ?? 0;
		$bfLastUs      = SlurmJson::int($stats, 'bf_cycle_last') ?? 0;
		$bfDepthMean   = SlurmJson::int($stats, 'bf_depth_mean') ?? SlurmJson::int($stats, 'bf_last_depth') ?? 0;
		$bfQueueLen    = SlurmJson::int($stats, 'bf_queue_len') ?? SlurmJson::int($stats, 'bf_queue_len_mean') ?? 0;

		$refNow    = $now ?? (SlurmJson::int($stats, 'req_time') ?? 0);
		$rollupLag = self::extractRollupLag($stats, $doc, $refNow);
		$summary   = ControllerDiagnostics::classify($rpcQueueDepth, $threads, $bfMaxUs, $rollupLag);

		return new ControllerDiagnostics(
			rpcQueueDepth: $rpcQueueDepth,
			agentQueueSize: $agentQueue,
			dbdAgentQueueSize: $dbdAgentQueue,
			serverThreadCount: $threads,
			bfCycleMeanUs: $bfMeanUs,
			bfCycleMaxUs: $bfMaxUs,
			bfCycleLastUs: $bfLastUs,
			bfDepthMean: $bfDepthMean,
			bfQueueLen: $bfQueueLen,
			slurmdbdRollupLagSec: $rollupLag,
			health: $summary['health'],
			warnings: $summary['warnings'],
		);
	}

	/**
	 * Parse classic "sdiag" plain-text output into ControllerDiagnostics.
	 *
	 * @param string $output raw text from sdiag
	 * @param ?int   $now    optional reference Unix timestamp
	 *
	 * @return ControllerDiagnostics
	 *
	 * @throws TransportError
	 */
	public static function parseSdiagText(string $output, ?int $now = null): ControllerDiagnostics {
		$trimmed = trim($output);

		if ($trimmed === '') {
			throw new TransportError(ErrorClass::Malformed, 'empty sdiag output');
		}

		$section       = 'main';
		$matchedAny    = false;
		$threads       = 0;
		$agentQueue    = 0;
		$dbdAgentQueue = 0;
		$bfMeanUs      = 0;
		$bfMaxUs       = 0;
		$bfLastUs      = 0;
		$bfDepthMean   = 0;
		$bfQueueLen    = 0;
		$rollupLag     = 0;
		$lastRollupTs  = 0;

		foreach (explode("\n", $trimmed) as $line) {
			$line = trim($line);

			if ($line === '') {
				continue;
			}

			if (stripos($line, 'Backfilling stats') !== false) {
				$section = 'backfill';

				continue;
			}

			if (stripos($line, 'Remote Procedure Call') !== false) {
				$section = 'rpc';

				continue;
			}

			$colon = strpos($line, ':');

			if ($colon === false) {
				continue;
			}

			$label = strtolower(trim(substr($line, 0, $colon)));
			$raw   = trim(substr($line, $colon + 1));

			if (preg_match('/^([0-9]+)/', $raw, $m) !== 1) {
				continue;
			}

			$val = (int) $m[1];

			if ($label === 'server thread count') {
				$threads    = $val;
				$matchedAny = true;
			} elseif ($label === 'agent queue size') {
				$agentQueue = $val;
				$matchedAny = true;
			} elseif ($label === 'dbd agent queue size') {
				$dbdAgentQueue = $val;
				$matchedAny    = true;
			} elseif ($section === 'backfill' && $label === 'mean cycle') {
				$bfMeanUs   = $val;
				$matchedAny = true;
			} elseif ($section === 'backfill' && $label === 'max cycle') {
				$bfMaxUs    = $val;
				$matchedAny = true;
			} elseif ($section === 'backfill' && $label === 'last cycle') {
				$bfLastUs   = $val;
				$matchedAny = true;
			} elseif ($section === 'backfill' && ($label === 'mean depth cycle' || $label === 'last depth cycle')) {
				if ($label === 'mean depth cycle' || $bfDepthMean === 0) {
					$bfDepthMean = $val;
				}

				$matchedAny = true;
			} elseif ($section === 'backfill' && ($label === 'last queue length' || $label === 'queue length mean')) {
				if ($label === 'last queue length' || $bfQueueLen === 0) {
					$bfQueueLen = $val;
				}

				$matchedAny = true;
			} elseif ($label === 'slurmdbd rollup lag' || $label === 'rollup lag') {
				$rollupLag  = $val;
				$matchedAny = true;
			} elseif ($label === 'last rollup' || $label === 'last rollup time') {
				$lastRollupTs = $val;
				$matchedAny   = true;
			}
		}

		if (!$matchedAny) {
			throw new TransportError(ErrorClass::Malformed, 'unrecognized sdiag output');
		}

		if ($rollupLag === 0 && $lastRollupTs > 0 && $now !== null && $now > $lastRollupTs) {
			$rollupLag = $now - $lastRollupTs;
		}

		$rpcQueueDepth = $agentQueue + $dbdAgentQueue;
		$summary       = ControllerDiagnostics::classify($rpcQueueDepth, $threads, $bfMaxUs, $rollupLag);

		return new ControllerDiagnostics(
			rpcQueueDepth: $rpcQueueDepth,
			agentQueueSize: $agentQueue,
			dbdAgentQueueSize: $dbdAgentQueue,
			serverThreadCount: $threads,
			bfCycleMeanUs: $bfMeanUs,
			bfCycleMaxUs: $bfMaxUs,
			bfCycleLastUs: $bfLastUs,
			bfDepthMean: $bfDepthMean,
			bfQueueLen: $bfQueueLen,
			slurmdbdRollupLagSec: $rollupLag,
			health: $summary['health'],
			warnings: $summary['warnings'],
		);
	}

	/**
	 * Extract slurmdbd rollup lag in seconds from direct lag fields or from
	 * last rollup timestamps relative to $refNow.
	 *
	 * @param array<array-key, mixed> $stats  statistics sub-object
	 * @param array<array-key, mixed> $doc    root /diag document
	 * @param int                     $refNow reference Unix timestamp
	 *
	 * @return int
	 */
	private static function extractRollupLag(array $stats, array $doc, int $refNow): int {
		$direct = SlurmJson::int($stats, 'slurmdbd_rollup_lag_seconds')
			?? SlurmJson::int($stats, 'rollup_lag_seconds')
			?? SlurmJson::int($doc, 'slurmdbd_rollup_lag_seconds');

		if ($direct !== null) {
			return $direct;
		}

		$rollup = $stats['rollups'] ?? $doc['rollups'] ?? $stats['rollup_stats'] ?? null;

		if (is_array($rollup)) {
			// Handle either an object with last_run/hourly/daily or a list of rollup entries.
			$timestamps = [];

			if (array_is_list($rollup)) {
				foreach ($rollup as $item) {
					if (is_array($item)) {
						$ts = SlurmJson::int($item, 'last_run') ?? SlurmJson::int($item, 'last_rollup_time');

						if ($ts !== null && $ts > 0) {
							$timestamps[] = $ts;
						}
					}
				}
			} else {
				$ts = SlurmJson::int($rollup, 'last_run') ?? SlurmJson::int($rollup, 'last_rollup_time');

				if ($ts !== null && $ts > 0) {
					$timestamps[] = $ts;
				}
			}

			if ($timestamps !== [] && $refNow > 0) {
				return max(0, $refNow - max($timestamps));
			}
		}

		$lastRollup = SlurmJson::int($stats, 'last_rollup_time') ?? 0;

		if ($lastRollup > 0 && $refNow > $lastRollup) {
			return $refNow - $lastRollup;
		}

		return 0;
	}
}
