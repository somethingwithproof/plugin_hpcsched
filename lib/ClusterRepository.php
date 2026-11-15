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
 * Cluster configuration rows. Deleting a cluster deletes its run record and
 * cached snapshot in one transaction, and leaves its Cacti device and graphs
 * alone; nothing is sent to the scheduler.
 */
final class ClusterRepository {
	private const SNAPSHOT_TABLES = ['plugin_hpcsched_run', 'plugin_hpcsched_section', 'plugin_hpcsched_job_count', 'plugin_hpcsched_node', 'plugin_hpcsched_node_count', 'plugin_hpcsched_partition', 'plugin_hpcsched_reservation', 'plugin_hpcsched_share'];

	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * @return list<Cluster>
	 */
	public function all(): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_hpcsched_cluster ORDER BY name, id', [], true, $this->connection)) as $row) {
			$cluster = Cluster::fromRow($row);

			if ($cluster !== null) {
				$out[] = $cluster;
			}
		}

		return $out;
	}

	public function get(int $id): ?Cluster {
		$row = Row::one(db_fetch_row_prepared('SELECT * FROM plugin_hpcsched_cluster WHERE id = ?', [$id], true, $this->connection));

		return $row === null ? null : Cluster::fromRow($row);
	}

	public function findByHost(int $hostId): ?Cluster {
		if ($hostId <= 0) {
			return null;
		}

		$row = Row::one(db_fetch_row_prepared('SELECT * FROM plugin_hpcsched_cluster WHERE host_id = ? ORDER BY id LIMIT 1', [$hostId], true, $this->connection));

		return $row === null ? null : Cluster::fromRow($row);
	}

	/**
	 * Map a cluster to a device, unless another cluster has that device: a
	 * device carries one cluster's graphs. 0 unmaps.
	 */
	public function mapHost(int $id, int $hostId): bool {
		$ok = db_execute_prepared('UPDATE plugin_hpcsched_cluster SET host_id = ?, updated_at = GREATEST(updated_at + 1, ?)
			WHERE id = ? AND (? = 0 OR NOT EXISTS (SELECT 1 FROM (SELECT id FROM plugin_hpcsched_cluster WHERE host_id = ? AND id <> ?) AS other))', [$hostId, $this->clock->now(), $id, $hostId, $hostId, $id], true, $this->connection);

		return $ok !== false && db_affected_rows($this->connection) === 1;
	}

	/**
	 * Forget deleted devices. Returns the number of clusters unmapped.
	 *
	 * @param list<int> $hostIds
	 */
	public function unmapHosts(array $hostIds): int {
		$hostIds = array_values(array_filter($hostIds, static fn (int $id): bool => $id > 0));

		if ($hostIds === []) {
			return 0;
		}

		$ok = db_execute_prepared('UPDATE plugin_hpcsched_cluster SET host_id = 0, updated_at = GREATEST(updated_at + 1, ?) WHERE host_id IN (' . implode(', ', array_fill(0, count($hostIds), '?')) . ')', array_merge([$this->clock->now()], $hostIds), true, $this->connection);

		$count = $ok === false ? 0 : db_affected_rows($this->connection);

		return is_int($count) ? $count : 0;
	}

	public function nameTaken(string $name, int $exceptId): bool {
		return Row::count(db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hpcsched_cluster WHERE name = ? AND id <> ?', [$name, $exceptId], '', true, $this->connection)) > 0;
	}

	/**
	 * Insert a new cluster, or update one only if nobody saved it since the
	 * form was loaded ($input->updatedAt). Returns the id, or 0 when the
	 * update lost that race or the write failed.
	 */
	public function save(ClusterInput $input): int {
		$now    = $this->clock->now();
		$values = [
			$input->name, $input->pollerId, $input->adminState->value, $input->transport->value, $input->interval,
			$input->slurmConf, $input->restUrl, $input->restUser, $input->tokenFile, $input->reservations ? 1 : 0, $input->shares ? 1 : 0,
		];

		if ($input->id === 0) {
			$ok = db_execute_prepared('INSERT INTO plugin_hpcsched_cluster (name, poller_id, admin_state, transport, collect_interval, slurm_conf, rest_url, rest_user, token_file, collect_reservations, collect_shares, created_at, updated_at)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($values, [$now, $now]), true, $this->connection);

			return $ok ? (int) db_fetch_insert_id($this->connection) : 0;
		}

		$ok = db_execute_prepared('UPDATE plugin_hpcsched_cluster SET name = ?, poller_id = ?, admin_state = ?, transport = ?, collect_interval = ?, slurm_conf = ?, rest_url = ?, rest_user = ?, token_file = ?,
			collect_reservations = ?, collect_shares = ?, updated_at = GREATEST(updated_at + 1, ?) WHERE id = ? AND updated_at = ?', array_merge($values, [$now, $input->id, $input->updatedAt]), true, $this->connection);

		return $ok && db_affected_rows($this->connection) === 1 ? $input->id : 0;
	}

	public function setState(int $id, AdminState $state): void {
		db_execute_prepared('UPDATE plugin_hpcsched_cluster SET admin_state = ?, updated_at = GREATEST(updated_at + 1, ?) WHERE id = ?', [$state->value, $this->clock->now(), $id], true, $this->connection);
	}

	public function delete(int $id): bool {
		if (!db_begin_transaction($this->connection)) {
			return false;
		}

		$ok = true;

		foreach (array_merge(self::SNAPSHOT_TABLES, ['plugin_hpcsched_cluster']) as $table) {
			$column = $table === 'plugin_hpcsched_cluster' ? 'id' : 'cluster_id';
			$ok     = $ok && db_execute_prepared("DELETE FROM $table WHERE $column = ?", [$id], true, $this->connection) !== false;
		}

		if ($ok && db_commit_transaction($this->connection)) {
			return true;
		}

		db_rollback_transaction($this->connection);

		return false;
	}
}
