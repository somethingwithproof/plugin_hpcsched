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
 * Evaluates multi-resource Dominant Resource Fairness (DRF) across accounts
 * alongside Slurm Fair-Share observations.
 *
 * Grounded in Ghodsi et al. (Dominant Resource Fairness: Fair Allocation of
 * Multiple Resource Types, USENIX NSDI 2011). Computes each account's
 * normalized shares across heterogeneous resources (CPU, GPU, memory), its
 * dominant resource share, Jain's fairness index, progressive-filling priority
 * ordering, and divergence flags against Slurm's scalar Fair-Share factor.
 */
final class DominantResourceFairness {
	/** Default minimum dominant share considered heavily allocated when checking Slurm fair-share divergence. */
	public const DEFAULT_HIGH_SHARE_THRESHOLD = 0.25;

	/** Default Slurm fair-share factor threshold above which a high-DRF account is flagged as divergent. */
	public const DEFAULT_HIGH_FAIRSHARE_THRESHOLD = 0.70;

	/**
	 * Threshold for dominant share used in Slurm Fair-Share alignment checks.
	 */
	private readonly float $highShareThreshold;

	/**
	 * Threshold for Slurm Fair-Share factor used in alignment checks.
	 */
	private readonly float $highFairshareThreshold;

	/**
	 * Construct a Dominant Resource Fairness evaluator.
	 *
	 * @param float $highShareThreshold     dominant share threshold in (0, 1]
	 * @param float $highFairshareThreshold Slurm fair-share factor threshold in (0, 1]
	 */
	public function __construct(
		float $highShareThreshold = self::DEFAULT_HIGH_SHARE_THRESHOLD,
		float $highFairshareThreshold = self::DEFAULT_HIGH_FAIRSHARE_THRESHOLD,
	) {
		$this->highShareThreshold     = max(0.01, min(1.0, $highShareThreshold));
		$this->highFairshareThreshold = max(0.01, min(1.0, $highFairshareThreshold));
	}

	/**
	 * Compute per-account dominant resource shares, cluster fairness indices,
	 * DRF priority order, and Slurm Fair-Share alignment diagnostics.
	 *
	 * @param array<string, float>                $capacities   resource name => total cluster capacity (> 0)
	 * @param array<string, array<string, float>> $allocations  account name => (resource name => allocated amount)
	 * @param list<ShareRecord>                   $shareRecords optional Slurm account ShareRecord rows
	 *
	 * @return array{
	 *   capacities: array<string, float>,
	 *   accounts: list<array{
	 *     account: string,
	 *     dominant_resource: string,
	 *     dominant_share: float,
	 *     shares: array<string, float>,
	 *     fairshare_factor: ?float,
	 *     effective_usage: ?float,
	 *     fairshare_alignment: string
	 *   }>,
	 *   priority_order: list<string>,
	 *   jains_fairness_index: float,
	 *   max_dominant_share: float,
	 *   min_dominant_share: float,
	 *   dominant_share_gap: float,
	 *   divergent_accounts: list<string>
	 * }
	 */
	public function evaluate(array $capacities, array $allocations, array $shareRecords = []): array {
		$validCapacities = [];

		foreach ($capacities as $resource => $capacity) {
			if ($resource !== '' && is_finite($capacity) && $capacity > 0.0) {
				$validCapacities[$resource] = $capacity;
			}
		}

		ksort($validCapacities);

		$slurmByAccount = $this->indexShareRecords($shareRecords);
		$accountNames   = array_values(array_unique(array_merge(array_keys($allocations), array_keys($slurmByAccount))));
		sort($accountNames);

		$rows              = [];
		$dominantShares    = [];
		$divergentAccounts = [];

		foreach ($accountNames as $account) {
			if ($account === '') {
				continue;
			}

			$alloc            = $allocations[$account] ?? [];
			$normalizedShares = [];
			$dominantResource = '';
			$dominantShare    = 0.0;

			foreach ($validCapacities as $resource => $capacity) {
				$raw   = $alloc[$resource] ?? 0.0;
				$used  = (is_finite($raw) && $raw > 0.0) ? $raw : 0.0;
				$share = $used / $capacity;

				$normalizedShares[$resource] = round($share, 6);

				if ($share > $dominantShare || ($dominantResource === '' && $share >= $dominantShare)) {
					$dominantShare    = $share;
					$dominantResource = $resource;
				}
			}

			$slurm     = $slurmByAccount[$account] ?? null;
			$factor    = $slurm !== null ? $slurm['fairshareFactor'] : null;
			$effective = $slurm !== null ? $slurm['effectiveUsage'] : null;
			$alignment = $this->classifyAlignment($dominantShare, $factor);

			if ($alignment === 'drf_heavy_fairshare_high') {
				$divergentAccounts[] = $account;
			}

			$roundedDominant  = round($dominantShare, 6);
			$dominantShares[] = $dominantShare;

			$rows[] = [
				'account'             => $account,
				'dominant_resource'   => $dominantResource,
				'dominant_share'      => $roundedDominant,
				'shares'              => $normalizedShares,
				'fairshare_factor'    => $factor !== null ? round($factor, 6) : null,
				'effective_usage'     => $effective !== null ? round($effective, 6) : null,
				'fairshare_alignment' => $alignment,
			];
		}

		// Under DRF progressive filling, the account with the lowest dominant
		// share receives the next resource allocation.
		$priorityRows = $rows;
		usort($priorityRows, static function (array $a, array $b): int {
			$cmp = $a['dominant_share'] <=> $b['dominant_share'];

			return $cmp !== 0 ? $cmp : strcmp($a['account'], $b['account']);
		});

		$priorityOrder = array_map(static fn (array $r): string => $r['account'], $priorityRows);
		$minShare      = $dominantShares !== [] ? round(min($dominantShares), 6) : 0.0;
		$maxShare      = $dominantShares !== [] ? round(max($dominantShares), 6) : 0.0;

		return [
			'capacities'           => $validCapacities,
			'accounts'             => $rows,
			'priority_order'       => $priorityOrder,
			'jains_fairness_index' => self::jainsIndex($dominantShares),
			'max_dominant_share'   => $maxShare,
			'min_dominant_share'   => $minShare,
			'dominant_share_gap'   => round($maxShare - $minShare, 6),
			'divergent_accounts'   => $divergentAccounts,
		];
	}

	/**
	 * Aggregate Slurm account ShareRecord rows by account name.
	 *
	 * @param list<ShareRecord> $shareRecords Slurm fair-share records
	 *
	 * @return array<string, array{fairshareFactor: ?float, effectiveUsage: ?float}>
	 */
	private function indexShareRecords(array $shareRecords): array {
		$grouped = [];

		foreach ($shareRecords as $record) {
			if ($record->account === '') {
				continue;
			}

			if (!isset($grouped[$record->account])) {
				$grouped[$record->account] = ['factors' => [], 'effective' => []];
			}

			if ($record->fairshareFactor !== null && is_finite($record->fairshareFactor)) {
				$grouped[$record->account]['factors'][] = $record->fairshareFactor;
			}

			if ($record->effectiveUsage !== null && is_finite($record->effectiveUsage)) {
				$grouped[$record->account]['effective'][] = $record->effectiveUsage;
			}
		}

		$indexed = [];

		foreach ($grouped as $account => $data) {
			$indexed[$account] = [
				'fairshareFactor' => $data['factors'] !== [] ? array_sum($data['factors']) / count($data['factors']) : null,
				'effectiveUsage'  => $data['effective'] !== [] ? array_sum($data['effective']) : null,
			];
		}

		return $indexed;
	}

	/**
	 * Classify agreement between multi-resource DRF share and Slurm Fair-Share.
	 *
	 * @param float  $dominantShare   account dominant resource share
	 * @param ?float $fairshareFactor Slurm fair-share factor, or null if absent
	 *
	 * @return string alignment classification
	 */
	private function classifyAlignment(float $dominantShare, ?float $fairshareFactor): string {
		if ($fairshareFactor === null) {
			return 'no_fairshare_data';
		}

		if ($dominantShare >= $this->highShareThreshold && $fairshareFactor >= $this->highFairshareThreshold) {
			return 'drf_heavy_fairshare_high';
		}

		return 'aligned';
	}

	/**
	 * Compute Jain's fairness index across a vector of non-negative shares.
	 *
	 * @param list<float> $values dominant resource shares
	 *
	 * @return float fairness index in [0.0, 1.0] (1.0 for empty, singleton, or uniform zero vectors)
	 */
	private static function jainsIndex(array $values): float {
		$n = count($values);

		if ($n <= 1) {
			return 1.0;
		}

		$sum   = 0.0;
		$sumSq = 0.0;

		foreach ($values as $v) {
			$sum   += $v;
			$sumSq += $v * $v;
		}

		if ($sumSq <= 0.0) {
			return 1.0;
		}

		return round(($sum * $sum) / ($n * $sumSq), 6);
	}
}
