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
 * The scheduler reader for one cluster, started by the poller_bottom hook:
 *
 *   php plugins/hpcsched/poller_hpcsched.php --cluster=N [--force] [--debug]
 *
 * One instance per cluster, through Cacti's process registry and the
 * cluster's lease (REQ-READ-001). It reads only a cluster assigned to the
 * poller it runs on (REQ-READ-002). --force runs even while the plugin is
 * disabled, for diagnosis from the command line. This file must parse on
 * PHP 8.0 and refuses to run below 8.2.
 */

chdir(__DIR__ . '/../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/plugins/hpcsched/setup.php');

ini_set('max_execution_time', '0');

// A large queue is decoded in memory; the limit is raised, never lowered.
if (function_exists('ini_parse_quantity') && (int) ini_get('memory_limit') !== -1 && ini_parse_quantity((string) ini_get('memory_limit')) < 1073741824) {
	ini_set('memory_limit', '1024M');
}

$hpcsched_cluster = 0;
$hpcsched_force   = false;

foreach (array_slice($_SERVER['argv'], 1) as $hpcsched_arg) {
	if (strpos($hpcsched_arg, '--cluster=') === 0) {
		$hpcsched_cluster = (int) substr($hpcsched_arg, 10);
	} elseif ($hpcsched_arg === '--force') {
		$hpcsched_force = true;
	} elseif ($hpcsched_arg === '--debug') {
		$debug = true;
	} else {
		fwrite(STDERR, 'ERROR: Unknown argument' . PHP_EOL . 'usage: poller_hpcsched.php --cluster=N [--force] [--debug]' . PHP_EOL);
		exit(1);
	}
}

if (hpcsched_refuse_unsupported()) {
	exit(1);
}

hpcsched_boot();

if (!$hpcsched_force && !api_plugin_is_enabled('hpcsched')) {
	exit(0);
}

if ($hpcsched_cluster < 1) {
	fwrite(STDERR, 'ERROR: --cluster=N is required' . PHP_EOL);
	exit(1);
}

if (!(new \Somethingwithproof\Hpcsched\Schema(false))->isCurrent()) {
	cacti_log('ERROR: The HPC scheduler reader did not run because the plugin schema is not current; open the Plugins page to finish the upgrade', false, 'HPCSCHED');
	exit(1);
}

$hpcsched_clock = new \Somethingwithproof\Hpcsched\SystemClock();

try {
	$hpcsched_row = (new \Somethingwithproof\Hpcsched\ClusterRepository(false, $hpcsched_clock))->get($hpcsched_cluster);
} catch (\Somethingwithproof\Hpcsched\DatabaseError $e) {
	cacti_log('ERROR: The HPC scheduler reader could not read cluster ' . $hpcsched_cluster, false, 'HPCSCHED');
	exit(1);
}

if ($hpcsched_row === null || !$hpcsched_row->enabled() || $hpcsched_row->pollerId !== (int) $config['poller_id']) {
	exit(0);
}

$hpcsched_interval = hpcsched_poller_interval();

if (!register_process_start('hpcsched', 'reader', $hpcsched_cluster, $hpcsched_row->budget($hpcsched_interval) + 120)) {
	cacti_log('NOTE: A reader for cluster ' . $hpcsched_cluster . ' is already running', false, 'HPCSCHED', POLLER_VERBOSITY_MEDIUM);
	exit(0);
}

try {
	$hpcsched_run = hpcsched_reader(hpcsched_settings(), '', $hpcsched_clock)->run($hpcsched_row, $hpcsched_interval);
} finally {
	unregister_process('hpcsched', 'reader', $hpcsched_cluster);
}

cacti_log(sprintf('STATS: Cluster:%d Outcome:%s Calls:%d Time:%.2f', $hpcsched_cluster, $hpcsched_run->outcome->value, $hpcsched_run->calls, (float) $hpcsched_run->duration), false, 'HPCSCHED', POLLER_VERBOSITY_MEDIUM);

exit(0);
