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
 * Device permissions over HTTP (REQ-SEC-001, REQ-SEC-002). Two clusters get
 * devices, permA and permB, and a third, permC, has none. Then:
 *
 *   admin      all devices: sees every cluster
 *   hpcadmin2  View and Admin realms, allowed device A only: sees permA and
 *              permC, and every action on permB (edit, device page,
 *              delete, disable, unmap, reindex, mapping permC onto device
 *              B, the export, the health page, the overview, the reasons
 *              view) shows or changes nothing of permB
 *   hpcview2   View realm, allowed device A only: the overview and reasons
 *              view follow the device, and so do Cacti's graph permissions
 *              for the clusters' graphs; the data source pages are denied
 *   revoked    hpcview2 after its device permission is removed
 *
 * Needs the plugin installed and enabled with its templates. Disposable
 * databases only: it resets the admin password and adds users and devices.
 * Must parse on PHP 8.0.
 *
 *   HPCSCHED_TEST_DATABASE=disposable php plugins/hpcsched/tests/bin/device-permissions.php http://127.0.0.1:8080/cacti/
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('HPCSCHED_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set HPCSCHED_TEST_DATABASE=disposable\n");
	exit(2);
}

include_once($config['base_path'] . '/plugins/hpcsched/setup.php');
foreach (array('api_automation_tools', 'api_data_source', 'api_graph', 'api_tree', 'api_device', 'data_query', 'template', 'utility', 'poller') as $hpcsched_lib) {
	include_once($config['base_path'] . '/lib/' . $hpcsched_lib . '.php');
}

if (!hpcsched_boot()) {
	fwrite(STDERR, "The plugin did not boot\n");
	exit(1);
}

$base     = rtrim($argv[1], '/') . '/plugins/hpcsched/';
$root     = rtrim($argv[1], '/') . '/';
$password = 'Hpc-' . bin2hex(random_bytes(8));
$failures = 0;
$hosts    = array();

function dp_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function dp_http($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	$body = (string) curl_exec($ch);

	return array((int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body);
}

function dp_token($body) {
	return preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $body, $m) ? $m[1] : '';
}

function dp_login($user) {
	global $root, $password;

	$jar = tempnam(sys_get_temp_dir(), 'hpcscheddp');
	list(, $login) = dp_http($root . 'index.php', $jar);
	dp_http($root . 'index.php', $jar, array('__csrf_magic' => dp_token($login), 'action' => 'login', 'login_username' => $user, 'login_password' => $password));

	return $jar;
}

/**
 * A POST to the clusters page carrying the csrf-magic token of a page view.
 */
function dp_post($jar, $fields) {
	global $base;

	list(, $page) = dp_http($base . 'hpcsched_clusters.php', $jar);

	return dp_http($base . 'hpcsched_clusters.php', $jar, array('__csrf_magic' => dp_token($page)) + $fields);
}

function dp_cluster($id) {
	return db_fetch_row_prepared('SELECT id, host_id, admin_state FROM plugin_hpcsched_cluster WHERE id = ?', array($id));
}

try {
	$installer = hpcsched_template_installer();
	dp_expect($installer->isCurrent() || $installer->install(), 'the templates are installed');

	$insert = "INSERT INTO plugin_hpcsched_cluster (name, poller_id, admin_state, transport, collect_interval) VALUES (?, 1, 'enabled', 'commands', 60)";
	$ids    = array();

	foreach (array('permA', 'permB', 'permC') as $name) {
		db_execute_prepared($insert, array($name));
		$ids[$name] = (int) db_fetch_insert_id();
	}

	$clusters    = new \Somethingwithproof\Hpcsched\ClusterRepository(false, new \Somethingwithproof\Hpcsched\SystemClock());
	$provisioner = hpcsched_provisioner();

	foreach (array('permA', 'permB') as $name) {
		$hosts[$name] = $provisioner->create($clusters->get($ids[$name]), false);
	}

	dp_expect($hosts['permA'] > 0 && $hosts['permB'] > 0, 'both clusters have devices');

	// Users. Realm 8 is Console access. Policy 2 denies every device,
	// graph, and graph template but those listed in user_auth_perms; device
	// A is listed (type 3), so under Cacti's default Permissive graph
	// method the device alone decides which graphs the user sees.
	$view  = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'hpcsched' AND file = 'hpcsched.php'");
	$admin = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'hpcsched' AND file LIKE 'hpcsched_clusters.php%'");
	$users = array();

	db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on', locked = '', failed_attempts = 0 WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

	foreach (array('hpcadmin2' => array(8, $view, $admin), 'hpcview2' => array(8, $view)) as $name => $realms) {
		db_execute_prepared('DELETE FROM user_auth WHERE username = ?', array($name));
		db_execute_prepared("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled) VALUES (?, ?, 0, ?, '', '', 2, 1, 2, 2, 'on')", array($name, password_hash($password, PASSWORD_DEFAULT), $name));
		$users[$name] = (int) db_fetch_insert_id();

		foreach ($realms as $realm) {
			db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $users[$name]));
		}

		db_execute_prepared('INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, 3)', array($users[$name], $hosts['permA']));
	}

	// Cacti's own view of the two users.
	dp_expect(is_device_allowed($hosts['permA'], $users['hpcadmin2']) && !is_device_allowed($hosts['permB'], $users['hpcadmin2']), 'Cacti allows hpcadmin2 device A only');

	// Admin sees every cluster.
	$jar = dp_login('admin');
	list(, $list) = dp_http($base . 'hpcsched_clusters.php', $jar);
	dp_expect(strpos($list, '>permA<') !== false && strpos($list, '>permB<') !== false && strpos($list, '>permC<') !== false, 'admin sees every cluster');
	unlink($jar);

	// The restricted plugin administrator.
	$jar = dp_login('hpcadmin2');
	$b   = $ids['permB'];

	list(, $list) = dp_http($base . 'hpcsched_clusters.php', $jar);
	dp_expect(strpos($list, '>permA<') !== false && strpos($list, '>permC<') !== false && strpos($list, 'permB') === false, 'hpcadmin2 lists permA and permC, not permB');

	list(, $edit) = dp_http($base . 'hpcsched_clusters.php?action=edit&id=' . $b, $jar);
	dp_expect(strpos($edit, 'value="permB"') === false && strpos($edit, 'permission for the device') !== false, 'hpcadmin2 cannot open permB for editing');

	list(, $device) = dp_http($base . 'hpcsched_clusters.php?action=device&id=' . $b, $jar);
	list(, $mine) = dp_http($base . 'hpcsched_clusters.php?action=device&id=' . $ids['permA'], $jar);
	dp_expect(strpos($device, 'Device for Cluster: permB') === false && strpos($mine, 'Device for Cluster: permA') !== false, 'hpcadmin2 opens the device page of permA only');

	$before = dp_cluster($b);

	foreach (array('disable', 'unmap_device', 'reindex_device', 'attach_device', 'delete') as $action) {
		dp_post($jar, array('action' => $action, 'id' => $b, 'partition_graphs' => 'on'));
		dp_expect(dp_cluster($b) == $before, "hpcadmin2 cannot $action permB");
	}

	dp_post($jar, array('action' => 'map_device', 'id' => $ids['permC'], 'host_id' => $hosts['permB']));
	dp_expect((int) dp_cluster($ids['permC'])['host_id'] === 0, 'hpcadmin2 cannot map permC onto device B');

	dp_post($jar, array('action' => 'save', 'id' => $b, 'updated_at' => 0, 'name' => 'stolen', 'poller_id' => 1, 'collect_interval' => 60, 'transport' => 'commands', 'enabled' => 'on'));
	dp_expect((string) db_fetch_cell_prepared('SELECT name FROM plugin_hpcsched_cluster WHERE id = ?', array($b)) === 'permB', 'hpcadmin2 cannot save over permB');

	list(, $export) = dp_http($base . 'hpcsched_clusters.php?action=export', $jar);
	$data = json_decode($export, true);
	$names = is_array($data) ? array_values(array_filter(array_column($data['clusters'], 'name'), function ($name) {
		return strpos($name, 'perm') === 0;
	})) : array();
	dp_expect($names === array('permA', 'permC'), 'the export holds permA and permC, not permB');

	list(, $health) = dp_http($base . 'hpcsched_health.php', $jar);
	list(, $overview) = dp_http($base . 'hpcsched.php', $jar);
	list(, $reasons) = dp_http($base . 'hpcsched.php?action=reasons&id=' . $b, $jar);
	dp_expect(strpos($health, 'Snapshot Sections') !== false && strpos($health, 'permB') === false, 'the health page leaves permB out');
	dp_expect(strpos($overview, 'permA') !== false && strpos($overview, 'permB') === false && strpos($reasons, 'Pending Reasons: permB') === false, 'the overview and the reasons view leave permB out');
	unlink($jar);

	// A viewer limited to device A, then with that permission removed.
	$graphs = array();

	foreach ($hosts as $name => $host) {
		$graphs[$name] = array_map('intval', array_column(db_fetch_assoc_prepared('SELECT id FROM graph_local WHERE host_id = ?', array($host)), 'id'));
	}

	$allowed = function ($name) use ($graphs, $users) {
		$out = array();

		foreach ($graphs[$name] as $graph) {
			$out[] = is_graph_allowed($graph, $users['hpcview2']);
		}

		return array_values(array_unique($out));
	};

	$jar = dp_login('hpcview2');
	list(, $overview) = dp_http($base . 'hpcsched.php', $jar);
	list(, $reasonsA) = dp_http($base . 'hpcsched.php?action=reasons&id=' . $ids['permA'], $jar);
	list(, $reasonsB) = dp_http($base . 'hpcsched.php?action=reasons&id=' . $b, $jar);
	dp_expect(strpos($overview, 'permA') !== false && strpos($overview, 'permC') !== false && strpos($overview, 'permB') === false, 'hpcview2 sees permA and permC on the overview');
	dp_expect(strpos($reasonsA, 'Pending Reasons: permA') !== false && strpos($reasonsB, 'Pending Reasons: permB') === false, 'hpcview2 opens the reasons view of permA only');
	dp_expect(count($graphs['permA']) === 6 && $allowed('permA') === array(true) && $allowed('permB') === array(false), 'Cacti allows hpcview2 the graphs of device A only');

	list(, $sources) = dp_http($root . 'data_sources.php', $jar);
	dp_expect(strpos($sources, 'Data Source Management') === false && strpos($sources, 'local_data_id') === false, 'hpcview2 cannot open the data source pages');
	unlink($jar);

	db_execute_prepared('DELETE FROM user_auth_perms WHERE user_id = ?', array($users['hpcview2']));
	$jar = dp_login('hpcview2');
	list(, $overview) = dp_http($base . 'hpcsched.php', $jar);
	dp_expect(strpos($overview, 'permC') !== false && strpos($overview, 'permA') === false && strpos($overview, 'permB') === false && $allowed('permA') === array(false), 'with the permission revoked, hpcview2 sees neither device\'s cluster nor graphs');
	unlink($jar);
} finally {
	foreach ($hosts as $host) {
		if ($host > 0) {
			api_device_remove($host);
		}
	}

	db_execute("DELETE FROM plugin_hpcsched_cluster WHERE name IN ('permA', 'permB', 'permC', 'stolen')");
	db_execute("DELETE FROM user_auth WHERE username IN ('hpcadmin2', 'hpcview2')");
}

exit($failures > 0 ? 1 : 0);
