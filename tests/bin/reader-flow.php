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

/*
 * Reader flow against the installed Cacti database, included by
 * tests/bin/lifecycle.php (action reader_flow). Both transports run against
 * stand-ins: the client commands are tests/fixtures/bin, slurmrestd is
 * tests/Support/fake-slurmrestd.php over plain HTTP on loopback. Nothing
 * contacts a cluster. Sets $result.
 */

use Somethingwithproof\Hpcsched\ClusterRepository;
use Somethingwithproof\Hpcsched\ClusterValues;
use Somethingwithproof\Hpcsched\FixedClock;
use Somethingwithproof\Hpcsched\FreshnessEvaluator;
use Somethingwithproof\Hpcsched\Tests\FakeRestd;
use Somethingwithproof\Hpcsched\Tests\Scratch;
use Somethingwithproof\Hpcsched\ViewRepository;

include_once($config['base_path'] . '/plugins/hpcsched/setup.php');
hpcsched_boot();
require_once($config['base_path'] . '/plugins/hpcsched/tests/Support/FakeRestd.php');

const FLOW_T     = 1800000000;
const FLOW_TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjE4MDAwMDM2MDAsInN1biI6ImNhY3RpcmVhZCJ9.Zmxvdy10ZXN0LXRva2Vu';

$flow_bin     = $config['base_path'] . '/plugins/hpcsched/tests/fixtures/bin';
$flow_scratch = new Scratch();
$flow_server  = new FakeRestd('plain');

try {
	foreach (glob($config['base_path'] . '/plugins/hpcsched/tests/fixtures/slurm/25.11/*.json') as $flow_file) {
		$flow_scratch->file(basename($flow_file), (string) file_get_contents($flow_file));
	}

	$flow_conf  = $flow_scratch->file('slurm.conf', "ok\n{$flow_scratch->dir}\n");
	$flow_token = $flow_scratch->file('token', FLOW_TOKEN . "\n", 0640);

	foreach (array('hpcsched_squeue' => 'squeue', 'hpcsched_scontrol' => 'scontrol', 'hpcsched_sshare' => 'sshare', 'hpcsched_prlimit' => 'prlimit') as $flow_name => $flow_bin_name) {
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($flow_name, $flow_bin . '/' . $flow_bin_name));
	}

	$flow_insert = 'INSERT INTO plugin_hpcsched_cluster (name, poller_id, admin_state, transport, collect_interval, slurm_conf, rest_url, rest_user, token_file, collect_reservations, collect_shares) VALUES (?, ?, ?, ?, 60, ?, ?, ?, ?, 1, 1)';

	db_execute_prepared($flow_insert, array('cmd', 1, 'enabled', 'commands', $flow_conf, '', '', ''));
	$flow_cmd = (int) db_fetch_insert_id();
	db_execute_prepared($flow_insert, array('elsewhere', 2, 'enabled', 'commands', $flow_conf, '', '', ''));
	db_execute_prepared($flow_insert, array('rest', 1, 'enabled', 'rest', '', $flow_server->url(), 'ok', $flow_token));
	$flow_rest = (int) db_fetch_insert_id();
	db_execute_prepared($flow_insert, array('off', 1, 'disabled', 'commands', $flow_conf, '', '', ''));

	$flow_clock    = new FixedClock(FLOW_T);
	$flow_clusters = new ClusterRepository(false, $flow_clock);
	$flow_run      = function (int $id) use ($flow_clock, $flow_clusters) {
		$run = hpcsched_reader(hpcsched_settings(), 'flow', $flow_clock)->run($flow_clusters->get($id), 60);

		return array('outcome' => $run->outcome->value, 'error' => $run->error === null ? null : $run->error->value, 'calls' => $run->calls, 'release' => $run->release, 'limited' => $run->memoryLimited, 'token' => $run->tokenState->value);
	};
	$flow_values = function (int $id) use ($flow_clock, $flow_clusters) {
		$view = new ViewRepository(false);
		$v    = ClusterValues::of($flow_clusters->get($id), $view->sections()[$id] ?? array(), $view->jobStates()[$id] ?? array(), $view->nodeStates()[$id] ?? array(), new FreshnessEvaluator($flow_clock), 60);

		return array('freshness' => $v->freshness->value, 'age' => $v->age, 'pending' => $v->values['pending'], 'held' => $v->values['held'], 'nodes' => $v->values['nodes_total'], 'drained' => $v->values['nodes_drained'], 'down' => $v->values['nodes_down']);
	};
	$flow_sections = function (int $id) {
		$out = array();

		foreach (db_fetch_assoc_prepared('SELECT section, state, error_class, observed_at FROM plugin_hpcsched_section WHERE cluster_id = ? ORDER BY section', array($id)) as $row) {
			$out[$row['section']] = array($row['state'], $row['error_class'], (int) $row['observed_at']);
		}

		return $out;
	};

	$result = array('due' => (new \Somethingwithproof\Hpcsched\Scheduler($flow_clusters, new \Somethingwithproof\Hpcsched\RunRepository(false, $flow_clock), $flow_clock))->due(1, 60) === array($flow_cmd, $flow_rest));

	// Client commands, every section.
	$result['cmd1']      = $flow_run($flow_cmd);
	$result['sections1'] = $flow_sections($flow_cmd);
	$result['values1']   = $flow_values($flow_cmd);
	$result['node_n03']  = db_fetch_row_prepared("SELECT state, raw_state, reason, partitions, observed_at, ingested_at, collect_interval FROM plugin_hpcsched_node WHERE cluster_id = ? AND name = 'n03'", array($flow_cmd));
	$result['shares']    = array_column(db_fetch_assoc_prepared('SELECT account FROM plugin_hpcsched_share WHERE cluster_id = ? ORDER BY account', array($flow_cmd)), 'account');
	$result['family']    = array_column(db_fetch_assoc_prepared("SELECT name, jobs FROM plugin_hpcsched_job_count WHERE cluster_id = ? AND partition_name = '' AND kind = 'family' ORDER BY name", array($flow_cmd)), 'jobs', 'name');
	$result['due_after'] = (new \Somethingwithproof\Hpcsched\Scheduler($flow_clusters, new \Somethingwithproof\Hpcsched\RunRepository(false, $flow_clock), $flow_clock))->due(1, 60) === array($flow_rest);

	// One section fails: its rows and their observation time stay.
	$flow_clock->advance(60);
	$flow_scratch->file('slurm.conf', "fail-jobs\n{$flow_scratch->dir}\n");
	$result['cmd2']      = $flow_run($flow_cmd);
	$result['sections2'] = $flow_sections($flow_cmd);
	$result['jobs2']     = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_hpcsched_job_count WHERE cluster_id = ? AND observed_at = ?", array($flow_cmd, FLOW_T));

	// The stopped reader: data ages into stale, values read as unknown, age keeps rising.
	$flow_clock->advance(100);
	$result['values_delayed'] = $flow_values($flow_cmd);
	$flow_clock->advance(400);
	$result['values_stale'] = $flow_values($flow_cmd);

	// slurmrestd over loopback with a token file the group can read.
	$result['rest1']     = $flow_run($flow_rest);
	$result['requests1'] = array_column($flow_server->requests(), 'target');

	db_execute_prepared("UPDATE plugin_hpcsched_cluster SET rest_user = 'unauth' WHERE id = ?", array($flow_rest));
	$flow_clock->advance(60);
	$result['rest2']     = $flow_run($flow_rest);
	$result['nodes2']    = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hpcsched_node WHERE cluster_id = ?', array($flow_rest));

	// No token text anywhere in the database or the log (REQ-CRED-001).
	$flow_dump = '';

	foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE 'plugin\\_hpcsched\\_%' OR TABLE_NAME IN ('settings', 'poller_output', 'processes'))") as $flow_table) {
		$flow_dump .= json_encode(db_fetch_assoc('SELECT * FROM `' . $flow_table['t'] . '`'));
	}

	$flow_log = $config['base_path'] . '/log/cacti.log';
	$flow_dump .= is_file($flow_log) ? (string) file_get_contents($flow_log) : '';

	$result['token_found'] = strpos($flow_dump, 'Zmxvdy10ZXN0LXRva2Vu') !== false || strpos($flow_dump, FLOW_TOKEN) !== false;
} finally {
	$flow_server->stop();
	$flow_scratch->remove();
}
