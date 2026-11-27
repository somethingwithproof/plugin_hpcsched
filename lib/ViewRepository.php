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
 * shows a job, user, or account (REQ-UI-004, REQ-REASON-006).
 */
final class ViewRepository {
	/**
	 * Construct a new ViewRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Run several reads against one snapshot of the tables. The reader
	 * replaces a cluster's rows in one transaction; separate reads outside
	 * one could pair one snapshot's counts with another's observation time.
	 * Under REPEATABLE READ (InnoDB's default, set here for the transaction
	 * in case the server's differs) every read in the transaction sees the
	 * tables as of its first read. When no transaction can be started the
	 * reads run as they are.
	 *
	 * @template T
	 *
	 * @param \Closure(): T $read
	 *
	 * @return T
	 */
	public function consistent(\Closure $read): mixed {
		db_execute_prepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', [], true, $this->connection);

		$started = db_begin_transaction($this->connection);

		try {
			return $read();
		} finally {
			if ($started) {
				db_commit_transaction($this->connection);
			}
		}
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

	/**
	 * Raw pending reasons of a cluster with their job counts, most jobs
	 * first, at most $limit of them, and the partitions each affects.
	 *
	 * @return array{reasons: list<array{name: string, jobs: int, partitions: list<string>}>, more: bool}
	 */
	public function reasons(int $clusterId, int $limit): array {
		$rows = Row::rows(db_fetch_assoc_prepared("SELECT name, jobs FROM plugin_hpcsched_job_count WHERE cluster_id = ? AND kind = 'reason' AND partition_name = '' ORDER BY jobs DESC, name LIMIT " . ($limit + 1), [$clusterId], true, $this->connection));
		$more = count($rows) > $limit;
		$out  = [];

		foreach (array_slice($rows, 0, $limit) as $row) {
			$out['r:' . Row::string($row, 'name')] = ['name' => Row::string($row, 'name'), 'jobs' => Row::int($row, 'jobs', 0, 0), 'partitions' => []];
		}

		if ($out !== []) {
			$names = array_map(static fn (array $r): string => $r['name'], array_values($out));

			foreach (Row::rows(db_fetch_assoc_prepared("SELECT name, partition_name FROM plugin_hpcsched_job_count WHERE cluster_id = ? AND kind = 'reason' AND partition_name <> '' AND name IN (" . implode(', ', array_fill(0, count($names), '?')) . ') ORDER BY partition_name', array_merge([$clusterId], $names), true, $this->connection)) as $row) {
				$key = 'r:' . Row::string($row, 'name');

				if (isset($out[$key])) {
					$out[$key]['partitions'][] = Row::string($row, 'partition_name');
				}
			}
		}

		return ['reasons' => array_values($out), 'more' => $more];
	}

	/**
	 * Nodes in a state the plugin does not know (normalized Other), grouped
	 * by the raw state Slurm reported (REQ-NODEAGG-002).
	 *
	 * @return list<array{state: string, nodes: int, names: list<string>}>
	 */
	public function otherNodes(int $clusterId, int $limit): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared("SELECT raw_state, COUNT(*) AS nodes FROM plugin_hpcsched_node WHERE cluster_id = ? AND state = 'other' GROUP BY raw_state ORDER BY nodes DESC, raw_state LIMIT " . $limit, [$clusterId], true, $this->connection)) as $row) {
			$out['s:' . Row::string($row, 'raw_state')] = ['state' => Row::string($row, 'raw_state'), 'nodes' => Row::int($row, 'nodes', 0, 0), 'names' => []];
		}

		foreach (Row::rows(db_fetch_assoc_prepared("SELECT name, raw_state FROM plugin_hpcsched_node WHERE cluster_id = ? AND state = 'other' ORDER BY name LIMIT " . $limit, [$clusterId], true, $this->connection)) as $row) {
			$key = 's:' . Row::string($row, 'raw_state');

			if (isset($out[$key])) {
				$out[$key]['names'][] = Row::string($row, 'name');
			}
		}

		return array_values($out);
	}
}
