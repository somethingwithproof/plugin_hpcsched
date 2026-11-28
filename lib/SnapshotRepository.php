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
 * Writes one snapshot in one transaction that commits only while the run's
 * lease is held (REQ-READ-005). A completed section replaces that section's
 * rows; a failed or not attempted section keeps its previous rows and their
 * observation times, so its age keeps growing from the last real read and
 * freshness turns it stale; only its section state records the failure. A
 * run killed part way, or one whose transaction InnoDB ended, writes
 * nothing, and the previous snapshot stands (REQ-READ-003).
 *
 * Every value row carries the time it was observed, the time it was
 * written, and the interval it was collected on (REQ-STATE-004).
 */
final class SnapshotRepository {
	/** Batch constant for SnapshotRepository. */
	private const BATCH = 500;

	/**
	 * Construct a new SnapshotRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Apply.
	 *
	 * @param int $clusterId
	 * @param string $holder
	 * @param int $now
	 * @param int $interval
	 * @param Snapshot $snapshot
	 * @return bool
	 */
	public function apply(int $clusterId, string $holder, int $now, int $interval, Snapshot $snapshot): bool {
		if (!db_begin_transaction($this->connection)) {
			return false;
		}

		$ok = Row::count(db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hpcsched_run WHERE cluster_id = ? AND holder = ? AND lease_expires >= ? FOR UPDATE', [$clusterId, $holder, $now], '', true, $this->connection)) === 1;

		$stamps = [$snapshot->startedAt, $now, $interval];

		foreach ($snapshot->outcomes as $name => $outcome) {
			if (!$ok) {
				break;
			}

			$section = Section::from($name);

			if ($outcome->state === SectionState::Completed && !$outcome->unchanged) {
				$ok = $this->replace($clusterId, $section, $snapshot, $stamps) && $this->inTransaction();

				// The node counts are the nodes section's, so they are
				// replaced with it and never outlive it.
				if ($ok && $section === Section::Nodes) {
					$ok = $this->write($clusterId, 'plugin_hpcsched_node_count', ['partition_name', 'name', 'value'], array_map(
						static fn (array $r): array => [$r['partition'], $r['name'], $r['value']],
						NodeTally::rows($snapshot->nodes ?? [])
					), $stamps);
				}
			}

			$ok = $ok && $this->section($clusterId, $section, $outcome, $now, $stamps) && $this->inTransaction();
		}

		if ($ok && db_commit_transaction($this->connection)) {
			return true;
		}

		if ($this->inTransaction()) {
			db_rollback_transaction($this->connection);
		}

		return false;
	}

	/**
	 * The cluster's stored section records, which the reader passes to the
	 * backend for conditional reads.
	 *
	 * @return array<string, SectionRecord> section value => record
	 */
	public function sections(int $clusterId): array {
		return (new SeriesRepository($this->connection))->sections($clusterId);
	}

	/**
	 * @param array{int, int, int} $stamps observed, ingested, interval
	 */
	private function section(int $clusterId, Section $section, SectionOutcome $outcome, int $now, array $stamps): bool {
		// Nothing changed since the last read: the rows stay, and so do their
		// ingestion time and count, but the data is current as of this run.
		if ($outcome->unchanged) {
			return db_execute_prepared('UPDATE plugin_hpcsched_section SET state = ?, error_class = \'\', attempted_at = ?, observed_at = ?, collect_interval = ? WHERE cluster_id = ? AND section = ?',
				[$outcome->state->value, $now, $stamps[0], $stamps[2], $clusterId, $section->value], true, $this->connection) !== false;
		}

		if ($outcome->state === SectionState::Completed) {
			return db_execute_prepared('INSERT INTO plugin_hpcsched_section (cluster_id, section, state, error_class, attempted_at, records, observed_at, ingested_at, collect_interval)
				VALUES (?, ?, ?, \'\', ?, ?, ?, ?, ?)
				ON DUPLICATE KEY UPDATE state = VALUES(state), error_class = \'\', attempted_at = VALUES(attempted_at), records = VALUES(records),
					observed_at = VALUES(observed_at), ingested_at = VALUES(ingested_at), collect_interval = VALUES(collect_interval)', array_merge([$clusterId, $section->value, $outcome->state->value, $now, $outcome->records], $stamps), true, $this->connection) !== false;
		}

		// The rows and their times stay as the last completed read left them.
		return db_execute_prepared('INSERT INTO plugin_hpcsched_section (cluster_id, section, state, error_class, attempted_at) VALUES (?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE state = VALUES(state), error_class = VALUES(error_class), attempted_at = VALUES(attempted_at)', [$clusterId, $section->value, $outcome->state->value, $outcome->error->value ?? '', $now], true, $this->connection) !== false;
	}

	/**
	 * @param array{int, int, int} $stamps
	 */
	private function replace(int $clusterId, Section $section, Snapshot $snapshot, array $stamps): bool {
		[$table, $columns, $rows] = match ($section) {
			Section::Jobs => ['plugin_hpcsched_job_count', ['partition_name', 'kind', 'name', 'jobs'], array_map(
				static fn (array $r): array => [$r['partition'], $r['kind'], $r['name'], $r['jobs']],
				$snapshot->jobs?->rows() ?? []
			)],
			Section::Nodes => ['plugin_hpcsched_node', ['name', 'hostname', 'address', 'partitions', 'state', 'raw_state', 'reason', 'reason_changed_at', 'cpus', 'alloc_cpus'], array_map(
				static fn (NodeRecord $n): array => [$n->name, $n->hostname, $n->address, mb_substr(implode(',', $n->partitions), 0, 1024, 'UTF-8'), $n->state->value, mb_substr(implode('+', $n->flags), 0, 255, 'UTF-8'), $n->reason, $n->reasonChangedAt, $n->cpus, $n->allocCpus],
				$snapshot->nodes ?? []
			)],
			Section::Partitions => ['plugin_hpcsched_partition', ['name', 'state', 'total_nodes', 'total_cpus'], array_map(
				static fn (PartitionRecord $p): array => [$p->name, $p->state, $p->totalNodes, $p->totalCpus],
				$snapshot->partitions ?? []
			)],
			Section::Reservations => ['plugin_hpcsched_reservation', ['name', 'flags', 'node_list', 'start_time', 'end_time'], array_map(
				static fn (ReservationRecord $r): array => [$r->name, mb_substr(implode(',', $r->flags), 0, 512, 'UTF-8'), $r->nodeList, $r->start, $r->end],
				$snapshot->reservations ?? []
			)],
			Section::Shares => ['plugin_hpcsched_share', ['account', 'partition_name', 'parent', 'usage_raw', 'usage_normalized', 'effective_usage', 'fairshare_factor', 'fairshare_level'], array_map(
				static fn (ShareRecord $s): array => [$s->account, $s->partition, $s->parent, $s->usage, $s->usageNormalized, $s->effectiveUsage, $s->fairshareFactor, $s->fairshareLevel],
				$snapshot->shares ?? []
			)],
		};

		return $this->write($clusterId, $table, $columns, $rows, $stamps);
	}

	/**
	 * Replace every row of a cluster in one table.
	 *
	 * @param list<string>                      $columns
	 * @param list<list<int|string|float|null>> $rows
	 * @param array{int, int, int}              $stamps
	 */
	private function write(int $clusterId, string $table, array $columns, array $rows, array $stamps): bool {
		if (db_execute_prepared("DELETE FROM $table WHERE cluster_id = ?", [$clusterId], true, $this->connection) === false) {
			return false;
		}

		$columns = array_merge(['cluster_id'], $columns, ['observed_at', 'ingested_at', 'collect_interval']);
		$row     = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

		// Duplicate keys in scheduler output (a node listed twice) keep the
		// last record instead of failing the section.
		$sql = "INSERT INTO $table (" . implode(', ', $columns) . ') VALUES ';
		$set = ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $c): string => "$c = VALUES($c)", array_slice($columns, 1)));

		foreach (array_chunk($rows, self::BATCH) as $chunk) {
			$params = [];

			foreach ($chunk as $values) {
				array_push($params, $clusterId, ...$values, ...$stamps);
			}

			if (db_execute_prepared($sql . implode(', ', array_fill(0, count($chunk), $row)) . $set, $params, true, $this->connection) === false || !$this->inTransaction()) {
				return false;
			}
		}

		return true;
	}

	/**
	 * InnoDB ends a transaction on a deadlock, and Cacti then retries the
	 * failed statement outside it, so the transaction is checked after every
	 * statement and the run's writes are abandoned when it has ended.
	 */
	private function inTransaction(): bool {
		return (int) db_fetch_cell_prepared('SELECT @@in_transaction', [], '', true, $this->connection) === 1;
	}
}
