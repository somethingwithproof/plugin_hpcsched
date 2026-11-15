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
 * Device flow against a real Cacti with the plugin installed and enabled:
 *
 *   - the template install is idempotent and never overwrites a data query
 *     file the plugin did not write (REQ-OBJ-001)
 *   - the reader collects the 25.11 fixtures from stand-in client commands
 *   - a cluster device is created with its graphs; the partition indexes,
 *     the script server answers, and, after a Cacti poll, the values in the
 *     RRD files match the fixtures (REQ-OBJ-002 to REQ-OBJ-004)
 *   - a partition added, renamed, and removed changes the indexes, and the
 *     removed one stops being polled while its graphs stay (ADR 0011)
 *   - hostile script server arguments answer U or nothing
 *   - deleting the device unmaps the cluster and keeps it
 *
 * Nothing contacts a cluster. The refusal check logs an error on purpose,
 * so CI sets this script's log aside. Disposable databases only. Must parse on
 * PHP 8.0. Exits non-zero on any failure.
 *
 *   HPCSCHED_TEST_DATABASE=disposable php plugins/hpcsched/tests/bin/device-flow.php
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
include_once($config['base_path'] . '/lib/rrd.php');

if (!hpcsched_boot()) {
	fwrite(STDERR, "The plugin did not boot\n");
	exit(1);
}

require_once($config['base_path'] . '/plugins/hpcsched/tests/Support/Scratch.php');

// As the script server does, so the scripts only define their functions.
$called_by_script_server = true;
include_once($config['base_path'] . '/plugins/hpcsched/scripts/ss_hpcsched_cluster.php');
include_once($config['base_path'] . '/plugins/hpcsched/scripts/ss_hpcsched_partitions.php');

$failures = 0;

function flow_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function flow_template_rows() {
	$out = array();

	foreach (array('host_template', 'host_template_graph', 'host_template_snmp_query', 'data_input', 'data_input_fields', 'data_template', 'data_template_data', 'data_template_rrd', 'graph_templates', 'graph_templates_graph', 'graph_templates_item', 'graph_template_input', 'graph_templates_gprint', 'snmp_query', 'snmp_query_graph', 'snmp_query_graph_rrd', 'snmp_query_graph_sv', 'snmp_query_graph_rrd_sv') as $table) {
		$out[$table] = (int) db_fetch_cell("SELECT COUNT(*) FROM `$table`");
	}

	return $out;
}

/**
 * name => value pairs of a "name:value name:value" answer.
 */
function flow_pairs($answer) {
	$out = array();

	foreach (preg_split('/\s+/', trim($answer)) as $pair) {
		list($name, $value) = array_pad(explode(':', $pair, 2), 2, '');
		$out[$name] = $value;
	}

	return $out;
}

/**
 * The last values Cacti wrote for a device, keyed by data source name, and
 * for a partition data source by "partition/name".
 */
function flow_rrd_values($host_id) {
	$out = array();

	foreach (db_fetch_assoc_prepared('SELECT id, snmp_index FROM data_local WHERE host_id = ?', array($host_id)) as $local) {
		$path = get_data_source_path($local['id'], true);

		if (!is_file($path)) {
			continue;
		}

		$lines = array();
		exec('rrdtool lastupdate ' . escapeshellarg($path), $lines);

		if (count($lines) < 3) {
			continue;
		}

		$names  = preg_split('/\s+/', trim($lines[0]));
		$values = preg_split('/\s+/', trim(substr($lines[2], strpos($lines[2], ':') + 1)));

		foreach ($names as $i => $name) {
			$out[($local['snmp_index'] === '' ? '' : $local['snmp_index'] . '/') . $name] = isset($values[$i]) ? $values[$i] : 'missing';
		}
	}

	return $out;
}

$scratch   = new \Somethingwithproof\Hpcsched\Tests\Scratch();
$installer = hpcsched_template_installer();
$target    = $config['base_path'] . '/resource/script_server/' . \Somethingwithproof\Hpcsched\TemplateInstaller::QUERY_FILE;
$host_id   = 0;

try {
	// The template install: current after install, and again creates nothing.
	$before = flow_template_rows();
	flow_expect($installer->isCurrent(), 'the templates and the query file are installed');
	flow_expect($installer->install() && flow_template_rows() === $before, 'installing the templates again changes no Cacti template row');

	// A query file the plugin did not write is left alone.
	$installer->uninstall();
	flow_expect(!is_file($target), 'uninstall removes the query file the plugin wrote');
	file_put_contents($target, "<interface>site copy</interface>\n");
	flow_expect(!$installer->install() && file_get_contents($target) === "<interface>site copy</interface>\n" && $installer->failure() !== '' && $installer->queryFileProblem() !== null, 'a query file the plugin did not write is never overwritten, and the reason is kept');
	unlink($target);
	flow_expect($installer->install() && $installer->isCurrent() && $installer->failure() === '', 'the install succeeds once the foreign file is gone');

	// Collect the 25.11 fixtures through the stand-in client commands.
	foreach (glob($config['base_path'] . '/plugins/hpcsched/tests/fixtures/slurm/25.11/*.json') as $file) {
		$scratch->file(basename($file), (string) file_get_contents($file));
	}

	$conf = $scratch->file('slurm.conf', "ok\n{$scratch->dir}\n");
	$bin  = $config['base_path'] . '/plugins/hpcsched/tests/fixtures/bin';

	foreach (array('hpcsched_squeue' => 'squeue', 'hpcsched_scontrol' => 'scontrol', 'hpcsched_sshare' => 'sshare', 'hpcsched_prlimit' => 'prlimit') as $name => $command) {
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $bin . '/' . $command));
	}

	// The Cacti poll below also starts the reader for every enabled
	// cluster; earlier checks may have left some with nothing to reach.
	$others = array_map('intval', array_column(db_fetch_assoc("SELECT id FROM plugin_hpcsched_cluster WHERE admin_state = 'enabled'"), 'id'));

	foreach ($others as $other) {
		db_execute_prepared("UPDATE plugin_hpcsched_cluster SET admin_state = 'disabled' WHERE id = ?", array($other));
	}

	db_execute_prepared("INSERT INTO plugin_hpcsched_cluster (name, poller_id, admin_state, transport, collect_interval, slurm_conf, collect_reservations, collect_shares) VALUES ('flowc', 1, 'enabled', 'commands', 60, ?, 1, 1)", array($conf));
	$cluster_id = (int) db_fetch_insert_id();
	$clusters   = new \Somethingwithproof\Hpcsched\ClusterRepository(false, new \Somethingwithproof\Hpcsched\SystemClock());
	$collect    = function () use ($clusters, $cluster_id) {
		return hpcsched_reader(hpcsched_settings(), 'device-flow')->run($clusters->get($cluster_id), 60)->outcome->value;
	};

	flow_expect($collect() === 'completed', 'the reader collects the fixtures');

	// Create the device with its graphs.
	$provisioner = hpcsched_provisioner();
	$host_id     = $provisioner->create($clusters->get($cluster_id), true);
	$host        = db_fetch_row_prepared('SELECT hostname, availability_method, poller_id FROM host WHERE id = ?', array($host_id));
	$query_id    = (new \Somethingwithproof\Hpcsched\CactiObjectRepository(false))->queryId();

	flow_expect($host_id > 0 && $clusters->get($cluster_id)->hostId === $host_id, 'a device is created and mapped to the cluster');
	flow_expect(is_array($host) && $host['hostname'] === 'flowc.hpcsched.invalid' && (int) $host['availability_method'] === AVAIL_NONE && (int) $host['poller_id'] === 1, 'the device has a reserved host name, no availability check, and the cluster poller');
	flow_expect(array_column(db_fetch_assoc_prepared('SELECT snmp_index FROM host_snmp_cache WHERE host_id = ? AND snmp_query_id = ? AND field_name = ? ORDER BY snmp_index', array($host_id, $query_id, 'hpcPartition')), 'snmp_index') === array('batch', 'gpu'), 'the data query indexes the fixture partitions');
	flow_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id = ?', array($host_id)) === 16 && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM data_local WHERE host_id = ?', array($host_id)) === 16, 'six cluster and ten partition graphs, each with its own data source');

	$provisioner->attach($cluster_id, $host_id, true);
	flow_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id = ?', array($host_id)) === 16, 'adding missing graphs again adds none');

	// Script server answers.
	$cluster = flow_pairs(ss_hpcsched_cluster($host_id));
	flow_expect(count($cluster) === 33 && $cluster['pending'] === '63' && $cluster['held'] === '1' && $cluster['running'] === '2' && $cluster['r_assoc'] === '50' && $cluster['cpus_alloc'] === '72' && $cluster['cpus_total'] === '384' && $cluster['cpu_util_pct'] === '18.75' && $cluster['node_util_pct'] === '25' && $cluster['n_drained'] === '2' && is_numeric($cluster['age']), 'the cluster input answers the fixture values');
	flow_expect(ss_hpcsched_partitions($host_id, 'index') === "batch\ngpu\n" && ss_hpcsched_partitions($host_id, 'num_indexes') === '2' && ss_hpcsched_partitions($host_id, 'get', 'pending', 'batch') === '62' && ss_hpcsched_partitions($host_id, 'get', 'cpus_total', 'gpu') === '192', 'the partition query answers the fixture values');

	// Hostile or unknown arguments answer nothing usable, never a number.
	$hostile = array(
		ss_hpcsched_partitions($host_id, 'get', 'pending', '../../etc/passwd'),
		ss_hpcsched_partitions($host_id, 'get', 'system', 'batch'),
		ss_hpcsched_partitions($host_id, 'get', 'pending', "batch\nU"),
		ss_hpcsched_partitions('1 OR 1=1', 'get', 'pending', 'batch'),
		ss_hpcsched_partitions($host_id + 1000, 'get', 'pending', 'batch'),
	);
	flow_expect($hostile === array('U', 'U', 'U', 'U', 'U') && ss_hpcsched_partitions($host_id, 'query', 'nope') === '' && ss_hpcsched_partitions($host_id, 'drop') === '', 'hostile script server arguments answer U or nothing');
	flow_expect(array_unique(array_values(flow_pairs(ss_hpcsched_cluster($host_id + 1000)))) === array('U'), 'a device without a cluster answers U for every value');

	// A Cacti poll writes the values into the RRD files.
	exec('php poller.php --poller=1 --force 2>&1', $poll, $rc);
	$rrd = flow_rrd_values($host_id);
	flow_expect($rc === 0 && isset($rrd['pending'], $rrd['batch/pending'], $rrd['gpu/cpus_total']), 'the poll created the RRD files');
	flow_expect(isset($rrd['pending']) && (float) $rrd['pending'] === 63.0 && (float) $rrd['cpus_alloc'] === 72.0 && (float) $rrd['cpu_util_pct'] === 18.75 && (float) $rrd['batch/pending'] === 62.0 && (float) $rrd['gpu/cpus_total'] === 192.0 && (float) $rrd['batch/r_priority'] === 1.0 && is_numeric($rrd['age']), 'the RRD files hold the fixture values');

	// Partitions added, renamed, and removed reach the indexes.
	$partitions = json_decode((string) file_get_contents($scratch->dir . '/partitions.json'), true);
	$base       = $partitions['partitions'];
	$indexes    = function () use ($host_id, $query_id) {
		return (new \Somethingwithproof\Hpcsched\CactiObjectRepository(false))->indexes($host_id, $query_id);
	};
	$change     = function (array $names) use ($scratch, $partitions, $base, $collect, $provisioner, $clusters, $cluster_id) {
		$partitions['partitions'] = array();

		foreach ($names as $name) {
			$partition                  = $base[$name === 'gpu' ? 1 : 0];
			$partition['name']          = $name;
			$partitions['partitions'][] = $partition;
		}

		$scratch->file('partitions.json', json_encode($partitions));

		return $collect() === 'completed' && $provisioner->reindexIfChanged($clusters->get($cluster_id));
	};

	flow_expect($change(array('batch', 'debug', 'gpu')) && $indexes() === array('batch', 'debug', 'gpu'), 'an added partition is indexed');
	flow_expect($change(array('batch', 'devel', 'gpu')) && $indexes() === array('batch', 'devel', 'gpu'), 'a renamed partition is reindexed although the index count is the same');
	flow_expect(!$provisioner->reindexIfChanged($clusters->get($cluster_id)), 'no reindex runs while the partitions are unchanged');

	$gpu_graphs = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM graph_local WHERE host_id = ? AND snmp_index = 'gpu'", array($host_id));
	flow_expect($change(array('batch')) && $indexes() === array('batch'), 'a removed partition leaves the indexes');
	flow_expect((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM poller_item AS pi INNER JOIN data_local AS dl ON dl.id = pi.local_data_id WHERE dl.host_id = ? AND dl.snmp_index = 'gpu'", array($host_id)) === 0 && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM graph_local WHERE host_id = ? AND snmp_index = 'gpu'", array($host_id)) === $gpu_graphs && $gpu_graphs === 5, 'the removed partition is no longer polled and its graphs stay');

	// Deleting the device keeps the cluster and unmaps it.
	api_device_remove($host_id);
	$deleted = $host_id;
	$host_id = 0;
	flow_expect($clusters->get($cluster_id) !== null && $clusters->get($cluster_id)->hostId === 0 && $clusters->findByHost($deleted) === null, 'deleting the device keeps the cluster and unmaps it');
} finally {
	foreach (isset($others) ? $others : array() as $other) {
		db_execute_prepared("UPDATE plugin_hpcsched_cluster SET admin_state = 'enabled' WHERE id = ?", array($other));
	}

	if ($host_id > 0) {
		api_device_remove($host_id);
	}

	$scratch->remove();
}

exit($failures > 0 ? 1 : 0);
