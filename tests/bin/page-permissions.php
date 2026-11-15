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
 * Page permissions and POST-only actions over HTTP. Logs in to a running
 * Cacti as three users and checks each plugin page:
 *
 *   admin    View and Admin realms: all three pages load
 *   viewer   Console and the View realm: hpcsched.php loads, the cluster
 *            and health pages are denied
 *   norealm  Console only: every page is denied
 *
 * Then shows that stored names are escaped, that a delete sent as GET or as
 * POST without the csrf-magic token has no effect while a proper POST works,
 * that the cluster form refuses unsafe values and saves good ones, that the
 * export holds no token, and that no page view ran a reader.
 * Disposable databases only: it resets the admin password and adds users.
 * Must parse on PHP 8.0.
 *
 *   HPCSCHED_TEST_DATABASE=disposable php plugins/hpcsched/tests/bin/page-permissions.php http://127.0.0.1:8080/cacti/
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('HPCSCHED_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set HPCSCHED_TEST_DATABASE=disposable\n");
	exit(2);
}

$base     = rtrim($argv[1], '/') . '/plugins/hpcsched/';
$root     = rtrim($argv[1], '/') . '/';
$password = 'Hpc-' . bin2hex(random_bytes(8));
$failures = 0;

$view  = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'hpcsched' AND file = 'hpcsched.php'");
$admin = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'hpcsched' AND file LIKE 'hpcsched_clusters.php%'");

db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on', locked = '', failed_attempts = 0 WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

// Realm 8 is Console access.
foreach (array('viewer' => array(8, $view), 'norealm' => array(8)) as $name => $realms) {
	db_execute_prepared('DELETE FROM user_auth WHERE username = ?', array($name));
	db_execute_prepared("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled) VALUES (?, ?, 0, ?, '', '', 1, 1, 1, 1, 'on')", array($name, password_hash($password, PASSWORD_DEFAULT), $name));
	$id = (int) db_fetch_insert_id();

	foreach ($realms as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $id));
	}
}

function perm_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function perm_http($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	$body = (string) curl_exec($ch);

	return array((int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body);
}

function perm_token($body) {
	return preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $body, $m) ? $m[1] : '';
}

function perm_login($user) {
	global $root, $password;

	$jar = tempnam(sys_get_temp_dir(), 'hpcschedperm');
	list(, $login) = perm_http($root . 'index.php', $jar);
	perm_http($root . 'index.php', $jar, array('__csrf_magic' => perm_token($login), 'action' => 'login', 'login_username' => $user, 'login_password' => $password));

	return $jar;
}

// Markers that only the rendered plugin page contains.
$pages = array('hpcsched.php' => 'Pending excludes held jobs', 'hpcsched_clusters.php' => 'holds token file paths, never a token', 'hpcsched_health.php' => 'Snapshot Sections');

// Names are validated on save; this row is stored directly, as a hostile
// database edit would, to show every page escapes what it prints.
db_execute_prepared("INSERT INTO plugin_hpcsched_cluster (name, poller_id, admin_state, transport, collect_interval) VALUES (?, 1, 'enabled', 'commands', 60)", array('<b id="hpcprobe">'));
$probe = (int) db_fetch_insert_id();

$access = array(
	'admin'   => array('hpcsched.php' => true, 'hpcsched_clusters.php' => true, 'hpcsched_health.php' => true),
	'viewer'  => array('hpcsched.php' => true, 'hpcsched_clusters.php' => false, 'hpcsched_health.php' => false),
	'norealm' => array('hpcsched.php' => false, 'hpcsched_clusters.php' => false, 'hpcsched_health.php' => false),
);

foreach ($access as $user => $expect) {
	$jar = perm_login($user);

	foreach ($expect as $page => $allowed) {
		list($code, $body) = perm_http($base . $page, $jar);
		$shown = strpos($body, $pages[$page]) !== false;

		perm_expect($shown === $allowed && preg_match('/(Fatal error|Parse error|Warning:|Uncaught)/', $body) !== 1, "$user " . ($allowed ? 'sees' : 'is denied') . " $page (HTTP $code)");
	}

	unlink($jar);
}

$jar = perm_login('admin');
$exists = function () use ($probe) {
	return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hpcsched_cluster WHERE id = ?', array($probe)) === 1;
};

foreach (array('hpcsched.php', 'hpcsched_clusters.php', 'hpcsched_clusters.php?action=edit&id=' . $probe, 'hpcsched_health.php') as $page) {
	list(, $body) = perm_http($base . $page, $jar);
	perm_expect(strpos($body, '<b id="hpcprobe">') === false && strpos($body, '&lt;b id=&quot;hpcprobe&quot;&gt;') !== false, "$page escapes the stored name");
}

perm_http($base . 'hpcsched_clusters.php?action=delete&id=' . $probe, $jar);
perm_expect($exists(), 'a delete sent as GET has no effect');

perm_http($base . 'hpcsched_clusters.php', $jar, array('action' => 'delete', 'id' => $probe));
perm_expect($exists(), 'a delete POST without the csrf-magic token has no effect');

list(, $list) = perm_http($base . 'hpcsched_clusters.php', $jar);
perm_http($base . 'hpcsched_clusters.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'delete', 'id' => $probe));
perm_expect(!$exists(), 'the same delete as a POST with the token removes the cluster');

// The form refuses a plain HTTP URL to another host and a relative token
// path, then saves good values.
$cluster = array('action' => 'save', 'id' => 0, 'updated_at' => 0, 'name' => 'demo', 'poller_id' => 1, 'collect_interval' => 60, 'transport' => 'rest', 'rest_url' => 'http://slurm.example.org:6820', 'rest_user' => 'cactiread', 'token_file' => 'token', 'enabled' => 'on');

list(, $form) = perm_http($base . 'hpcsched_clusters.php?action=edit', $jar);
list(, $refused) = perm_http($base . 'hpcsched_clusters.php', $jar, array('__csrf_magic' => perm_token($form)) + $cluster);
perm_expect(strpos($refused, 'plain http:// is accepted only for a loopback address') !== false && strpos($refused, 'Token File:') !== false && (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_hpcsched_cluster') === 0, 'unsafe cluster values are refused');

// A token file outside the token directories set in Settings is refused.
db_execute("REPLACE INTO settings (name, value) VALUES ('hpcsched_token_dirs', '/etc/cacti/hpcsched')");
$outside = array('rest_url' => 'https://slurm.example.org:6820', 'token_file' => '/etc/passwd') + $cluster;
list(, $form) = perm_http($base . 'hpcsched_clusters.php?action=edit', $jar);
list(, $refused) = perm_http($base . 'hpcsched_clusters.php', $jar, array('__csrf_magic' => perm_token($form)) + $outside);
perm_expect(strpos($refused, 'must be inside one of the token file directories') !== false && (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_hpcsched_cluster') === 0, 'a token file outside the token directories is refused');

$tokenPath = '/etc/cacti/hpcsched/probe-' . bin2hex(random_bytes(4));
$cluster   = array('rest_url' => 'https://slurm.example.org:6820', 'token_file' => $tokenPath) + $cluster;

list(, $form) = perm_http($base . 'hpcsched_clusters.php?action=edit', $jar);
list(, $saved) = perm_http($base . 'hpcsched_clusters.php', $jar, array('__csrf_magic' => perm_token($form)) + $cluster);
$id = (int) db_fetch_cell("SELECT id FROM plugin_hpcsched_cluster WHERE name = 'demo'");
perm_expect(strpos($saved, 'The cluster was saved.') !== false && $id > 0, 'the cluster saves through the form');

// A form loaded before another save is refused instead of overwriting it.
list(, $form) = perm_http($base . 'hpcsched_clusters.php?action=edit&id=' . $id, $jar);
$stamp = preg_match('/name="updated_at" value="(\d+)"/', $form, $m) ? $m[1] : '0';
db_execute_prepared('UPDATE plugin_hpcsched_cluster SET updated_at = updated_at + 5 WHERE id = ?', array($id));
list(, $stale) = perm_http($base . 'hpcsched_clusters.php', $jar, array('__csrf_magic' => perm_token($form), 'id' => $id, 'updated_at' => $stamp, 'collect_interval' => 120) + $cluster);
perm_expect(strpos($stale, 'changed by someone else') !== false && (int) db_fetch_cell_prepared('SELECT collect_interval FROM plugin_hpcsched_cluster WHERE id = ?', array($id)) === 60, 'a stale form does not overwrite a newer save');

list($code, $export) = perm_http($base . 'hpcsched_clusters.php?action=export', $jar);
$data = json_decode($export, true);
perm_expect($code === 200 && is_array($data) && count($data['clusters']) === 1 && $data['clusters'][0]['token_file'] === $tokenPath && strpos($export, 'eyJ') === false, 'the export holds the token file path and no token');

perm_expect((int) db_fetch_cell('SELECT COUNT(*) FROM plugin_hpcsched_run') === 0 && (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_hpcsched_section') === 0, 'no page view ran a reader');

// Test scripts refuse a web request before doing anything.
foreach (array('tests/Support/fake-slurmrestd.php', 'tests/bin/lifecycle.php', 'tests/bin/reader-flow.php', 'tests/Support/make-certs.php') as $script) {
	list(, $body) = perm_http($base . $script, $jar);
	perm_expect($body === '', "$script does nothing when requested over the web");
}

unlink($jar);

exit($failures > 0 ? 1 : 0);
