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
 * Reader restart scenarios, included by tests/bin/lifecycle.php:
 *
 *   reader_kill    a run killed while reading leaves the previous snapshot,
 *                  and the next run continues once the lease expires
 *   reader_victim  the run that is killed (started by reader_kill)
 *   reader_second  a second reader start for the same cluster exits at once
 *
 * The client command stand-ins under tests/fixtures/bin read their scenario
 * from the file the cluster's slurm.conf path names. Sets $result.
 */

use Somethingwithproof\Hpcsched\ClusterRepository;
use Somethingwithproof\Hpcsched\FixedClock;
use Somethingwithproof\Hpcsched\Tests\Scratch;

include_once($config['base_path'] . '/plugins/hpcsched/setup.php');
hpcsched_boot();
require_once($config['base_path'] . '/plugins/hpcsched/tests/Support/Scratch.php');

const RESTART_TIME = 1800000000;
const RESTART_DIR  = '/tmp/hpcsched-restart';

function restart_seed(string $scenario): int {
	global $config;

	if (!is_dir(RESTART_DIR)) {
		mkdir(RESTART_DIR, 0700);
	}

	foreach (glob($config['base_path'] . '/plugins/hpcsched/tests/fixtures/slurm/25.11/*.json') as $file) {
		copy($file, RESTART_DIR . '/' . basename($file));
	}

	file_put_contents(RESTART_DIR . '/slurm.conf', "$scenario\n" . RESTART_DIR . "\n");

	foreach (array('hpcsched_squeue' => 'squeue', 'hpcsched_scontrol' => 'scontrol', 'hpcsched_sshare' => 'sshare', 'hpcsched_prlimit' => 'prlimit') as $name => $bin) {
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $config['base_path'] . '/plugins/hpcsched/tests/fixtures/bin/' . $bin));
	}

	$id = (int) db_fetch_cell("SELECT id FROM plugin_hpcsched_cluster WHERE name = 'restart'");

	if ($id === 0) {
		db_execute_prepared("INSERT INTO plugin_hpcsched_cluster (name, poller_id, admin_state, transport, collect_interval, slurm_conf) VALUES ('restart', 1, 'enabled', 'commands', 60, ?)", array(RESTART_DIR . '/slurm.conf'));
		$id = (int) db_fetch_insert_id();
	}

	return $id;
}

function restart_cleanup(): void {
	foreach (glob(RESTART_DIR . '/*') as $file) {
		unlink($file);
	}

	if (is_dir(RESTART_DIR)) {
		rmdir(RESTART_DIR);
	}
}

function restart_reader(FixedClock $clock, int $id, string $holder): array {
	$run = hpcsched_reader(hpcsched_settings(), $holder, $clock)->run((new ClusterRepository(false, $clock))->get($id), 60);

	return array('outcome' => $run->outcome->value, 'calls' => $run->calls);
}

function restart_state(int $id): array {
	return array(
		'lease'    => db_fetch_row_prepared('SELECT holder, lease_expires FROM plugin_hpcsched_run WHERE cluster_id = ?', array($id)),
		'sections' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hpcsched_section WHERE cluster_id = ?', array($id)),
		'jobs'     => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hpcsched_job_count WHERE cluster_id = ?', array($id)),
		'nodes'    => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hpcsched_node WHERE cluster_id = ?', array($id)),
		'observed' => (int) db_fetch_cell_prepared("SELECT observed_at FROM plugin_hpcsched_section WHERE cluster_id = ? AND section = 'jobs'", array($id)),
	);
}

switch ($action) {
	case 'reader_second':
		$id = restart_seed('ok');

		// A live process holds the reader slot for the cluster. Cacti 1.2.x
		// treats a slot as held only when the holder runs the same script,
		// so the stand-in names poller_hpcsched.php on its command line.
		$script  = $config['base_path'] . '/plugins/hpcsched/poller_hpcsched.php';
		$holder  = proc_open(array(PHP_BINARY, '-r', 'sleep(60);', $script), array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
		$status  = proc_get_status($holder);
		register_process('hpcsched', 'reader', $id, $status['pid'], 300);
		$started = microtime(true);
		exec(escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg($script) . ' --cluster=' . $id . ' --force 2>&1', $lines, $rc);
		proc_terminate($holder, 9);
		proc_close($holder);
		unregister_process('hpcsched', 'reader', $id);
		$result = array('rc' => $rc, 'seconds' => round(microtime(true) - $started, 1), 'runs' => (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_hpcsched_run'));

		// Control: with the slot free, the same start does run.
		exec(escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg($script) . ' --cluster=' . $id . ' --force 2>&1', $lines, $rc);
		$result['rc_when_free']   = $rc;
		$result['outcome_free']   = db_fetch_cell_prepared('SELECT outcome FROM plugin_hpcsched_run WHERE cluster_id = ?', array($id));
		restart_cleanup();

		break;
	case 'reader_victim':
		// Killed outright by the node stand-in while the snapshot is being
		// read, as on an OOM kill or a reboot: no finally block runs.
		restart_reader(new FixedClock(RESTART_TIME + 60), restart_seed('kill-reader'), 'victim');

		break;
	case 'reader_kill':
		$id = restart_seed('ok');
		$result = array('first' => restart_reader(new FixedClock(RESTART_TIME), $id, 'first'));
		$result['before_kill'] = restart_state($id);

		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/lifecycle.php') . ' reader_victim 2>&1', $lines, $rc);
		$result['victim_rc']  = $rc;
		$result['after_kill'] = restart_state($id);

		restart_seed('ok');

		// Inside the dead run's lease, the next run does nothing.
		$clock = new FixedClock(RESTART_TIME + 90);
		$result['while_leased'] = restart_reader($clock, $id, 'next');

		// After it expires, the next run takes over.
		$clock->advance(200);
		$result['after_expiry'] = restart_reader($clock, $id, 'next');
		$result['final']        = restart_state($id);
		restart_cleanup();

		break;
}
