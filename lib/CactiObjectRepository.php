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
 * Read-only lookups in Cacti's own tables: templates by hash, devices,
 * graphs, and data query indexes. Every change to a Cacti object goes
 * through Cacti's API functions in DeviceProvisioner; nothing here writes.
 */
final class CactiObjectRepository {
	/** Tables whose rows the plugin looks up by hash. */
	private const HASHED = ['host_template', 'data_input', 'data_template', 'graph_templates', 'snmp_query'];

	public function __construct(private readonly \PDO|false $connection) {
	}

	public function idByHash(string $table, string $hash): int {
		if (!in_array($table, self::HASHED, true) || preg_match('/^[0-9a-f]{32}$/', $hash) !== 1) {
			return 0;
		}

		return Row::count(db_fetch_cell_prepared("SELECT id FROM `$table` WHERE hash = ? ORDER BY id LIMIT 1", [$hash], '', true, $this->connection));
	}

	public function graphTemplateId(string $name): int {
		return $this->idByHash('graph_templates', TemplateInstaller::hash('graph_template', $name));
	}

	public function queryId(): int {
		return $this->idByHash('snmp_query', TemplateInstaller::hash('data_query', TemplateInstaller::QUERY));
	}

	public function deviceTemplateId(): int {
		return $this->idByHash('host_template', TemplateInstaller::hash('host_template', TemplateInstaller::DEVICE_TEMPLATE));
	}

	/**
	 * The query graph (snmp_query_graph) that creates a partition graph.
	 */
	public function queryGraphId(int $queryId, int $graphTemplateId): int {
		return Row::count(db_fetch_cell_prepared('SELECT id FROM snmp_query_graph WHERE snmp_query_id = ? AND graph_template_id = ? ORDER BY id LIMIT 1', [$queryId, $graphTemplateId], '', true, $this->connection));
	}

	public function defaultProfileId(): int {
		$id = Row::count(db_fetch_cell_prepared("SELECT id FROM data_source_profiles WHERE `default` = 'on' ORDER BY id LIMIT 1", [], '', true, $this->connection));

		return $id > 0 ? $id : 1;
	}

	/**
	 * @return array{id: int, description: string, poller_id: int}|null
	 */
	public function device(int $hostId): ?array {
		$row = $hostId > 0 ? Row::one(db_fetch_row_prepared("SELECT id, description, poller_id FROM host WHERE id = ? AND deleted = ''", [$hostId], true, $this->connection)) : null;

		if ($row === null || Row::int($row, 'id', 0, 1) === 0) {
			return null;
		}

		return ['id' => Row::int($row, 'id', 0, 1), 'description' => Row::string($row, 'description'), 'poller_id' => Row::int($row, 'poller_id', 1, 1)];
	}

	/**
	 * Whether Cacti's run_data_query() skips this device: it returns true
	 * without running the query for a disabled device or one that is down
	 * (HOST_DOWN is 1).
	 */
	public function queriesSkipped(int $hostId): bool {
		$row = Row::one(db_fetch_row_prepared('SELECT status, disabled FROM host WHERE id = ?', [$hostId], true, $this->connection));

		return $row !== null && (Row::int($row, 'status', 0, 1) === 1 || Row::string($row, 'disabled') === 'on');
	}

	/**
	 * Devices a cluster may be mapped to: every device not already mapped.
	 *
	 * @param list<int> $mapped host ids already in use
	 *
	 * @return array<int, string> host id => description
	 */
	public function mappableDevices(array $mapped): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared("SELECT id, description FROM host WHERE deleted = '' ORDER BY description, id", [], true, $this->connection)) as $row) {
			$id = Row::int($row, 'id', 0, 1);

			if ($id > 0 && !in_array($id, $mapped, true)) {
				$out[$id] = Row::string($row, 'description');
			}
		}

		return $out;
	}

	/**
	 * Graphs of a device from one template; for a partition graph, of one
	 * index.
	 */
	public function graphCount(int $hostId, int $graphTemplateId, string $index = ''): int {
		return Row::count(db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id = ? AND graph_template_id = ? AND snmp_index = ?', [$hostId, $graphTemplateId, $index], '', true, $this->connection));
	}

	public function hasQuery(int $hostId, int $queryId): bool {
		return Row::count(db_fetch_cell_prepared('SELECT COUNT(*) FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ?', [$hostId, $queryId], '', true, $this->connection)) > 0;
	}

	/**
	 * The indexes Cacti holds for a device's data query, as its last
	 * reindex left them.
	 *
	 * @return list<string>
	 */
	public function indexes(int $hostId, int $queryId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared("SELECT DISTINCT snmp_index FROM host_snmp_cache WHERE host_id = ? AND snmp_query_id = ? AND snmp_index != '' ORDER BY snmp_index", [$hostId, $queryId], true, $this->connection)) as $row) {
			$out[] = Row::string($row, 'snmp_index');
		}

		sort($out, SORT_STRING);

		return $out;
	}
}
