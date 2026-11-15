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
 * The per-cluster reader lease and last run record. A lease is taken only
 * when no unexpired one is held by another run, in one statement, so two
 * readers of one cluster cannot both write. register_process_start() already
 * keeps a second reader process from starting; the lease also fences a run
 * that outlived its registration.
 */
final class RunRepository {
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	public function acquire(int $clusterId, string $holder, int $seconds): bool {
		$now = $this->clock->now();

		db_execute_prepared('INSERT IGNORE INTO plugin_hpcsched_run (cluster_id, holder, lease_expires) VALUES (?, ?, 0)', [$clusterId, ''], true, $this->connection);
		db_execute_prepared('UPDATE plugin_hpcsched_run SET holder = ?, lease_expires = ?, started_at = ? WHERE cluster_id = ? AND (holder = ? OR lease_expires < ?)', [$holder, $now + $seconds, $now, $clusterId, $holder, $now], true, $this->connection);

		return $this->holds($clusterId, $holder);
	}

	public function holds(int $clusterId, string $holder): bool {
		return Row::count(db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hpcsched_run WHERE cluster_id = ? AND holder = ? AND lease_expires >= ?', [$clusterId, $holder, $this->clock->now()], '', true, $this->connection)) === 1;
	}

	public function release(int $clusterId, string $holder): void {
		db_execute_prepared('UPDATE plugin_hpcsched_run SET lease_expires = 0 WHERE cluster_id = ? AND holder = ?', [$clusterId, $holder], true, $this->connection);
	}

	/**
	 * Record the run while the lease is held. A zero last success or failure
	 * keeps the previous time.
	 */
	public function finish(ClusterRun $run, string $holder): void {
		db_execute_prepared('UPDATE plugin_hpcsched_run SET finished_at = ?, duration = ?, outcome = ?, error_class = ?, slurm_release = IF(? <> \'\', ?, slurm_release), transport = ?, data_parser = ?, calls = ?,
			last_success = IF(? > 0, ?, last_success), last_failure = IF(? > 0, ?, last_failure), backoff_until = ?, backoff_step = ?, overran = ?, memory_limited = ?, token_state = ?, token_expires = ?
			WHERE cluster_id = ? AND holder = ? AND lease_expires >= ?', [
			$run->finishedAt, $run->duration, $run->outcome->value, $run->error->value ?? '', $run->release, $run->release, $run->transport, $run->dataParser, $run->calls,
			$run->lastSuccess, $run->lastSuccess, $run->lastFailure, $run->lastFailure, $run->backoffUntil, $run->backoffStep, $run->overran ? 1 : 0, $run->memoryLimited ? 1 : 0,
			$run->tokenState->value, $run->tokenExpires,
			$run->clusterId, $holder, $this->clock->now(),
		], true, $this->connection);
	}

	public function get(int $clusterId): ?ClusterRun {
		$row = Row::one(db_fetch_row_prepared('SELECT * FROM plugin_hpcsched_run WHERE cluster_id = ?', [$clusterId], true, $this->connection));

		return $row === null ? null : ClusterRun::fromRow($row);
	}

	/**
	 * @return array<int, ClusterRun> cluster id => last run
	 */
	public function all(): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_hpcsched_run ORDER BY cluster_id', [], true, $this->connection)) as $row) {
			$run = ClusterRun::fromRow($row);

			if ($run !== null) {
				$out[$run->clusterId] = $run;
			}
		}

		return $out;
	}
}
