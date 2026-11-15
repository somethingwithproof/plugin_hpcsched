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
 * HPC Clusters (HPC Scheduler Admin realm). This file must parse on PHP 8.0:
 * Cacti may include it on an unsupported runtime, and hpcsched_page_start()
 * refuses there before any plugin class loads. Request values are read
 * through Cacti's request helpers and validated by ClusterInput before
 * anything is stored. Nothing here contacts a scheduler.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/hpcsched/setup.php');

if (!hpcsched_page_start()) {
	exit;
}

hpcsched_require_post('hpcsched_clusters.php', array('save', 'delete', 'enable', 'disable'));

$hpcsched_clock    = new \Somethingwithproof\Hpcsched\SystemClock();
$hpcsched_clusters = new \Somethingwithproof\Hpcsched\ClusterRepository(false, $hpcsched_clock);
$hpcsched_pollers  = new \Somethingwithproof\Hpcsched\PollerRepository(false);
$hpcsched_page     = new \Somethingwithproof\Hpcsched\ClustersPage($hpcsched_clusters, $hpcsched_pollers);
$hpcsched_action   = (string) get_nfilter_request_var('action');

switch ($hpcsched_action) {
	case 'save':
		$hpcsched_id       = (int) get_filter_request_var('id');
		$hpcsched_existing = $hpcsched_id > 0 ? $hpcsched_clusters->get($hpcsched_id) : null;

		if ($hpcsched_id > 0 && $hpcsched_existing === null) {
			header('Location: hpcsched_clusters.php');
			exit;
		}

		$hpcsched_input = array();

		foreach (array('name', 'poller_id', 'collect_interval', 'enabled', 'collect_reservations', 'collect_shares', 'transport', 'slurm_conf', 'rest_url', 'rest_user', 'token_file', 'updated_at') as $hpcsched_field) {
			$hpcsched_input[$hpcsched_field] = (string) get_nfilter_request_var($hpcsched_field);
		}

		$hpcsched_parsed = \Somethingwithproof\Hpcsched\ClusterInput::parse($hpcsched_input, $hpcsched_existing, $hpcsched_pollers->names(), hpcsched_settings());
		$hpcsched_errors = $hpcsched_parsed->errors;

		if ($hpcsched_parsed->valid() && $hpcsched_clusters->nameTaken($hpcsched_parsed->name, $hpcsched_id)) {
			$hpcsched_errors['name'] = __('Name: another cluster already uses this name.', 'hpcsched');
		}

		if ($hpcsched_errors !== array()) {
			top_header();
			$hpcsched_page->renderEdit($hpcsched_existing, $hpcsched_input, $hpcsched_errors);
			bottom_footer();
			exit;
		}

		$hpcsched_saved = $hpcsched_clusters->save($hpcsched_parsed) > 0;
		raise_message('hpcsched_saved', $hpcsched_saved ? __('The cluster was saved.', 'hpcsched') : __('The cluster was not saved: it was changed by someone else since the form was opened, or the write failed. Open it again and repeat the change.', 'hpcsched'), $hpcsched_saved ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR);
		header('Location: hpcsched_clusters.php');
		exit;
	case 'enable':
	case 'disable':
		$hpcsched_clusters->setState((int) get_filter_request_var('id'), $hpcsched_action === 'enable' ? \Somethingwithproof\Hpcsched\AdminState::Enabled : \Somethingwithproof\Hpcsched\AdminState::Disabled);
		header('Location: hpcsched_clusters.php');
		exit;
	case 'delete':
		if ($hpcsched_clusters->delete((int) get_filter_request_var('id'))) {
			raise_message('hpcsched_deleted', __('The cluster and its cached snapshot were deleted. The scheduler was not contacted.', 'hpcsched'), MESSAGE_LEVEL_INFO);
		}

		header('Location: hpcsched_clusters.php');
		exit;
	case 'edit':
		$hpcsched_id = (int) get_filter_request_var('id');

		top_header();
		$hpcsched_page->renderEdit($hpcsched_id > 0 ? $hpcsched_clusters->get($hpcsched_id) : null);
		bottom_footer();
		exit;
	case 'export':
		$hpcsched_info = plugin_hpcsched_version();

		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="hpcsched-clusters.json"');
		header('X-Content-Type-Options: nosniff');
		print json_encode($hpcsched_page->export($hpcsched_info['version'] ?? '', $hpcsched_clock->now()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
		exit;
	default:
		top_header();
		$hpcsched_page->renderList();
		bottom_footer();
}
