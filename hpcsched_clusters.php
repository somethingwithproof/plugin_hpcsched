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
 *
 * Every action that loads an existing cluster checks Cacti's device
 * permission for the cluster's device first (DeviceAccess), and mapping also
 * checks the device it maps to, so an administrator of the plugin allowed
 * one device cannot see or change a cluster on another.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/hpcsched/setup.php');

if (!hpcsched_page_start()) {
	exit;
}

hpcsched_require_post('hpcsched_clusters.php', array('save', 'delete', 'enable', 'disable', 'create_device', 'map_device', 'attach_device', 'unmap_device', 'reindex_device'));

$hpcsched_clock    = new \Somethingwithproof\Hpcsched\SystemClock();
$hpcsched_clusters = new \Somethingwithproof\Hpcsched\ClusterRepository(false, $hpcsched_clock);
$hpcsched_pollers  = new \Somethingwithproof\Hpcsched\PollerRepository(false);
$hpcsched_access   = hpcsched_device_access();
$hpcsched_devices  = hpcsched_provisioner();
$hpcsched_page     = new \Somethingwithproof\Hpcsched\ClustersPage($hpcsched_clusters, $hpcsched_pollers, $hpcsched_access, new \Somethingwithproof\Hpcsched\CactiObjectRepository(false), $hpcsched_devices);
$hpcsched_action   = (string) get_nfilter_request_var('action');

/**
 * The cluster a request names, or back to the list when it does not exist
 * or its device is not allowed to this user.
 */
function hpcsched_cluster_request(\Somethingwithproof\Hpcsched\ClusterRepository $clusters, \Somethingwithproof\Hpcsched\DeviceAccess $access): \Somethingwithproof\Hpcsched\Cluster {
	$cluster = $clusters->get((int) get_filter_request_var('id'));

	if (!$access->cluster($cluster)) {
		if ($cluster !== null) {
			raise_message('hpcsched_denied', __('You do not have permission for the device of that cluster.', 'hpcsched'), MESSAGE_LEVEL_ERROR);
		}

		header('Location: hpcsched_clusters.php');
		exit;
	}

	return $cluster;
}

switch ($hpcsched_action) {
	case 'save':
		$hpcsched_id       = (int) get_filter_request_var('id');
		$hpcsched_existing = $hpcsched_id > 0 ? hpcsched_cluster_request($hpcsched_clusters, $hpcsched_access) : null;
		$hpcsched_input    = array();

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
		$hpcsched_cluster = hpcsched_cluster_request($hpcsched_clusters, $hpcsched_access);

		if (!$hpcsched_clusters->setState($hpcsched_cluster->id, $hpcsched_action === 'enable' ? \Somethingwithproof\Hpcsched\AdminState::Enabled : \Somethingwithproof\Hpcsched\AdminState::Disabled)) {
			raise_message('hpcsched_state', __('The cluster state was not changed: the write failed. See the Cacti log.', 'hpcsched'), MESSAGE_LEVEL_ERROR);
		}

		header('Location: hpcsched_clusters.php');
		exit;
	case 'delete':
		$hpcsched_cluster = hpcsched_cluster_request($hpcsched_clusters, $hpcsched_access);

		if ($hpcsched_clusters->delete($hpcsched_cluster->id)) {
			raise_message('hpcsched_deleted', __('The cluster and its cached snapshot were deleted. Its device and graphs were kept. The scheduler was not contacted.', 'hpcsched'), MESSAGE_LEVEL_INFO);
		}

		header('Location: hpcsched_clusters.php');
		exit;
	case 'create_device':
	case 'map_device':
	case 'attach_device':
		$hpcsched_cluster = hpcsched_cluster_request($hpcsched_clusters, $hpcsched_access);
		$hpcsched_graphs  = get_nfilter_request_var('partition_graphs') === 'on';
		$hpcsched_back    = 'hpcsched_clusters.php?action=device&id=' . $hpcsched_cluster->id;

		if ($hpcsched_devices->refusal() !== null) {
			raise_message('hpcsched_device', $hpcsched_devices->refusal(), MESSAGE_LEVEL_ERROR);
		} elseif ($hpcsched_action === 'create_device') {
			$hpcsched_ok = $hpcsched_access->unmapped($hpcsched_cluster) && $hpcsched_devices->create($hpcsched_cluster, $hpcsched_graphs) > 0;
			raise_message('hpcsched_device', $hpcsched_ok ? __('The device and its graphs were created.', 'hpcsched') : __('The device was not created: the cluster already has a device, it was changed by someone else since the page was opened, or Cacti refused it. See the Cacti log.', 'hpcsched'), $hpcsched_ok ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR);
		} elseif ($hpcsched_action === 'map_device') {
			$hpcsched_host = (int) get_filter_request_var('host_id');
			$hpcsched_ok   = $hpcsched_access->unmapped($hpcsched_cluster) && $hpcsched_access->device($hpcsched_host) && $hpcsched_devices->map($hpcsched_cluster, $hpcsched_host, $hpcsched_graphs);
			raise_message('hpcsched_device', $hpcsched_ok ? __('The cluster was mapped to the device.', 'hpcsched') : __('The cluster was not mapped: the device does not exist, is not allowed to you, already carries another cluster, or the cluster was changed by someone else since the page was opened.', 'hpcsched'), $hpcsched_ok ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR);
		} elseif ($hpcsched_cluster->hostId > 0 && !$hpcsched_access->orphaned($hpcsched_cluster)) {
			$hpcsched_devices->attach($hpcsched_cluster->id, $hpcsched_cluster->hostId, $hpcsched_graphs);
			raise_message('hpcsched_device', __('Missing graphs were added.', 'hpcsched'), MESSAGE_LEVEL_INFO);
		} else {
			raise_message('hpcsched_device', __('No graphs were added: the cluster has no device, or its device no longer exists.', 'hpcsched'), MESSAGE_LEVEL_ERROR);
		}

		header('Location: ' . $hpcsched_back);
		exit;
	case 'unmap_device':
		$hpcsched_cluster = hpcsched_cluster_request($hpcsched_clusters, $hpcsched_access);
		$hpcsched_ok      = $hpcsched_clusters->mapHost($hpcsched_cluster->id, 0, $hpcsched_cluster->hostId);
		raise_message('hpcsched_device', $hpcsched_ok ? __('The cluster was unmapped. The device and its graphs were kept.', 'hpcsched') : __('The cluster was not unmapped: it was changed by someone else since the page was opened.', 'hpcsched'), $hpcsched_ok ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR);
		header('Location: hpcsched_clusters.php?action=device&id=' . $hpcsched_cluster->id);
		exit;
	case 'reindex_device':
		$hpcsched_cluster = hpcsched_cluster_request($hpcsched_clusters, $hpcsched_access);
		$hpcsched_result  = $hpcsched_cluster->hostId > 0 && !$hpcsched_access->orphaned($hpcsched_cluster) ? $hpcsched_devices->reindex($hpcsched_cluster->hostId) : \Somethingwithproof\Hpcsched\ReindexResult::NotRun;

		switch ($hpcsched_result) {
			case \Somethingwithproof\Hpcsched\ReindexResult::Completed:
				raise_message('hpcsched_device', __('The partitions were reindexed from the snapshot.', 'hpcsched'), MESSAGE_LEVEL_INFO);
				break;
			case \Somethingwithproof\Hpcsched\ReindexResult::Skipped:
				raise_message('hpcsched_device', __('The partitions were not reindexed: the device is disabled or down, so Cacti skipped its data query.', 'hpcsched'), MESSAGE_LEVEL_ERROR);
				break;
			case \Somethingwithproof\Hpcsched\ReindexResult::Failed:
				raise_message('hpcsched_device', __('The partitions were not reindexed: Cacti could not run the data query. See the Cacti log.', 'hpcsched'), MESSAGE_LEVEL_ERROR);
				break;
			case \Somethingwithproof\Hpcsched\ReindexResult::NoQuery:
				raise_message('hpcsched_device', __('The partitions were not reindexed: the HPC Scheduler data query is not installed. Install the templates from the health page.', 'hpcsched'), MESSAGE_LEVEL_ERROR);
				break;
			default:
				raise_message(
					'hpcsched_device',
					__('The partitions were not reindexed: the cluster has no device, or its device no longer exists.', 'hpcsched'),
					MESSAGE_LEVEL_ERROR
				);
		}

		header('Location: hpcsched_clusters.php?action=device&id=' . $hpcsched_cluster->id);
		exit;
	case 'device':
		$hpcsched_cluster = hpcsched_cluster_request($hpcsched_clusters, $hpcsched_access);

		top_header();
		$hpcsched_page->renderDevice($hpcsched_cluster);
		bottom_footer();
		exit;
	case 'edit':
		$hpcsched_id = (int) get_filter_request_var('id');

		top_header();
		$hpcsched_page->renderEdit($hpcsched_id > 0 ? hpcsched_cluster_request($hpcsched_clusters, $hpcsched_access) : null);
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
