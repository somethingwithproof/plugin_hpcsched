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
 * Database boundary for Cacti Maint schedules and hpcsched's ownership link
 * table (plugin_hpcsched_maint_link). Never modifies or deletes a Maint
 * schedule that is not owned by a plugin_hpcsched_maint_link row.
 */
final class MaintRepository {
	/**
	 * Construct a new MaintRepository instance.
	 *
	 * @param \PDO|false $connection
	 * @param Clock $clock
	 */
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * Whether the Cacti Maint plugin tables exist in this database.
	 */
	public function maintTablesAvailable(): bool {
		$count = Row::count(db_fetch_cell_prepared(
			"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('plugin_maint_schedules', 'plugin_maint_hosts')",
			[],
			'',
			true,
			$this->connection,
		));

		return $count === 2;
	}

	/**
	 * Load all hpcsched-owned Maint schedule links for a cluster.
	 *
	 * @return array<string, array{node_name: string, host_id: int, schedule_id: int, reason_kind: string, source_ref: string, start_time: int, end_time: int}>
	 */
	public function linksForCluster(int $clusterId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT node_name, host_id, schedule_id, reason_kind, source_ref, start_time, end_time FROM plugin_hpcsched_maint_link WHERE cluster_id = ? ORDER BY node_name', [$clusterId], true, $this->connection)) as $row) {
			$name = Row::string($row, 'node_name');

			if ($name !== '') {
				$out[$name] = [
					'node_name'   => $name,
					'host_id'     => Row::int($row, 'host_id', 0, 1),
					'schedule_id' => Row::int($row, 'schedule_id', 0, 1),
					'reason_kind' => Row::string($row, 'reason_kind'),
					'source_ref'  => Row::string($row, 'source_ref'),
					'start_time'  => Row::int($row, 'start_time', 0, 0),
					'end_time'    => Row::int($row, 'end_time', 0, 0),
				];
			}
		}

		return $out;
	}

	/**
	 * Total links.
	 *
	 * @return int
	 */
	public function totalLinks(): int {
		return Row::count(db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hpcsched_maint_link', [], '', true, $this->connection));
	}

	/**
	 * Create a one-time Maint schedule, attach the host, and record ownership
	 * in plugin_hpcsched_maint_link.
	 */
	public function createSchedule(MaintAction $action, string $scheduleName): int {
		$ok = db_execute_prepared(
			"INSERT INTO plugin_maint_schedules (enabled, name, mtype, stime, etime, minterval) VALUES ('on', ?, 1, ?, ?, 0)",
			[substr($scheduleName, 0, 128), $action->startTime, $action->endTime],
			true,
			$this->connection,
		);

		if ($ok === false) {
			return 0;
		}

		$scheduleId = (int) db_fetch_insert_id($this->connection);

		if ($scheduleId < 1) {
			return 0;
		}

		$linked = db_execute_prepared(
			'INSERT INTO plugin_maint_hosts (type, host, schedule) VALUES (1, ?, ?)',
			[$action->hostId, $scheduleId],
			true,
			$this->connection,
		);

		if ($linked === false) {
			return 0;
		}

		db_execute_prepared(
			'INSERT INTO plugin_hpcsched_maint_link (cluster_id, node_name, host_id, schedule_id, reason_kind, source_ref, start_time, end_time, synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE host_id = VALUES(host_id), schedule_id = VALUES(schedule_id), reason_kind = VALUES(reason_kind), source_ref = VALUES(source_ref), start_time = VALUES(start_time), end_time = VALUES(end_time), synced_at = VALUES(synced_at)',
			[$action->clusterId, $action->nodeName, $action->hostId, $scheduleId, $action->reasonKind, $action->sourceRef, $action->startTime, $action->endTime, $this->clock->now()],
			true,
			$this->connection,
		);

		return $scheduleId;
	}

	/**
	 * Update schedule.
	 *
	 * @param MaintAction $action
	 * @return bool
	 */
	public function updateSchedule(MaintAction $action): bool {
		if ($action->scheduleId < 1) {
			return false;
		}

		$ok = db_execute_prepared(
			'UPDATE plugin_maint_schedules SET stime = ?, etime = ? WHERE id = ?',
			[$action->startTime, $action->endTime, $action->scheduleId],
			true,
			$this->connection,
		);

		if ($ok === false) {
			return false;
		}

		return db_execute_prepared(
			'UPDATE plugin_hpcsched_maint_link SET reason_kind = ?, source_ref = ?, start_time = ?, end_time = ?, synced_at = ? WHERE cluster_id = ? AND node_name = ?',
			[$action->reasonKind, $action->sourceRef, $action->startTime, $action->endTime, $this->clock->now(), $action->clusterId, $action->nodeName],
			true,
			$this->connection,
		) !== false;
	}

	/**
	 * Safely remove ONLY a plugin-owned Maint schedule and its link row.
	 */
	public function removeSchedule(MaintAction $action): bool {
		if ($action->scheduleId > 0) {
			db_execute_prepared('DELETE FROM plugin_maint_hosts WHERE type = 1 AND host = ? AND schedule = ?', [$action->hostId, $action->scheduleId], true, $this->connection);
			db_execute_prepared('DELETE FROM plugin_maint_schedules WHERE id = ?', [$action->scheduleId], true, $this->connection);
		}

		return db_execute_prepared(
			'DELETE FROM plugin_hpcsched_maint_link WHERE cluster_id = ? AND node_name = ?',
			[$action->clusterId, $action->nodeName],
			true,
			$this->connection,
		) !== false;
	}
}
