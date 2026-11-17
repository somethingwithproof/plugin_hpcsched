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

// Test code: refuse to run from a web request.
PHP_SAPI === 'cli' || exit(1);

/*
 * Drive the plugin lifecycle through Cacti's own plugin API in a fresh PHP
 * process, the way plugins.php and cli/plugin_manage.php do, and print the
 * resulting state as JSON. Used by tests/Integration. Disposable databases
 * only.
 *
 *   php plugins/hpcsched/tests/bin/lifecycle.php <action> [argument]
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/plugins.php');
require_once($config['base_path'] . '/lib/poller.php');

if (getenv('HPCSCHED_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set HPCSCHED_TEST_DATABASE=disposable\n");
	exit(2);
}

$action   = isset($argv[1]) ? $argv[1] : 'state';
$argument = isset($argv[2]) ? $argv[2] : '';
$result   = null;

ob_start();

switch ($action) {
	case 'reset':
		api_plugin_uninstall('hpcsched');

		foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_hpcsched\\_%'") as $row) {
			db_execute('DROP TABLE `' . $row['t'] . '`');
		}

		db_execute("DELETE FROM settings WHERE name LIKE 'hpcsched\\_%'");
		db_execute("DELETE FROM plugin_db_changes WHERE plugin = 'hpcsched'");

		break;
	case 'install':
		api_plugin_install('hpcsched');

		break;
	case 'enable':
		api_plugin_enable('hpcsched');

		break;
	case 'disable':
		api_plugin_disable('hpcsched');

		break;
	case 'uninstall':
		api_plugin_uninstall('hpcsched');

		break;
	case 'upgrade':
		include_once($config['base_path'] . '/plugins/hpcsched/setup.php');
		$result = plugin_hpcsched_upgrade();

		break;
	case 'migrate_through':
		// Stop after the given migration, as an upgrade killed part way would.
		include_once($config['base_path'] . '/plugins/hpcsched/setup.php');
		hpcsched_boot();
		$result = (new \Somethingwithproof\Hpcsched\Schema(false))->migrate((int) $argument);

		break;
	case 'set_version':
		db_execute_prepared("UPDATE plugin_config SET version = ? WHERE directory = 'hpcsched'", array($argument));

		break;
	case 'set_schema':
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('hpcsched_schema_version', ?)", array($argument));

		break;
	case 'set_setting':
		list($name, $value) = explode('=', $argument, 2);
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $value));

		break;
	case 'drop_registrations':
		// What a failed hook registration on an earlier request leaves.
		db_execute("DELETE FROM plugin_hooks WHERE name = 'hpcsched'");
		db_execute("DELETE FROM plugin_realms WHERE plugin = 'hpcsched'");

		break;
	case 'drop_table':
		db_execute('DROP TABLE IF EXISTS `' . preg_replace('/[^a-z_]/', '', $argument) . '`');

		break;
	case 'make_schema_1':
		// What a schema 1 install has: no device column and no node count
		// table, which core never recorded either.
		db_execute('ALTER TABLE plugin_hpcsched_cluster DROP KEY host_id, DROP COLUMN host_id');
		db_execute('DROP TABLE IF EXISTS plugin_hpcsched_node_count');
		db_execute("DELETE FROM plugin_db_changes WHERE plugin = 'hpcsched' AND `table` = 'plugin_hpcsched_node_count'");

		break;
	case 'seed':
		// A cluster row, so table drops and keeps are visible.
		db_execute("INSERT INTO plugin_hpcsched_cluster (name, poller_id, admin_state, transport, collect_interval) VALUES ('seed', 1, 'enabled', 'commands', 60)");

		break;
	case 'reader_flow':
		include($config['base_path'] . '/plugins/hpcsched/tests/bin/reader-flow.php');

		break;
	case 'uninstall_unbooted':
		// What core does on uninstall when hpcsched_boot() refuses: the PHP 8.0
		// fallback, then core drops whatever is still tracked.
		include_once($config['base_path'] . '/plugins/hpcsched/include/unbooted-uninstall.php');
		hpcsched_uninstall_unbooted();
		api_plugin_db_changes_remove('hpcsched');
		api_plugin_remove_hooks('hpcsched');
		api_plugin_remove_realms('hpcsched');
		db_execute("DELETE FROM plugin_config WHERE directory = 'hpcsched'");

		break;
	case 'repair_as_viewer':
		// A viewer opens a plugin page after the realm rows went missing.
		db_execute("DELETE FROM user_auth WHERE username = 'hpcschedviewer'");
		db_execute("INSERT INTO user_auth (username, password, realm, full_name, enabled) VALUES ('hpcschedviewer', '', 0, 'viewer', 'on')");
		$viewer = (int) db_fetch_insert_id();
		db_execute("DELETE FROM plugin_realms WHERE plugin = 'hpcsched'");
		db_execute("DELETE FROM plugin_hooks WHERE name = 'hpcsched'");
		$_SESSION['sess_user_id'] = $viewer;
		db_execute("REPLACE INTO settings (name, value) VALUES ('admin_user', '0')");
		include_once($config['base_path'] . '/plugins/hpcsched/setup.php');
		plugin_hpcsched_upgrade();
		$result = array('viewer_realms' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM user_auth_realm WHERE user_id = ?', array($viewer)));
		db_execute("REPLACE INTO settings (name, value) VALUES ('admin_user', '1')");

		break;
	case 'reader_second':
	case 'reader_victim':
	case 'reader_kill':
		include($config['base_path'] . '/plugins/hpcsched/tests/bin/reader-restart.php');

		break;
	case 'operator_roles':
		include(__DIR__ . '/operator-roles.php');

		break;
	case 'state':
		break;
	default:
		fwrite(STDERR, "Unknown action $action\n");
		exit(2);
}

$output = ob_get_clean();

$tables = array();

foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_hpcsched\\_%' ORDER BY TABLE_NAME") as $row) {
	$tables[$row['t']] = (int) db_fetch_cell('SELECT COUNT(*) FROM `' . $row['t'] . '`');
}

// Cacti objects the template import creates, counted by table, and
// whether the installer finds them all and the query file in place.
$templates = array();

foreach (array('host_template', 'data_input', 'data_input_fields', 'data_template', 'data_template_rrd', 'graph_templates', 'graph_templates_item', 'snmp_query', 'snmp_query_graph', 'snmp_query_graph_rrd') as $table) {
	$templates[$table] = (int) db_fetch_cell("SELECT COUNT(*) FROM `$table`");
}

include_once($config['base_path'] . '/plugins/hpcsched/setup.php');

$columns = array_column(db_fetch_assoc("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_hpcsched_cluster' ORDER BY ORDINAL_POSITION"), 'c');

print json_encode(array(
	'templates' => $templates,
	'templates_current' => hpcsched_boot() && hpcsched_template_installer()->isCurrent(),
	'query_file' => is_file($config['base_path'] . '/resource/script_server/hpcsched_partitions.xml'),
	'columns' => $columns,
	'result'  => $result,
	'output'  => $output,
	'plugin'  => db_fetch_row("SELECT status, version FROM plugin_config WHERE directory = 'hpcsched'"),
	'schema'  => db_fetch_cell("SELECT value FROM settings WHERE name = 'hpcsched_schema_version'"),
	'hooks'   => array_column(db_fetch_assoc("SELECT hook, status FROM plugin_hooks WHERE name = 'hpcsched' ORDER BY hook"), 'status', 'hook'),
	'realms'  => array_column(db_fetch_assoc("SELECT file, display FROM plugin_realms WHERE plugin = 'hpcsched' ORDER BY file"), 'display', 'file'),
	'admin'   => (int) db_fetch_cell("SELECT COUNT(*) FROM user_auth_realm AS uar INNER JOIN plugin_realms AS pr ON pr.id + 100 = uar.realm_id WHERE pr.plugin = 'hpcsched' AND uar.user_id = 1"),
	'tracked' => array_column(db_fetch_assoc("SELECT `table` FROM plugin_db_changes WHERE plugin = 'hpcsched' AND method = 'create' ORDER BY `table`"), 'table'),
	'tables'  => $tables,
), JSON_PRETTY_PRINT) . PHP_EOL;
