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
 * Plugin tables, their migrations, and the plugin's registration records.
 *
 * Tables are created through api_plugin_db_table_create() so Cacti tracks
 * them in plugin_db_changes (REQ-LIFE-001). The schema version is kept in its
 * own setting because api_plugin_install() writes plugin_config.version
 * before the plugin's install function runs, so that column cannot tell us
 * whether the tables exist.
 *
 * Migrations run once each, in order, and the stored schema version moves
 * past a migration only after it succeeds (REQ-LIFE-002). Every migration is
 * safe to repeat, so one interrupted part way is completed by the next run.
 * No migration touches a Cacti device, data source, graph, or Maint
 * schedule, or contacts the scheduler.
 */
final class Schema {
	public const VERSION = 2;

	public const HOOKS = [
		'config_arrays'        => 'hpcsched_config_arrays',
		'config_settings'      => 'hpcsched_config_settings',
		'draw_navigation_text' => 'hpcsched_draw_navigation_text',
		'poller_bottom'        => 'hpcsched_poller_bottom',
	];

	private const PLUGIN  = 'hpcsched';
	private const SETTING = 'hpcsched_schema_version';

	public function __construct(private readonly \PDO|false $connection) {
	}

	public function storedVersion(): int {
		$value = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [self::SETTING], '', true, $this->connection);

		return is_numeric($value) ? (int) $value : 0;
	}

	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	/**
	 * Apply every pending migration, or stop at the first that fails.
	 *
	 * @param int $through the last migration to run; tests pass a lower
	 *                     number to leave an upgrade interrupted
	 */
	public function migrate(int $through = self::VERSION): bool {
		$from = $this->storedVersion();

		if ($from > self::VERSION) {
			Log::error(sprintf('Schema version %d is newer than this code (%d); refusing to downgrade', $from, self::VERSION));

			return false;
		}

		foreach ($this->migrations() as $version => $migration) {
			if ($version <= $from || $version > $through) {
				continue;
			}

			if (!$migration()) {
				Log::error(sprintf('Schema migration %d failed; schema left at version %d', $version, $this->storedVersion()));

				return false;
			}

			db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', [self::SETTING, (string) $version], true, $this->connection);

			Log::info(sprintf('Schema migrated to version %d', $version));
		}

		return true;
	}

	public function recordedPluginVersion(): string {
		return (string) db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', [self::PLUGIN], '', true, $this->connection);
	}

	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared('UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?', [$version, $author, $homepage, self::PLUGIN], true, $this->connection);
	}

	/**
	 * Whether core has the plugin enabled (plugin_config status 1).
	 */
	public function pluginEnabled(): bool {
		return (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory = ?', [self::PLUGIN], '', true, $this->connection) === 1;
	}

	public function hooksRegistered(): bool {
		$placeholders = implode(', ', array_fill(0, count(self::HOOKS), '?'));

		$hooks  = (int) db_fetch_cell_prepared("SELECT COUNT(DISTINCT hook) FROM plugin_hooks WHERE name = ? AND hook IN ($placeholders)", array_merge([self::PLUGIN], array_keys(self::HOOKS)), '', true, $this->connection);
		$realms = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?', [self::PLUGIN], '', true, $this->connection);

		return $hooks === count(self::HOOKS) && $realms === count(Pages::realms());
	}

	/**
	 * Stop Cacti from dropping the plugin tables on uninstall, and keep the
	 * schema version so a reinstall reuses them. True only when no table is
	 * still tracked afterwards, because core drops every tracked table.
	 */
	public function untrackTables(): bool {
		$ok = db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", [self::PLUGIN], true, $this->connection);

		return $ok !== false && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", [self::PLUGIN], '', true, $this->connection) === 0;
	}

	public function forgetVersion(): void {
		db_execute_prepared('DELETE FROM settings WHERE name = ?', [self::SETTING], true, $this->connection);
	}

	/**
	 * Track every existing plugin table for uninstall again.
	 *
	 * Core records a table only when it creates one, so tables kept through an
	 * uninstall would otherwise never be dropped by a later one. The row is
	 * the one api_plugin_db_table_create() writes; the table has no unique
	 * key, so an existing row is checked for rather than replaced.
	 */
	public function trackTables(): void {
		foreach (array_keys(self::tables()) as $table) {
			if ($this->tableExists($table)) {
				db_execute_prepared("INSERT INTO plugin_db_changes (plugin, `table`, `column`, `method`) SELECT ?, ?, '', 'create' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM plugin_db_changes WHERE plugin = ? AND `table` = ? AND `method` = 'create')", [self::PLUGIN, $table, self::PLUGIN, $table], true, $this->connection);
			}
		}
	}

	/**
	 * @return array<int, \Closure(): bool> schema version => migration
	 */
	private function migrations(): array {
		return [
			1 => fn (): bool => $this->createTables(self::tables()),
			// Milestone 3: the cluster's Cacti device and the node counts the
			// data inputs read. A fresh install already has both from
			// migration 1, which uses the current definitions.
			2 => fn (): bool => $this->createTables(array_intersect_key(self::tables(), ['plugin_hpcsched_node_count' => true])) && $this->addHostColumn(),
		];
	}

	/**
	 * Added with ALTER TABLE rather than api_plugin_db_add_column(), which
	 * records the column for core to drop on uninstall, also when the
	 * administrator chose to keep the tables.
	 */
	private function addHostColumn(): bool {
		if (!$this->columnExists('plugin_hpcsched_cluster', 'host_id')) {
			db_execute_prepared("ALTER TABLE plugin_hpcsched_cluster ADD COLUMN host_id int(10) unsigned NOT NULL DEFAULT '0' AFTER poller_id, ADD KEY host_id (host_id)", [], true, $this->connection);
		}

		return $this->columnExists('plugin_hpcsched_cluster', 'host_id');
	}

	private function columnExists(string $table, string $column): bool {
		return db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column], '', true, $this->connection) > 0;
	}

	/**
	 * @param array<string, array<string, mixed>> $tables
	 */
	private function createTables(array $tables): bool {
		foreach ($tables as $table => $definition) {
			api_plugin_db_table_create(self::PLUGIN, $table, $definition);

			if (!$this->tableExists($table)) {
				Log::error(sprintf('Unable to create table %s', $table));

				return false;
			}
		}

		return true;
	}

	private function tableExists(string $table): bool {
		return db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table], '', true, $this->connection) > 0;
	}

	/**
	 * Table definitions in api_plugin_db_table_create() format.
	 *
	 * Enum values are stored as varchar values, never list positions
	 * (REQ-CODE-003). Times are Unix seconds. Every cached value row carries
	 * the time it was observed, the time it was written, and the interval it
	 * was collected on (REQ-STATE-004). No table holds a credential: the
	 * cluster table has a token file path, never a token (REQ-CRED-001).
	 * Composite keys are unique keys, because 1.2.31 accepts only a single
	 * column name as a primary key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tables(): array {
		$innodb = ['type' => 'InnoDB', 'charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_ci', 'row_format' => 'Dynamic'];
		$id     = static fn (string $name): array => ['name' => $name, 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
		$int    = static fn (string $name): array => ['name' => $name, 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'];
		$flag   = static fn (string $name): array => ['name' => $name, 'type' => 'tinyint(1)', 'unsigned' => true, 'NULL' => false, 'default' => '0'];
		$text   = static fn (string $name, int $length, string $default = ''): array => ['name' => $name, 'type' => "varchar($length)", 'NULL' => false, 'default' => $default];
		$real   = static fn (string $name): array => ['name' => $name, 'type' => 'double', 'NULL' => true];
		$stamps = [$int('observed_at'), $int('ingested_at'), $int('collect_interval')];

		return [
			'plugin_hpcsched_cluster' => $innodb + [
				'comment' => 'Scheduler clusters and how each is read',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$text('name', 64),
					$id('poller_id') + ['default' => '1'],
					$int('host_id'),
					$text('admin_state', 16, 'enabled'),
					$text('transport', 16, 'commands'),
					$int('collect_interval') + ['default' => (string) Cluster::DEFAULT_INTERVAL],
					$text('slurm_conf', 255),
					$text('rest_url', 255),
					$text('rest_user', 64),
					$text('token_file', 255),
					$flag('collect_reservations'),
					$flag('collect_shares'),
					$int('created_at'),
					$int('updated_at'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'name', 'columns' => ['name']]],
				'keys'        => [['name' => 'poller_id', 'columns' => ['poller_id']], ['name' => 'host_id', 'columns' => ['host_id']]],
			],
			// One row per cluster: the reader lease and the last run.
			'plugin_hpcsched_run' => $innodb + [
				'comment' => 'Reader lease and last run per cluster',
				'columns' => [
					$id('cluster_id'),
					$text('holder', 64),
					$int('lease_expires'),
					$int('started_at'),
					$int('finished_at'),
					$real('duration'),
					$text('outcome', 16, 'never'),
					$text('error_class', 16),
					$text('slurm_release', 32),
					$text('transport', 16),
					$text('data_parser', 16),
					$int('calls'),
					$int('last_success'),
					$int('last_failure'),
					$int('backoff_until'),
					$int('backoff_step'),
					$flag('overran'),
					$flag('memory_limited'),
					$text('token_state', 16),
					$int('token_expires'),
				],
				'primary' => 'cluster_id',
			],
			// One row per cluster and section: the last attempt, and the
			// collection the stored rows of that section came from.
			'plugin_hpcsched_section' => $innodb + [
				'comment' => 'Snapshot section state per cluster',
				'columns' => [
					$id('cluster_id'),
					$text('section', 16),
					$text('state', 16, 'not_attempted'),
					$text('error_class', 16),
					$int('attempted_at'),
					$int('records'),
					...$stamps,
				],
				'unique_keys' => [['name' => 'cluster_section', 'columns' => ['cluster_id', 'section']]],
			],
			// Job counts, never jobs: kind is state, family, or reason, and
			// partition '' is the cluster total, where a job submitted to
			// several partitions counts once.
			'plugin_hpcsched_job_count' => $innodb + [
				'comment' => 'Job counts by state, reason family, and reason',
				'columns' => [
					$id('cluster_id'),
					$text('partition_name', 64),
					$text('kind', 8),
					$text('name', 64),
					$int('jobs'),
					...$stamps,
				],
				'unique_keys' => [['name' => 'cluster_count', 'columns' => ['cluster_id', 'partition_name', 'kind', 'name']]],
			],
			// Node and CPU counts per partition (NodeTally), written with
			// the nodes section; partition '' is the cluster.
			'plugin_hpcsched_node_count' => $innodb + [
				'comment' => 'Node and CPU counts by partition',
				'columns' => [
					$id('cluster_id'),
					$text('partition_name', 64),
					$text('name', 16),
					$int('value'),
					...$stamps,
				],
				'unique_keys' => [['name' => 'cluster_count', 'columns' => ['cluster_id', 'partition_name', 'name']]],
			],
			'plugin_hpcsched_node' => $innodb + [
				'comment' => 'Scheduler nodes with normalized and raw state',
				'columns' => [
					$id('cluster_id'),
					$text('name', 64),
					$text('hostname', 255),
					$text('address', 255),
					$text('partitions', 1024),
					$text('state', 16, 'other'),
					$text('raw_state', 255),
					$text('reason', 255),
					$int('reason_changed_at'),
					$int('cpus'),
					$int('alloc_cpus'),
					...$stamps,
				],
				'unique_keys' => [['name' => 'cluster_node', 'columns' => ['cluster_id', 'name']]],
			],
			'plugin_hpcsched_partition' => $innodb + [
				'comment' => 'Scheduler partitions',
				'columns' => [
					$id('cluster_id'),
					$text('name', 64),
					$text('state', 32),
					$int('total_nodes'),
					$int('total_cpus'),
					...$stamps,
				],
				'unique_keys' => [['name' => 'cluster_partition', 'columns' => ['cluster_id', 'name']]],
			],
			'plugin_hpcsched_reservation' => $innodb + [
				'comment' => 'Scheduler reservations',
				'columns' => [
					$id('cluster_id'),
					$text('name', 128),
					$text('flags', 512),
					['name' => 'node_list', 'type' => 'text', 'NULL' => true],
					$int('start_time'),
					$int('end_time'),
					...$stamps,
				],
				'unique_keys' => [['name' => 'cluster_reservation', 'columns' => ['cluster_id', 'name']]],
			],
			// Account associations only; user rows from sshare are dropped
			// before they reach the plugin (ADR 0010).
			'plugin_hpcsched_share' => $innodb + [
				'comment' => 'Fair-share usage per account',
				'columns' => [
					$id('cluster_id'),
					$text('account', 64),
					$text('partition_name', 64),
					$text('parent', 64),
					$real('usage_raw'),
					$real('usage_normalized'),
					$real('effective_usage'),
					$real('fairshare_factor'),
					$real('fairshare_level'),
					...$stamps,
				],
				'unique_keys' => [['name' => 'cluster_share', 'columns' => ['cluster_id', 'account', 'partition_name']]],
			],
		];
	}
}
