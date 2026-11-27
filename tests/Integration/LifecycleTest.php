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

// The version INFO declares, which Cacti records as the installed version.
define('HPCSCHED_VERSION', (string) (parse_ini_file(dirname(__DIR__, 2) . '/INFO', true)['info']['version'] ?? ''));

const HPCSCHED_TABLES = [
	'plugin_hpcsched_cluster',
	'plugin_hpcsched_job_count',
	'plugin_hpcsched_maint_link',
	'plugin_hpcsched_node',
	'plugin_hpcsched_node_count',
	'plugin_hpcsched_node_map',
	'plugin_hpcsched_partition',
	'plugin_hpcsched_reservation',
	'plugin_hpcsched_run',
	'plugin_hpcsched_section',
	'plugin_hpcsched_share',
];

const HPCSCHED_HOOKS = ['abilities_register', 'api_register_resources', 'auth_alternate_realms', 'casc_register_providers', 'config_arrays', 'config_settings', 'device_remove', 'doctor_register_checks', 'doctor_register_debug_info', 'draw_navigation_text', 'poller_bottom', 'replicate_out'];

const HPCSCHED_T = 1800000000;

beforeEach(function () {
	lifecycle('reset');
});

afterAll(function () {
	lifecycle('reset');
});

it('installs fresh with tracked tables, hooks, and three realms, leaving the plugin disabled', function () {
	$state = lifecycle('install');

	expect($state['plugin']['status'])->toEqual('4')
		->and($state['plugin']['version'])->toEqual(HPCSCHED_VERSION)
		->and($state['schema'])->toEqual('3')
		->and(array_keys($state['tables']))->toBe(HPCSCHED_TABLES)
		->and($state['tracked'])->toBe(HPCSCHED_TABLES)
		->and(array_keys($state['hooks']))->toBe(HPCSCHED_HOOKS)
		->and($state['realms'])->toEqual([
			'hpcsched.php' => 'HPC Scheduler View',
			'hpcsched_clusters.php,hpcsched_health.php' => 'HPC Scheduler Admin',
			'hpcsched_operator.php' => 'HPC Scheduler Operator',
		])
		->and($state['admin'])->toBe(2);
});

it('enables and disables through core, keeping data on disable', function () {
	lifecycle('install');
	lifecycle('seed');

	$enabled = lifecycle('enable');
	expect($enabled['plugin']['status'])->toEqual('1')
		->and(array_values(array_unique(array_map('strval', $enabled['hooks']))))->toBe(['1']);

	$disabled = lifecycle('disable');
	expect($disabled['plugin']['status'])->toEqual('4')
		->and($disabled['tables']['plugin_hpcsched_cluster'])->toBe(1);
});

it('upgrades from an older recorded version and is idempotent on repeated runs', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('set_version', '0.0');

	$first = lifecycle('upgrade');
	expect($first['result'])->toBeTrue()
		->and($first['plugin']['version'])->toEqual(HPCSCHED_VERSION);

	foreach ([lifecycle('upgrade'), lifecycle('upgrade')] as $run) {
		expect($run['result'])->toBeTrue()
			->and($run['tables'])->toBe($first['tables'])
			->and($run['hooks'])->toBe($first['hooks'])
			->and($run['realms'])->toBe($first['realms'])
			->and($run['tracked'])->toBe(HPCSCHED_TABLES);
	}

	expect($first['tables']['plugin_hpcsched_cluster'])->toBe(1);
});

it('re-registers hooks and realms that went missing, active while the plugin is enabled', function () {
	lifecycle('install');
	lifecycle('enable');

	expect(lifecycle('drop_registrations')['hooks'])->toBe([]);

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['hooks']))->toBe(HPCSCHED_HOOKS)
		->and(array_values(array_unique(array_map('strval', $state['hooks']))))->toBe(['1'])
		->and($state['realms'])->toHaveCount(3);
});

it('finishes an upgrade that was interrupted after creating only some tables', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('drop_table', 'plugin_hpcsched_share');
	lifecycle('drop_table', 'plugin_hpcsched_section');
	lifecycle('set_schema', '0');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['tables']))->toBe(HPCSCHED_TABLES)
		->and($state['tables']['plugin_hpcsched_cluster'])->toBe(1)
		->and($state['schema'])->toEqual('3')
		->and($state['plugin']['version'])->toEqual(HPCSCHED_VERSION);
});

it('does not advance past a migration that did not run', function () {
	lifecycle('install');
	lifecycle('set_schema', '0');

	$stopped = lifecycle('migrate_through', '0');
	expect($stopped['result'])->toBeTrue()
		->and($stopped['schema'])->toEqual('0');

	expect(lifecycle('upgrade')['schema'])->toEqual('3');
});

it('upgrades a schema 1 install, adding the device column and the node counts, keeping its clusters', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('make_schema_1');
	lifecycle('set_schema', '1');
	lifecycle('set_version', '0.0');

	expect(lifecycle('state')['columns'])->not->toContain('host_id');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and($state['schema'])->toEqual('3')
		->and(array_keys($state['tables']))->toBe(HPCSCHED_TABLES)
		->and($state['tracked'])->toBe(HPCSCHED_TABLES)
		->and($state['tables']['plugin_hpcsched_cluster'])->toBe(1)
		->and($state['columns'])->toContain('host_id');
});

it('upgrades a schema 2 (main) install to schema 3 idempotently, keeping clusters', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('make_schema_2');
	lifecycle('set_schema', '2');
	lifecycle('set_version', '0.1.0');

	$before = lifecycle('state');
	expect(array_keys($before['tables']))->not->toContain('plugin_hpcsched_node_map')
		->and(array_keys($before['tables']))->not->toContain('plugin_hpcsched_maint_link');

	$first = lifecycle('upgrade');
	expect($first['result'])->toBeTrue()
		->and($first['schema'])->toEqual('3')
		->and($first['plugin']['version'])->toEqual(HPCSCHED_VERSION)
		->and(array_keys($first['tables']))->toBe(HPCSCHED_TABLES)
		->and($first['tracked'])->toBe(HPCSCHED_TABLES)
		->and(array_keys($first['hooks']))->toBe(HPCSCHED_HOOKS)
		->and($first['tables']['plugin_hpcsched_cluster'])->toBe(1);

	$second = lifecycle('upgrade');
	expect($second['result'])->toBeTrue()
		->and($second['schema'])->toEqual('3')
		->and($second['tables']['plugin_hpcsched_cluster'])->toBe(1)
		->and($second['tracked'])->toBe(HPCSCHED_TABLES)
		->and($second['hooks'])->toBe($first['hooks']);
});

it('imports the templates and the query file on install, and a reinstall creates no Cacti object (REQ-OBJ-001)', function () {
	$first = lifecycle('install');

	expect($first['templates_current'])->toBeTrue()
		->and($first['query_file'])->toBeTrue();

	$uninstalled = lifecycle('uninstall');

	// The query file the plugin wrote goes; the templates are the site's.
	expect($uninstalled['query_file'])->toBeFalse()
		->and($uninstalled['templates'])->toBe($first['templates']);

	$again = lifecycle('install');

	expect($again['templates_current'])->toBeTrue()
		->and($again['templates'])->toBe($first['templates'])
		->and(lifecycle('upgrade')['templates'])->toBe($first['templates']);
});

it('refuses a schema newer than the code and leaves the recorded version alone', function () {
	lifecycle('install');
	lifecycle('set_schema', '99');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeFalse()
		->and($state['plugin']['version'])->toEqual('0.0')
		->and($state['schema'])->toEqual('99');
});

it('keeps tables on uninstall by default, and drops them only when the box was cleared', function () {
	lifecycle('install');
	lifecycle('seed');

	$kept = lifecycle('uninstall');
	expect(array_keys($kept['tables']))->toBe(HPCSCHED_TABLES)
		->and($kept['tables']['plugin_hpcsched_cluster'])->toBe(1)
		->and($kept['tracked'])->toBe([])
		->and($kept['hooks'])->toBe([])
		->and($kept['realms'])->toBe([]);

	$reinstalled = lifecycle('install');
	expect($reinstalled['plugin']['status'])->toEqual('4')
		->and($reinstalled['tables']['plugin_hpcsched_cluster'])->toBe(1)
		->and($reinstalled['tracked'])->toBe(HPCSCHED_TABLES);

	lifecycle('set_setting', 'hpcsched_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall');
	expect($dropped['plugin'])->toBeEmpty()
		->and($dropped['tables'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();
});

it('honours keep tables on uninstall when the runtime is refused', function () {
	lifecycle('install');
	lifecycle('seed');

	$kept = lifecycle('uninstall_unbooted');
	expect($kept['tables']['plugin_hpcsched_cluster'])->toBe(1)
		->and($kept['tracked'])->toBe([]);

	lifecycle('reset');
	lifecycle('install');
	lifecycle('set_setting', 'hpcsched_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall_unbooted');
	expect($dropped['tables'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();
});

it('grants realms on install but never on a repair a viewer triggers', function () {
	expect(lifecycle('install')['admin'])->toBe(2);

	$repair = lifecycle('repair_as_viewer');
	expect($repair['realms'])->toHaveCount(3)
		->and($repair['result'])->toBe(['viewer_realms' => 0]);
});

it('reads both transports into a snapshot with states, stamps, and no token in storage', function () {
	lifecycle('install');

	$r = lifecycle('reader_flow')['result'];

	expect($r['due'])->toBeTrue()
		->and($r['cmd1'])->toBe(['outcome' => 'completed', 'error' => null, 'calls' => 5, 'release' => '25.11.2', 'limited' => true, 'token' => ''])
		->and($r['sections1'])->toBe([
			'jobs'         => ['completed', '', HPCSCHED_T],
			'nodes'        => ['completed', '', HPCSCHED_T],
			'partitions'   => ['completed', '', HPCSCHED_T],
			'reservations' => ['completed', '', HPCSCHED_T],
			'shares'       => ['completed', '', HPCSCHED_T],
		])
		->and($r['values1'])->toBe(['freshness' => 'current', 'age' => 0, 'pending' => 63, 'held' => 1, 'nodes' => 8, 'drained' => 3, 'down' => 1])
		->and($r['node_n03'])->toEqual(['state' => 'down', 'raw_state' => 'DOWN+DRAIN+NOT_RESPONDING', 'reason' => 'bad DIMM <b>', 'partitions' => 'batch', 'observed_at' => HPCSCHED_T, 'ingested_at' => HPCSCHED_T, 'collect_interval' => 60])
		->and($r['shares'])->toBe(['chem', 'physics', 'root'])
		->and($r['family'])->toEqual(['assoc' => 50, 'held' => 1, 'node' => 1, 'priority' => 1, 'qos' => 1, 'resources' => 10])
		->and($r['due_after'])->toBeTrue();
});

it('keeps a failed section\'s rows and their time without serving them, and ages a stopped reader into unknown', function () {
	lifecycle('install');

	$r = lifecycle('reader_flow')['result'];

	expect($r['cmd2'])->toMatchArray(['outcome' => 'partial', 'error' => 'failed'])
		->and($r['sections2']['jobs'])->toBe(['failed', 'failed', HPCSCHED_T])
		->and($r['sections2']['nodes'])->toBe(['completed', '', HPCSCHED_T + 60])
		->and($r['jobs2'])->toBeGreaterThan(0)
		// The failed jobs section is U although its rows are only delayed;
		// the completed nodes section is still served.
		->and($r['values_delayed'])->toMatchArray(['freshness' => 'delayed', 'age' => 160, 'pending' => null, 'held' => null, 'nodes' => 8])
		->and($r['values_stale'])->toMatchArray(['freshness' => 'stale', 'pending' => null, 'nodes' => null])
		->and($r['values_stale']['age'])->toBeGreaterThan($r['values_delayed']['age']);
});

it('reads slurmrestd on loopback, records a group-readable token, and keeps data after a refused credential', function () {
	lifecycle('install');

	$r = lifecycle('reader_flow')['result'];

	// One discovery ping finds the newest parser the fake serves; the fake
	// reports 25.11, so no read is conditional.
	expect($r['rest1'])->toBe(['outcome' => 'completed', 'error' => null, 'calls' => 6, 'release' => '25.11.2', 'limited' => true, 'token' => 'group_readable'])
		->and($r['requests1'])->toBe(['/slurm/v0.0.45/ping/', '/slurm/v0.0.45/jobs/', '/slurm/v0.0.45/nodes/', '/slurm/v0.0.45/partitions/', '/slurm/v0.0.45/reservations/', '/slurm/v0.0.45/shares'])
		->and($r['rest2'])->toMatchArray(['outcome' => 'failed', 'error' => 'credential', 'calls' => 1])
		->and($r['nodes2'])->toBe(8)
		->and($r['token_found'])->toBeFalse();
});

it('exits at once when a reader for the cluster already runs', function () {
	lifecycle('install');
	lifecycle('enable');

	$r = lifecycle('reader_second')['result'];

	expect($r['rc'])->toBe(0)
		->and($r['seconds'])->toBeLessThan(5.0)
		->and($r['runs'])->toBe(0)
		->and($r['rc_when_free'])->toBe(0)
		->and($r['outcome_free'])->toBe('completed');
});

it('leaves the previous snapshot when a run is killed mid-read, and the next run continues after the lease expires', function () {
	lifecycle('install');

	$r = lifecycle('reader_kill')['result'];

	expect($r['first']['outcome'])->toBe('completed')
		->and($r['victim_rc'])->not->toBe(0)
		->and($r['after_kill']['lease']['holder'])->toBe('victim')
		->and(array_diff_key($r['after_kill'], ['lease' => 1]))->toBe(array_diff_key($r['before_kill'], ['lease' => 1]))
		->and($r['after_kill']['observed'])->toBe(HPCSCHED_T)
		->and($r['while_leased']['calls'])->toBe(3)
		->and($r['after_expiry'])->toBe(['outcome' => 'completed', 'calls' => 3])
		->and($r['final']['observed'])->toBe(HPCSCHED_T + 290);
});

it('preserves old grants and resolves the three roles through Cacti including groups', function () {
	lifecycle('install');
	lifecycle('enable');
	$roles = lifecycle('operator_roles')['result'];
	expect($roles['ids_preserved'])->toBeTrue()
		->and($roles['viewer_grants'])->toBe([$roles['expected_view']])
		->and($roles['hook_enabled'])->toBe(1)
		->and($roles['viewer'])->toBe([true, false, false])
		->and($roles['operator'])->toBe([true, true, false])
		->and($roles['admin'])->toBe([true, true, true])
		->and($roles['revoked'])->toBe([false, false, false])
		->and($roles['group_operator'])->toBe([true, true, false])
		->and($roles['disabled_group'])->toBe([false, false, false])
		->and($roles['first_request']['viewer'])->toBe([[true, true, true], [true, false, true], [false, false, true]])
		->and($roles['first_request']['operator'])->toBe([[true, true, true], [true, true, true], [false, false, true]])
		->and($roles['first_request']['admin'])->toBe([[true, true, true], [true, true, true], [false, true, true]])
		->and($roles['first_request']['revoked'])->toBe([false, false, false])
		->and($roles['disabled_user'])->toBe([false, false, false]);
});
