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
 * Aggregated account-level fair-share and accounting summary for one cluster
 * or partition (ADR 0010: strictly account associations, never user rows).
 */
final readonly class ShareSummary {
	/**
	 * @param list<array{account: string, partition: string, parent: string, effectiveUsage: ?float, fairshareFactor: ?float, fairshareLevel: ?float}> $topAccounts
	 */
	public function __construct(
		public int $clusterId,
		public int $accountCount,
		public int $partitionCount,
		public float $totalRawUsage,
		public float $totalEffectiveUsage,
		public ?float $minFairshareFactor,
		public ?float $maxFairshareFactor,
		public ?float $avgFairshareFactor,
		public array $topAccounts,
	) {
	}

	/**
	 * Build a summary from stored ShareRecord rows.
	 *
	 * @param list<ShareRecord> $records
	 */
	public static function fromRecords(int $clusterId, array $records, int $topLimit = 10): self {
		$accounts   = [];
		$partitions = [];
		$totalRaw   = 0.0;
		$totalEff   = 0.0;
		$factors    = [];

		foreach ($records as $record) {
			if ($record->account !== '') {
				$accounts[$record->account] = true;
			}

			if ($record->partition !== '') {
				$partitions[$record->partition] = true;
			}

			if ($record->usage !== null) {
				$totalRaw += $record->usage;
			}

			if ($record->effectiveUsage !== null) {
				$totalEff += $record->effectiveUsage;
			}

			if ($record->fairshareFactor !== null) {
				$factors[] = $record->fairshareFactor;
			}
		}

		$sorted = $records;
		usort($sorted, static function (ShareRecord $a, ShareRecord $b): int {
			$cmp = ($b->effectiveUsage ?? -1.0) <=> ($a->effectiveUsage ?? -1.0);

			return $cmp !== 0 ? $cmp : strcmp($a->account, $b->account);
		});

		$top = [];

		foreach (array_slice($sorted, 0, max(1, $topLimit)) as $r) {
			$top[] = [
				'account'         => $r->account,
				'partition'       => $r->partition,
				'parent'          => $r->parent,
				'effectiveUsage'  => $r->effectiveUsage,
				'fairshareFactor' => $r->fairshareFactor,
				'fairshareLevel'  => $r->fairshareLevel,
			];
		}

		$min = $factors !== [] ? min($factors) : null;
		$max = $factors !== [] ? max($factors) : null;
		$avg = $factors !== [] ? round(array_sum($factors) / count($factors), 6) : null;

		return new self(
			clusterId: $clusterId,
			accountCount: count($accounts),
			partitionCount: count($partitions),
			totalRawUsage: round($totalRaw, 4),
			totalEffectiveUsage: round($totalEff, 6),
			minFairshareFactor: $min !== null ? round($min, 6) : null,
			maxFairshareFactor: $max !== null ? round($max, 6) : null,
			avgFairshareFactor: $avg,
			topAccounts: $top,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'clusterId'           => $this->clusterId,
			'accountCount'        => $this->accountCount,
			'partitionCount'      => $this->partitionCount,
			'totalRawUsage'       => $this->totalRawUsage,
			'totalEffectiveUsage' => $this->totalEffectiveUsage,
			'minFairshareFactor'  => $this->minFairshareFactor,
			'maxFairshareFactor'  => $this->maxFairshareFactor,
			'avgFairshareFactor'  => $this->avgFairshareFactor,
			'topAccounts'         => $this->topAccounts,
		];
	}
}
