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
 * Read-only queries the pages use. Counts only: no page in this release
 * shows a job, user, or account (REQ-UI-004).
 */
final class ViewRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @return array<int, array<string, SectionRecord>> cluster id => section value => record
	 */
	public function sections(): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_hpcsched_section ORDER BY cluster_id, section', [], true, $this->connection)) as $row) {
			$record = SectionRecord::fromRow($row);

			if ($record !== null) {
				$out[$record->clusterId][$record->section->value] = $record;
			}
		}

		return $out;
	}

	/**
	 * Cluster-wide job counts by state.
	 *
	 * @return array<int, array<string, int>> cluster id => state => jobs
	 */
	public function jobStates(): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared("SELECT cluster_id, name, jobs FROM plugin_hpcsched_job_count WHERE partition_name = '' AND kind = 'state'", [], true, $this->connection)) as $row) {
			$out[Row::int($row, 'cluster_id')][Row::string($row, 'name')] = Row::int($row, 'jobs', 0, 0);
		}

		return $out;
	}

	/**
	 * Node counts by normalized state, and drained nodes counted from the
	 * drain flag so a down and drained node counts in both.
	 *
	 * @return array<int, array<string, int>> cluster id => state (and 'drain_flag') => nodes
	 */
	public function nodeStates(): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared("SELECT cluster_id, state, COUNT(*) AS nodes, SUM(CONCAT('+', raw_state, '+') LIKE '%+DRAIN+%') AS drained FROM plugin_hpcsched_node GROUP BY cluster_id, state", [], true, $this->connection)) as $row) {
			$id = Row::int($row, 'cluster_id');

			$out[$id][Row::string($row, 'state')] = Row::int($row, 'nodes', 0, 0);
			$out[$id]['drain_flag']               = ($out[$id]['drain_flag'] ?? 0) + Row::int($row, 'drained', 0, 0);
		}

		return $out;
	}
}
