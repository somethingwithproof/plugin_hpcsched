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
 * Cost of the reader, the script server answers, and the plugin pages on
 * synthetic snapshots (#47): 1,000 and 10,000 nodes, 10,000 and 100,000
 * jobs, 8 partitions. The snapshots have the shape of the 25.11 fixtures
 * (only the fields the plugin reads, so real squeue answers are larger),
 * and go through the stand-in client commands, so the reader time includes
 * starting each command, decoding, tallying, and the database write.
 *
 * The script server figure is one poll cycle of a device with every
 * partition graphed: the cluster input once per cluster data source and
 * one get per partition value, in one process as the script server runs
 * them. Each figure is the median of three runs. Times depend on the
 * machine and database; the script prints what it ran on.
 *
 * Needs the plugin installed and enabled with its templates, and a
 * disposable database. Must parse on PHP 8.0.
 *
 *   HPCSCHED_TEST_DATABASE=disposable php plugins/hpcsched/tests/bin/perf.php
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

require_once($config['base_path'] . '/plugins/hpcsched/tests/Support/Scratch.php');

const PERF_PARTITIONS = array('batch', 'gpu', 'debug', 'long', 'short', 'himem', 'interactive', 'preempt');
const PERF_REASONS    = array('Priority', 'Priority', 'Priority', 'Resources', 'Resources', 'Dependency', 'BeginTime', 'AssocGrpCpuLimit', 'AssocMaxJobsLimit', 'QOSMaxJobsPerUserLimit', 'QOSGrpCpuLimit', 'PartitionTimeLimit', 'ReqNodeNotAvail, UnavailableNodes:n[0001-0004]', 'ReservationTime', 'JobArrayTaskLimit', 'JobHeldUser', 'JobHeldAdmin');
const PERF_NODE_STATES = array(array('IDLE'), array('ALLOCATED'), array('ALLOCATED'), array('MIXED'), array('MIXED'), array('IDLE', 'DRAIN'), array('MIXED', 'DRAIN'), array('DOWN'), array('DOWN', 'DRAIN'), array('IDLE', 'MAINTENANCE', 'RESERVED'), array('IDLE', 'POWERED_DOWN'), array('FUTURE'));

function perf_jobs($count) {
	$jobs = array();

	for ($i = 0; $i < $count; $i++) {
		$roll  = mt_rand(0, 99);
		$state = $roll < 35 ? array('RUNNING') : ($roll < 38 ? array('RUNNING', 'COMPLETING') : array('PENDING'));
		$parts = PERF_PARTITIONS[mt_rand(0, count(PERF_PARTITIONS) - 1)];

		if (mt_rand(0, 19) === 0) {
			$parts .= ',' . PERF_PARTITIONS[mt_rand(0, count(PERF_PARTITIONS) - 1)];
		}

		$pending = $state === array('PENDING');
		$jobs[]  = array(
			'job_id'            => 100000 + $i,
			'name'              => 'job-' . $i,
			'user_name'         => 'user' . ($i % 500),
			'account'           => 'acct' . ($i % 40),
			'job_state'         => $state,
			'state_reason'      => $pending ? PERF_REASONS[mt_rand(0, count(PERF_REASONS) - 1)] : 'None',
			'partition'         => $parts,
			'array_task_string' => $pending && mt_rand(0, 49) === 0 ? '0-99%10' : '',
			'array_task_id'     => array('set' => true, 'infinite' => false, 'number' => 0),
		);
	}

	return array('jobs' => $jobs, 'last_backfill' => array('set' => true, 'infinite' => false, 'number' => time()), 'last_update' => array('set' => true, 'infinite' => false, 'number' => time()), 'meta' => array(), 'errors' => array(), 'warnings' => array());
}

function perf_nodes($count) {
	$nodes = array();

	for ($i = 0; $i < $count; $i++) {
		$name       = sprintf('n%05d', $i);
		$state      = PERF_NODE_STATES[mt_rand(0, count(PERF_NODE_STATES) - 1)];
		$partitions = array(PERF_PARTITIONS[$i % count(PERF_PARTITIONS)]);

		if ($i % 10 === 0) {
			$partitions[] = PERF_PARTITIONS[($i + 3) % count(PERF_PARTITIONS)];
		}

		$alloc   = in_array('ALLOCATED', $state, true) ? 64 : (in_array('MIXED', $state, true) ? mt_rand(1, 63) : 0);
		$nodes[] = array('name' => $name, 'hostname' => $name, 'address' => '10.' . intdiv($i, 65536) . '.' . intdiv($i % 65536, 256) . '.' . ($i % 256), 'state' => $state, 'reason' => in_array('DRAIN', $state, true) ? 'maintenance' : '', 'reason_changed_at' => array('set' => true, 'infinite' => false, 'number' => 0), 'partitions' => $partitions, 'cpus' => 64, 'alloc_cpus' => $alloc);
	}

	return array('nodes' => $nodes, 'last_update' => array('set' => true, 'infinite' => false, 'number' => time()), 'meta' => array(), 'errors' => array(), 'warnings' => array());
}

function perf_partitions($nodes) {
	$out = array();

	foreach (PERF_PARTITIONS as $name) {
		$out[] = array('name' => $name, 'partition' => array('state' => array('UP')), 'nodes' => array('total' => intdiv($nodes, count(PERF_PARTITIONS)), 'configured' => ''), 'cpus' => array('total' => 64 * intdiv($nodes, count(PERF_PARTITIONS))));
	}

	return array('partitions' => $out, 'last_update' => array('set' => true, 'infinite' => false, 'number' => time()), 'meta' => array(), 'errors' => array(), 'warnings' => array());
}

/**
 * The median wall time of three runs of $work, in milliseconds.
 */
function perf_time($work) {
	$times = array();

	for ($i = 0; $i < 3; $i++) {
		$start = hrtime(true);
		$work();
		$times[] = (hrtime(true) - $start) / 1e6;
	}

	sort($times);

	return $times[1];
}

function perf_render($work) {
	ob_start();
	$work();

	return strlen((string) ob_get_clean());
}

mt_srand(47);

$cpus = (int) trim((string) shell_exec('nproc 2>/dev/null'));
printf("PHP %s on %s %s, %d CPUs, %s %s\n\n", PHP_VERSION, php_uname('s'), php_uname('m'), $cpus, db_fetch_cell('SELECT @@version_comment'), db_fetch_cell('SELECT VERSION()'));
print "| Nodes | Jobs | Answer MiB (jobs, nodes) | Parse ms | Reader run ms | Rows written | Reader peak MiB | Script server cycle ms (calls) | Overview ms | Reasons view ms |\n";
print "|---|---|---|---|---|---|---|---|---|---|\n";

$bin       = $config['base_path'] . '/plugins/hpcsched/tests/fixtures/bin';
$fixtures  = $config['base_path'] . '/plugins/hpcsched/tests/fixtures/slurm/25.11';
$failures  = 0;
$hosts     = array();
$created   = array();

foreach (array('hpcsched_squeue' => 'squeue', 'hpcsched_scontrol' => 'scontrol', 'hpcsched_sshare' => 'sshare', 'hpcsched_prlimit' => 'prlimit') as $name => $command) {
	db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $bin . '/' . $command));
}

try {
	foreach (array(array(1000, 10000), array(1000, 100000), array(10000, 10000), array(10000, 100000)) as $size) {
		list($node_count, $job_count) = $size;

		$scratch = new \Somethingwithproof\Hpcsched\Tests\Scratch();
		$jobs    = json_encode(perf_jobs($job_count));
		$nodes   = json_encode(perf_nodes($node_count));

		$scratch->file('jobs.json', $jobs);
		$scratch->file('nodes.json', $nodes);
		$scratch->file('partitions.json', json_encode(perf_partitions($node_count)));

		foreach (array('reservations.json', 'shares.json') as $file) {
			$scratch->file($file, (string) file_get_contents($fixtures . '/' . $file));
		}

		$conf = $scratch->file('slurm.conf', "ok\n{$scratch->dir}\n");
		$name = 'perf' . $node_count . 'x' . $job_count;

		db_execute_prepared("INSERT INTO plugin_hpcsched_cluster (name, poller_id, admin_state, transport, collect_interval, slurm_conf, collect_reservations, collect_shares) VALUES (?, 1, 'enabled', 'commands', 300, ?, 0, 0)", array($name, $conf));
		$cluster_id = (int) db_fetch_insert_id();
		$created[]  = $cluster_id;
		$clusters   = new \Somethingwithproof\Hpcsched\ClusterRepository(false, new \Somethingwithproof\Hpcsched\SystemClock());

		$parse = perf_time(function () use ($jobs, $nodes) {
			\Somethingwithproof\Hpcsched\SlurmParser::jobs(json_decode($jobs, true));
			\Somethingwithproof\Hpcsched\SlurmParser::nodes(json_decode($nodes, true));
		});

		$outcome = '';

		if (function_exists('memory_reset_peak_usage')) {
			memory_reset_peak_usage();
		}

		$base = memory_get_usage();
		$run  = perf_time(function () use ($clusters, $cluster_id, &$outcome) {
			$outcome = hpcsched_reader(hpcsched_settings(), 'perf')->run($clusters->get($cluster_id), 300)->outcome->value;
		});
		$peak = (memory_get_peak_usage() - $base) / 1048576;

		if ($outcome !== 'completed') {
			print "FAIL $name: the reader run ended $outcome\n";
			$failures++;
		}

		$rows = 0;

		foreach (array('plugin_hpcsched_job_count', 'plugin_hpcsched_node', 'plugin_hpcsched_node_count', 'plugin_hpcsched_partition') as $table) {
			$rows += (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM `$table` WHERE cluster_id = ?", array($cluster_id));
		}

		$host_id = hpcsched_provisioner()->create($clusters->get($cluster_id), true);
		$hosts[] = $host_id;
		$calls   = 0;
		$cycle   = perf_time(function () use ($host_id, &$calls) {
			// A fresh answerer per cycle, as each poll starts a new script server.
			$series = hpcsched_cluster_series();
			$calls  = 0;

			for ($i = 0; $i < count(\Somethingwithproof\Hpcsched\TemplateInstaller::overview()); $i++) {
				$series->cluster($host_id);
				$calls++;
			}

			foreach (PERF_PARTITIONS as $partition) {
				foreach (\Somethingwithproof\Hpcsched\Series::names() as $field) {
					if ($series->partitions($host_id, 'get', $field, $partition) === 'U') {
						throw new RuntimeException("U for $partition $field");
					}

					$calls++;
				}
			}
		});

		$clock    = new \Somethingwithproof\Hpcsched\SystemClock();
		$access   = new \Somethingwithproof\Hpcsched\DeviceAccess(function ($id) {
			return true;
		});
		$overview = new \Somethingwithproof\Hpcsched\OverviewPage($clusters, new \Somethingwithproof\Hpcsched\ViewRepository(false), new \Somethingwithproof\Hpcsched\FreshnessEvaluator($clock), $clock, 300, $access, true);
		$page     = perf_time(function () use ($overview) {
			perf_render(function () use ($overview) {
				$overview->render();
			});
		});
		$reasons  = perf_time(function () use ($overview, $clusters, $cluster_id) {
			perf_render(function () use ($overview, $clusters, $cluster_id) {
				$overview->renderReasons($clusters->get($cluster_id));
			});
		});

		printf("| %s | %s | %.1f, %.1f | %.0f | %.0f | %s | %.0f | %.0f (%d) | %.0f | %.0f |\n", number_format($node_count), number_format($job_count), strlen($jobs) / 1048576, strlen($nodes) / 1048576, $parse, $run, number_format($rows), $peak, $cycle, $calls, $page, $reasons);

		$scratch->remove();
	}
} finally {
	foreach ($hosts as $host) {
		if ($host > 0) {
			api_device_remove($host);
		}
	}

	// Through the repository, so the clusters' snapshot rows go too.
	foreach ($created as $id) {
		(new \Somethingwithproof\Hpcsched\ClusterRepository(false, new \Somethingwithproof\Hpcsched\SystemClock()))->delete($id);
	}
}

exit($failures > 0 ? 1 : 0);
