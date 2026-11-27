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
 * Read-only queries over account-level fair-share snapshots
 * (plugin_hpcsched_share).
 */
final class ShareRepository {
	/**
	 * Construct a new ShareRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @return list<ShareRecord>
	 */
	public function forCluster(int $clusterId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT account, partition_name, parent, usage_raw, usage_normalized, effective_usage, fairshare_factor, fairshare_level FROM plugin_hpcsched_share WHERE cluster_id = ? ORDER BY account, partition_name', [$clusterId], true, $this->connection)) as $row) {
			$out[] = new ShareRecord(
				account: Row::string($row, 'account'),
				partition: Row::string($row, 'partition_name'),
				parent: Row::string($row, 'parent'),
				usage: self::nullableFloat($row, 'usage_raw'),
				usageNormalized: self::nullableFloat($row, 'usage_normalized'),
				effectiveUsage: self::nullableFloat($row, 'effective_usage'),
				fairshareFactor: self::nullableFloat($row, 'fairshare_factor'),
				fairshareLevel: self::nullableFloat($row, 'fairshare_level'),
			);
		}

		return $out;
	}

	/**
	 * Summary.
	 *
	 * @param int $clusterId
	 * @param int $topLimit
	 * @return ShareSummary
	 */
	public function summary(int $clusterId, int $topLimit = 10): ShareSummary {
		return ShareSummary::fromRecords($clusterId, $this->forCluster($clusterId), $topLimit);
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	private static function nullableFloat(array $row, string $key): ?float {
		$val = $row[$key] ?? null;

		return is_numeric($val) ? (float) $val : null;
	}
}
