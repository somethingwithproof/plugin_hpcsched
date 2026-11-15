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
 * Build the HPC Scheduler templates in a disposable Cacti database with
 * Cacti's own save function, and write Cacti's export of them (get_item_xml
 * with dependencies) to templates/hpcsched-templates.xml. The plugin ships
 * that export, not this script's data:
 *
 *   HPCSCHED_TEST_DATABASE=disposable php plugins/hpcsched/tests/bin/build-templates.php [export-file]
 *
 * Hashes come from TemplateInstaller::hash(), so a rebuild updates the rows
 * it made and exports the same hashes. Must parse on PHP 8.0.
 *
 * Every value is a GAUGE with minimum 0: the scheduler reports counts and
 * the plugin keeps no counter. Percentages have maximum 100.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/functions.php');
require_once($config['base_path'] . '/lib/export.php');
require_once($config['base_path'] . '/plugins/hpcsched/setup.php');

if (getenv('HPCSCHED_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set HPCSCHED_TEST_DATABASE=disposable\n");
	exit(2);
}

if (!hpcsched_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

use Somethingwithproof\Hpcsched\ReasonFamily;
use Somethingwithproof\Hpcsched\Series;
use Somethingwithproof\Hpcsched\TemplateInstaller as T;

const HPCSCHED_GAUGE = 1;

/** Core's "Get Script Server Data (Indexed)" input and its three index fields. */
const HPCSCHED_INDEXED_INPUT  = '332111d8b54ac8ce939af87a7eac0c06';
const HPCSCHED_INDEXED_FIELDS = array('172b4b0eacee4948c6479f587b62e512', '30fb5d5bcf3d66bb5abe88596f357c26', '31112c85ae4ff821d3b288336288818c');

function tb_save($table, array $row) {
	$id = (int) db_fetch_cell_prepared("SELECT id FROM `$table` WHERE hash = ?", array($row['hash']));

	$row['id'] = $id > 0 ? $id : 0;
	$saved     = sql_save($row, $table);

	if (!$saved) {
		fwrite(STDERR, "Saving $table {$row['hash']} failed\n");
		exit(1);
	}

	return (int) $saved;
}

function tb_color($hex) {
	$hex = strtoupper($hex);
	$id  = (int) db_fetch_cell_prepared('SELECT id FROM colors WHERE hex = ?', array($hex));

	if ($id === 0) {
		db_execute_prepared('INSERT INTO colors (hex) VALUES (?)', array($hex));
		$id = (int) db_fetch_cell_prepared('SELECT id FROM colors WHERE hex = ?', array($hex));
	}

	return $id;
}

function tb_gprint($name, $text) {
	return tb_save('graph_templates_gprint', array('hash' => T::hash('gprint', $name), 'name' => $name, 'gprint_text' => $text));
}

/**
 * A data template with one GAUGE item per name.
 *
 * @param array<string, int> $items value name => input field id (0 for an indexed query)
 */
function tb_data_template($name, $inputId, $title, array $items, array $indexFields = array(), array $hostFields = array()) {
	$dtId  = tb_save('data_template', array('hash' => T::hash('data_template', $name), 'name' => $name));
	$dtdId = (int) db_fetch_cell_prepared('SELECT id FROM data_template_data WHERE data_template_id = ? AND local_data_id = 0', array($dtId));
	$dtdId = (int) sql_save(array(
		'id'                          => $dtdId,
		'data_template_id'            => $dtId,
		'local_data_id'               => 0,
		'local_data_template_data_id' => 0,
		'data_input_id'               => $inputId,
		'name'                        => $title,
		'active'                      => 'on',
		'rrd_step'                    => 300,
		'data_source_profile_id'      => 1,
	), 'data_template_data');

	// The index fields are set per data source when Cacti creates one from
	// the data query.
	foreach ($indexFields as $fieldId) {
		db_execute_prepared('REPLACE INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) VALUES (?, ?, ?, ?)', array($fieldId, $dtdId, 'on', ''));
	}

	// A field with a device type code gets its value from the device when
	// Cacti builds the poller cache, but only when the template has a row
	// for it, left empty and not overridable per data source.
	foreach ($hostFields as $fieldId) {
		db_execute_prepared('REPLACE INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) VALUES (?, ?, ?, ?)', array($fieldId, $dtdId, '', ''));
	}

	$rrd = array();

	foreach ($items as $item => $fieldId) {
		$rrd[$item] = tb_save('data_template_rrd', array(
			'hash'                => T::hash('data_template_item', $name . ':' . $item),
			'data_template_id'    => $dtId,
			'local_data_id'       => 0,
			'rrd_maximum'         => substr($item, -4) === '_pct' ? '100' : 'U',
			'rrd_minimum'         => '0',
			'rrd_heartbeat'       => 600,
			'data_source_type_id' => HPCSCHED_GAUGE,
			'data_source_name'    => $item,
			'data_input_field_id' => $fieldId,
		));
	}

	return array('id' => $dtId, 'rrd' => $rrd);
}

/**
 * A graph template from a list of items. Each item may carry legends: a
 * list of [GPRINT type, label] printed after it; the last legend ends the
 * line. An item with no rrd is a comment.
 *
 * @param list<array<string, mixed>> $items
 */
function tb_graph_template($name, $title, $label, array $items, $upper = '', $rigid = false) {
	$gtId  = tb_save('graph_templates', array('hash' => T::hash('graph_template', $name), 'name' => $name));
	$gtgId = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0', array($gtId));

	sql_save(array(
		'id'                            => $gtgId,
		'graph_template_id'             => $gtId,
		'local_graph_id'                => 0,
		'local_graph_template_graph_id' => 0,
		'image_format_id'               => 1,
		't_title'                       => '',
		'title'                         => $title,
		'height'                        => 150,
		'width'                         => 600,
		'upper_limit'                   => $upper === '' ? '100' : $upper,
		'lower_limit'                   => '0',
		'vertical_label'                => $label,
		'auto_scale'                    => $rigid ? '' : 'on',
		'auto_scale_opts'               => 2,
		'auto_scale_rigid'              => $rigid ? 'on' : '',
		'base_value'                    => 1000,
		'slope_mode'                    => 'on',
	), 'graph_templates_graph');

	db_execute_prepared('DELETE FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0', array($gtId));

	$sequence = 0;
	$first    = 0;

	$add = function (array $row) use (&$sequence, $gtId, $name) {
		$sequence++;

		return tb_save('graph_templates_item', $row + array(
			'hash'                      => T::hash('graph_template_item', $name . ':' . $sequence),
			'graph_template_id'         => $gtId,
			'local_graph_id'            => 0,
			'task_item_id'              => 0,
			'color_id'                  => 0,
			'alpha'                     => 'FF',
			'graph_type_id'             => GRAPH_ITEM_TYPE_COMMENT,
			'cdef_id'                   => 0,
			'consolidation_function_id' => 1,
			'text_format'               => '',
			'value'                     => '',
			'hard_return'               => '',
			'gprint_id'                 => 0,
			'sequence'                  => $sequence,
		));
	};

	foreach ($items as $item) {
		if (!isset($item['rrd'])) {
			$add(array('text_format' => $item['text'], 'hard_return' => 'on'));

			continue;
		}

		$legends = isset($item['legends']) ? $item['legends'] : array();
		$id      = $add(array(
			'task_item_id'  => $item['rrd'],
			'graph_type_id' => $item['type'],
			'color_id'      => isset($item['color']) ? tb_color($item['color']) : 0,
			'text_format'   => $item['text'],
			'gprint_id'     => in_array($item['type'], array(GRAPH_ITEM_TYPE_GPRINT_LAST, GRAPH_ITEM_TYPE_GPRINT_AVERAGE, GRAPH_ITEM_TYPE_GPRINT_MAX, GRAPH_ITEM_TYPE_GPRINT_MIN), true) ? $item['gprint'] : 0,
			'hard_return'   => $legends === array() ? 'on' : '',
		));

		$first = $first === 0 && $item['type'] !== GRAPH_ITEM_TYPE_GPRINT_LAST ? $id : $first;

		foreach ($legends as $i => $legend) {
			$add(array(
				'task_item_id'  => $item['rrd'],
				'graph_type_id' => $legend[0],
				'text_format'   => $legend[1],
				'gprint_id'     => $item['gprint'],
				'hard_return'   => $i === count($legends) - 1 ? 'on' : '',
			));
		}
	}

	// One graph template input, for the first item's color: Cacti 1.2.32
	// and later refuse a graph template export with no <inputs> element.
	$inputId = tb_save('graph_template_input', array(
		'hash'              => T::hash('graph_template_input', $name . ':color'),
		'graph_template_id' => $gtId,
		'name'              => 'First Item Color',
		'description'       => 'The color of the first item',
		'column_name'       => 'color_id',
	));

	db_execute_prepared('DELETE FROM graph_template_input_defs WHERE graph_template_input_id = ?', array($inputId));
	db_execute_prepared('INSERT INTO graph_template_input_defs (graph_template_input_id, graph_template_item_id) VALUES (?, ?)', array($inputId, $first));

	return $gtId;
}

$gCount = tb_gprint('HPC Scheduler Count', '%8.0lf');
$gPct   = tb_gprint('HPC Scheduler Percent', '%5.1lf %%');
$gSec   = tb_gprint('HPC Scheduler Seconds', '%8.0lf s');

$last = array(array(GRAPH_ITEM_TYPE_GPRINT_LAST, 'Current:'), array(GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'Average:'), array(GRAPH_ITEM_TYPE_GPRINT_MAX, 'Maximum:'));

$palette = array(
	'pending' => 'F39C12', 'held' => '8E44AD', 'running' => '27AE60', 'completing' => '2E86C1', 'jobs_other' => '95A5A6',
	'n_idle' => '52BE80', 'n_allocated' => '2471A3', 'n_mixed' => '5DADE2', 'n_drained' => 'E67E22', 'n_down' => 'C0392B', 'n_maint' => '7D3C98', 'n_powered_down' => '566573', 'n_other' => 'BDC3C7',
	'cpus_alloc' => '2471A3', 'cpus_idle' => 'A9DFBF', 'cpus_other' => 'E6B0AA', 'cpus_total' => '17202A',
	'cpu_util_pct' => '2471A3', 'node_util_pct' => '27AE60',
);
$reasonColors = array('1F77B4', 'FF7F0E', '2CA02C', 'D62728', '9467BD', '8C564B', 'E377C2', '7F7F7F', 'BCBD22', '17BECF', '393B79');

foreach (ReasonFamily::cases() as $i => $family) {
	$palette['r_' . $family->value] = $reasonColors[$i % count($reasonColors)];
}

$labels = array(
	'pending' => 'Pending (eligible)', 'held' => 'Held', 'running' => 'Running', 'completing' => 'Completing', 'jobs_other' => 'Other',
	'n_idle' => 'Idle', 'n_allocated' => 'Allocated', 'n_mixed' => 'Mixed', 'n_drained' => 'Drained or draining', 'n_down' => 'Down', 'n_maint' => 'Maintenance or reserved', 'n_powered_down' => 'Powered down', 'n_other' => 'Other',
	'cpus_alloc' => 'Allocated', 'cpus_idle' => 'Idle', 'cpus_other' => 'Unavailable', 'cpus_total' => 'Total',
	'cpu_util_pct' => 'CPUs allocated', 'node_util_pct' => 'Nodes with allocated CPUs', 'nodes_alloc' => 'Nodes with allocated CPUs', 'nodes_total' => 'Nodes',
);

foreach (ReasonFamily::cases() as $family) {
	$labels['r_' . $family->value] = $family->label();
}

/**
 * The items of one group's graph: counts stacked, the CPU total as a line,
 * utilization as lines with node counts printed, the denominator rule as a
 * comment (REQ-NODEAGG-005).
 *
 * @param array<string, int> $rrd value name => data template item id
 */
function tb_group_items($group, array $rrd) {
	global $palette, $labels, $last, $gCount, $gPct;

	$items = array();

	if ($group === 'Utilization') {
		$items[] = array('rrd' => $rrd['cpu_util_pct'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => $palette['cpu_util_pct'], 'text' => $labels['cpu_util_pct'], 'gprint' => $gPct, 'legends' => $last);
		$items[] = array('rrd' => $rrd['node_util_pct'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => $palette['node_util_pct'], 'text' => $labels['node_util_pct'], 'gprint' => $gPct, 'legends' => $last);
		$items[] = array('rrd' => $rrd['nodes_alloc'], 'type' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text' => 'Nodes with allocated CPUs now', 'gprint' => $gCount);
		$items[] = array('rrd' => $rrd['nodes_total'], 'type' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text' => 'of', 'gprint' => $gCount);
		$items[] = array('text' => 'Allocated over all CPUs and nodes, down, drained, and maintenance ones included, as sinfo counts them');

		return $items;
	}

	$stackFirst = true;

	foreach ($rrd as $name => $id) {
		if ($name === 'cpus_total') {
			$items[] = array('rrd' => $id, 'type' => GRAPH_ITEM_TYPE_LINE1, 'color' => $palette[$name], 'text' => $labels[$name], 'gprint' => $gCount, 'legends' => array(array(GRAPH_ITEM_TYPE_GPRINT_LAST, 'Current:')));

			continue;
		}

		$items[] = array('rrd' => $id, 'type' => $stackFirst ? GRAPH_ITEM_TYPE_AREA : GRAPH_ITEM_TYPE_STACK, 'color' => $palette[$name], 'text' => $labels[$name], 'gprint' => $gCount, 'legends' => $last);
		$stackFirst = false;
	}

	if ($group === 'CPUs') {
		$items[] = array('text' => 'Unavailable is the unallocated CPUs of nodes that are down, drained, draining, in maintenance, or powered down');
	}

	if ($group === 'Pending Reasons') {
		$items[] = array('text' => 'Each job under the last reason the scheduler gave it; eligible and held jobs both count');
	}

	return $items;
}

$vertical = array('Jobs' => 'jobs', 'Pending Reasons' => 'jobs', 'Nodes' => 'nodes', 'CPUs' => 'CPUs', 'Utilization' => 'percent');

/* ---- cluster data input, data template, and graphs ---- */

$inputId = tb_save('data_input', array(
	'hash'         => T::hash('data_input', T::CLUSTER),
	'name'         => T::CLUSTER,
	'input_string' => '<path_cacti>/plugins/hpcsched/scripts/ss_hpcsched_cluster.php ss_hpcsched_cluster <host_id>',
	'type_id'      => DATA_INPUT_TYPE_PHP_SCRIPT_SERVER,
));

$field = function ($dataName, $friendly, $inOut, $sequence, $typeCode = '') use ($inputId) {
	return tb_save('data_input_fields', array(
		'hash'          => T::hash('data_input_field', T::CLUSTER . ':' . $dataName),
		'data_input_id' => $inputId,
		'name'          => $friendly,
		'data_name'     => $dataName,
		'input_output'  => $inOut,
		'update_rra'    => $inOut === 'out' ? 'on' : '',
		'sequence'      => $sequence,
		'type_code'     => $typeCode,
		'regexp_match'  => '',
		'allow_nulls'   => '',
	));
};

$hostField    = $field('host_id', 'Device ID', 'in', 0, 'host_id');
$clusterItems = array();

foreach (array_merge(Series::names(), array(Series::AGE)) as $i => $name) {
	$clusterItems[$name] = $field($name, $name === Series::AGE ? 'Snapshot age (seconds)' : $labels[$name], 'out', $i + 1);
}

$cluster = tb_data_template(T::CLUSTER, $inputId, '|host_description| - HPC Scheduler Cluster', $clusterItems, array(), array($hostField));
$overview = array();

foreach (Series::groups() as $group => $names) {
	$overview[] = tb_graph_template(T::overview()[$group], '|host_description| - ' . $group, $vertical[$group], tb_group_items($group, array_intersect_key($cluster['rrd'], array_flip($names))), $group === 'Utilization' ? '100' : '', $group === 'Utilization');
}

$overview[] = tb_graph_template(T::AGE_GRAPH, '|host_description| - Snapshot Age', 'seconds', array(
	array('rrd' => $cluster['rrd'][Series::AGE], 'type' => GRAPH_ITEM_TYPE_AREA, 'color' => '5D6D7E', 'text' => 'Age of the oldest section', 'gprint' => $gSec, 'legends' => $last),
	array('text' => 'Grows while the reader is stopped; alert on it with a duration of at least one collection interval'),
));

/* ---- partition data templates, data query, and graphs ---- */

$indexedInput  = (int) db_fetch_cell_prepared('SELECT id FROM data_input WHERE hash = ?', array(HPCSCHED_INDEXED_INPUT));
$indexedFields = array();

foreach (HPCSCHED_INDEXED_FIELDS as $hash) {
	$indexedFields[] = (int) db_fetch_cell_prepared('SELECT id FROM data_input_fields WHERE hash = ?', array($hash));
}

if ($indexedInput === 0 || in_array(0, $indexedFields, true)) {
	fwrite(STDERR, "Cacti's script server indexed input is missing\n");
	exit(1);
}

$queryId = tb_save('snmp_query', array(
	'hash'          => T::hash('data_query', T::QUERY),
	'xml_path'      => '<path_cacti>/resource/script_server/' . T::QUERY_FILE,
	'name'          => T::QUERY,
	'description'   => 'Partitions of a scheduler cluster, from the HPC Scheduler plugin snapshot',
	'data_input_id' => $indexedInput,
));

foreach (Series::groups() as $group => $names) {
	$name     = T::partitionGraphs()[$group];
	$template = tb_data_template($name, $indexedInput, '|host_description| - |query_hpcPartition| - ' . $group, array_fill_keys($names, 0), $indexedFields);
	$graphId  = tb_graph_template($name, '|host_description| - |query_hpcPartition| - ' . $group, $vertical[$group], tb_group_items($group, $template['rrd']), $group === 'Utilization' ? '100' : '', $group === 'Utilization');

	$sqgId = tb_save('snmp_query_graph', array(
		'hash'              => T::hash('data_query_graph', T::QUERY . ':' . $group),
		'snmp_query_id'     => $queryId,
		'name'              => 'Partition ' . $group,
		'graph_template_id' => $graphId,
	));

	// Every item of the graph's data template maps to the query field of
	// the same name.
	db_execute_prepared('DELETE FROM snmp_query_graph_rrd WHERE snmp_query_graph_id = ?', array($sqgId));

	foreach ($template['rrd'] as $item => $rrdId) {
		db_execute_prepared('INSERT INTO snmp_query_graph_rrd (snmp_query_graph_id, data_template_id, data_template_rrd_id, snmp_field_name) VALUES (?, ?, ?, ?)', array($sqgId, $template['id'], $rrdId, $item));
	}

	tb_save('snmp_query_graph_sv', array(
		'hash'                => T::hash('data_query_sv_graph', T::QUERY . ':' . $group . ':title'),
		'snmp_query_graph_id' => $sqgId,
		'sequence'            => 1,
		'field_name'          => 'title',
		'text'                => '|host_description| - |query_hpcPartition| - ' . $group,
	));

	tb_save('snmp_query_graph_rrd_sv', array(
		'hash'                => T::hash('data_query_sv_data_source', T::QUERY . ':' . $group . ':name'),
		'snmp_query_graph_id' => $sqgId,
		'data_template_id'    => $template['id'],
		'sequence'            => 1,
		'field_name'          => 'name',
		'text'                => '|host_description| - |query_hpcPartition| - ' . $group,
	));
}

/* ---- device template ---- */

$htId = tb_save('host_template', array('hash' => T::hash('host_template', T::DEVICE_TEMPLATE), 'name' => T::DEVICE_TEMPLATE));

db_execute_prepared('DELETE FROM host_template_graph WHERE host_template_id = ?', array($htId));
db_execute_prepared('DELETE FROM host_template_snmp_query WHERE host_template_id = ?', array($htId));

foreach ($overview as $gtId) {
	db_execute_prepared('INSERT INTO host_template_graph (host_template_id, graph_template_id) VALUES (?, ?)', array($htId, $gtId));
}

// The reindex method is set per device when the provisioner attaches the
// query; 1.2.31's host_template_snmp_query has no column for it.
db_execute_prepared('INSERT INTO host_template_snmp_query (host_template_id, snmp_query_id) VALUES (?, ?)', array($htId, $queryId));

$xml = get_item_xml('host_template', $htId, true);

// The export follows the data source profile the data templates use. The
// plugin must not overwrite a site's profile, so the shipped file leaves it
// out and import resolves the profile by its hash, which is Cacti's stock one.
$xml = preg_replace('#\s*<hash_2001[0-9a-f]{34}>.*?</hash_2001[0-9a-f]{34}>#s', '', $xml, -1, $removed);

if ($removed !== 1) {
	fwrite(STDERR, "Expected one data source profile in the export, found $removed\n");
	exit(1);
}

// Cacti stores "no right axis format" as NULL or 0 depending on how the row
// was written, and exports them as '' or 0. Both mean none; writing it empty
// keeps the export the same whether it is built on an empty database or
// rebuilt after an import.
$xml = str_replace('<right_axis_format>0</right_axis_format>', '<right_axis_format></right_axis_format>', $xml);

$file = isset($argv[1]) ? $argv[1] : $config['base_path'] . '/plugins/hpcsched/templates/hpcsched-templates.xml';

file_put_contents($file, $xml);
print "Wrote $file\n";
