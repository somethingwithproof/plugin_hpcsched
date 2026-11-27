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
 * Persistence and read queries for Slurm node-to-Cacti-host mappings
 * (plugin_hpcsched_node_map) and snapshot node/reservation reads.
 */
final class NodeMapRepository {
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * Load Cacti hosts eligible for compute-node matching.
	 *
	 * @return list<array{id: int, hostname: string, description: string}>
	 */
	public function cactiHosts(): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared("SELECT id, hostname, description FROM host WHERE deleted = '' ORDER BY id", [], true, $this->connection)) as $row) {
			$id = Row::int($row, 'id', 0, 1);

			if ($id > 0) {
				$out[] = [
					'id'          => $id,
					'hostname'    => Row::string($row, 'hostname'),
					'description' => Row::string($row, 'description'),
				];
			}
		}

		return $out;
	}

	/**
	 * Load stored snapshot nodes for a cluster.
	 *
	 * @return list<array{name: string, hostname: string, address: string, state: string, raw_state: string, reason: string}>
	 */
	public function clusterNodes(int $clusterId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT name, hostname, address, state, raw_state, reason FROM plugin_hpcsched_node WHERE cluster_id = ? ORDER BY name', [$clusterId], true, $this->connection)) as $row) {
			$out[] = [
				'name'      => Row::string($row, 'name'),
				'hostname'  => Row::string($row, 'hostname'),
				'address'   => Row::string($row, 'address'),
				'state'     => Row::string($row, 'state'),
				'raw_state' => Row::string($row, 'raw_state'),
				'reason'    => Row::string($row, 'reason'),
			];
		}

		return $out;
	}

	/**
	 * Load active or upcoming reservations for a cluster.
	 *
	 * @return list<array{name: string, flags: string, node_list: string, start_time: int, end_time: int}>
	 */
	public function clusterReservations(int $clusterId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT name, flags, node_list, start_time, end_time FROM plugin_hpcsched_reservation WHERE cluster_id = ? ORDER BY name', [$clusterId], true, $this->connection)) as $row) {
			$out[] = [
				'name'       => Row::string($row, 'name'),
				'flags'      => Row::string($row, 'flags'),
				'node_list'  => Row::string($row, 'node_list'),
				'start_time' => Row::int($row, 'start_time', 0, 0),
				'end_time'   => Row::int($row, 'end_time', 0, 0),
			];
		}

		return $out;
	}

	/**
	 * Load saved node-to-host mappings for a cluster.
	 *
	 * @return array<string, array{host_id: int, match_state: string, match_tier: int, source: string}>
	 */
	public function mappings(int $clusterId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT node_name, host_id, match_state, match_tier, source FROM plugin_hpcsched_node_map WHERE cluster_id = ? ORDER BY node_name', [$clusterId], true, $this->connection)) as $row) {
			$name = Row::string($row, 'node_name');

			if ($name !== '') {
				$out[$name] = [
					'host_id'     => Row::int($row, 'host_id', 0, 0),
					'match_state' => Row::string($row, 'match_state'),
					'match_tier'  => Row::int($row, 'match_tier', 0, 0),
					'source'      => Row::string($row, 'source'),
				];
			}
		}

		return $out;
	}

	public function saveMapping(int $clusterId, NodeMatch $match, string $source = 'auto'): bool {
		return db_execute_prepared(
			'INSERT INTO plugin_hpcsched_node_map (cluster_id, node_name, host_id, match_state, match_tier, source, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE host_id = VALUES(host_id), match_state = VALUES(match_state), match_tier = VALUES(match_tier), source = VALUES(source), updated_at = VALUES(updated_at)',
			[$clusterId, $match->nodeName, $match->hostId ?? 0, $match->state->value, $match->tier ?? 0, $source, $this->clock->now()],
			true,
			$this->connection,
		) !== false;
	}

	/**
	 * Clear mappings pointing to deleted Cacti hosts.
	 *
	 * @param list<int> $hostIds
	 */
	public function unmapHosts(array $hostIds): void {
		$hostIds = array_values(array_filter($hostIds, static fn (int $id): bool => $id > 0));

		if ($hostIds === []) {
			return;
		}

		$placeholders = implode(', ', array_fill(0, count($hostIds), '?'));
		db_execute_prepared("DELETE FROM plugin_hpcsched_node_map WHERE host_id IN ($placeholders)", $hostIds, true, $this->connection);
	}
}
