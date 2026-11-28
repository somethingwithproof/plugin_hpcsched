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
 * Read-only snapshot queries for the data inputs: job and node counts per
 * partition and the partition names of one cluster. Only the plugin's
 * snapshot tables are read (REQ-OBJ-005).
 */
final class SeriesRepository {
	/**
	 * Construct a new SeriesRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @return array<string, SectionRecord> section value => record
	 */
	public function sections(int $clusterId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_hpcsched_section WHERE cluster_id = ?', [$clusterId], true, $this->connection)) as $row) {
			$record = SectionRecord::fromRow($row);

			if ($record !== null) {
				$out[$record->section->value] = $record;
			}
		}

		return $out;
	}

	/**
	 * @return array<string, array<string, int>> "p:" partition => "kind:name" => jobs, for states and families
	 */
	public function jobs(int $clusterId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared("SELECT partition_name, kind, name, jobs FROM plugin_hpcsched_job_count WHERE cluster_id = ? AND kind IN ('state', 'family')", [$clusterId], true, $this->connection)) as $row) {
			$out['p:' . Row::string($row, 'partition_name')][Row::string($row, 'kind') . ':' . Row::string($row, 'name')] = Row::int($row, 'jobs', 0, 0);
		}

		return $out;
	}

	/**
	 * @return array<string, array<string, int>> "p:" partition => name => value
	 */
	public function nodes(int $clusterId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT partition_name, name, value FROM plugin_hpcsched_node_count WHERE cluster_id = ?', [$clusterId], true, $this->connection)) as $row) {
			$out['p:' . Row::string($row, 'partition_name')][Row::string($row, 'name')] = Row::int($row, 'value', 0, 0);
		}

		return $out;
	}

	/**
	 * Partition names that can be data query indexes, sorted.
	 *
	 * @return list<string>
	 */
	public function partitions(int $clusterId): array {
		$names = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT name FROM plugin_hpcsched_partition WHERE cluster_id = ? ORDER BY name', [$clusterId], true, $this->connection)) as $row) {
			$name = Row::string($row, 'name');

			if (preg_match(Series::PARTITION_PATTERN, $name) === 1) {
				$names[] = $name;
			}
		}

		sort($names, SORT_STRING);

		return array_values(array_unique($names));
	}
}
