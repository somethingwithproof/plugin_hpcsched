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

use Somethingwithproof\Hpcsched\Series;
use Somethingwithproof\Hpcsched\TemplateInstaller;

/*
 * The shipped template export and data query file agree with the code that
 * finds and feeds them: every object the installer looks up by hash is in
 * the export, the data query's fields are exactly the partition series, and
 * every value the cluster input returns is stored (REQ-OBJ-001).
 */

function hpcsched_templates_xml(): string {
	return (string) file_get_contents(dirname(__DIR__, 2) . '/templates/hpcsched-templates.xml');
}

it('holds every object the installer finds by hash, with the type prefix Cacti gives it', function () {
	$xml = hpcsched_templates_xml();
	$expect = [
		'02' => [TemplateInstaller::hash('host_template', TemplateInstaller::DEVICE_TEMPLATE)],
		'03' => [TemplateInstaller::hash('data_input', TemplateInstaller::CLUSTER)],
		'01' => [TemplateInstaller::hash('data_template', TemplateInstaller::CLUSTER)],
		'04' => [TemplateInstaller::hash('data_query', TemplateInstaller::QUERY)],
		'00' => [],
	];

	foreach (TemplateInstaller::overview() as $name) {
		$expect['00'][] = TemplateInstaller::hash('graph_template', $name);
	}

	foreach (TemplateInstaller::partitionGraphs() as $name) {
		$expect['00'][] = TemplateInstaller::hash('graph_template', $name);
		$expect['01'][] = TemplateInstaller::hash('data_template', $name);
	}

	foreach ($expect as $type => $hashes) {
		foreach ($hashes as $hash) {
			expect($xml)->toContain('<hash_' . $type . '0103' . $hash . '>');
		}
	}

	// Core's data source profile is referenced, never shipped.
	expect($xml)->not->toContain('<hash_2001');
});

it('ships a data query whose fields are exactly the partition series', function () {
	$query  = simplexml_load_file(dirname(__DIR__, 2) . '/resource/script_server/' . TemplateInstaller::QUERY_FILE);
	$fields = [];

	foreach ($query->fields->children() as $field) {
		if ((string) $field->direction === 'output') {
			$fields[] = (string) $field->query_name;
		}
	}

	expect($fields)->toBe(Series::names())
		->and((string) $query->index_order)->toBe('hpcPartition')
		->and((string) $query->index_orphan_removal)->toBe('true')
		->and((string) $query->arg_prepend)->toBe('|host_id|');
});

it('stores every cluster value and the age in the cluster data template', function () {
	$xml = hpcsched_templates_xml();

	foreach (array_merge(Series::names(), [Series::AGE]) as $name) {
		expect($xml)->toContain('<data_source_name>' . $name . '</data_source_name>');
	}
});
