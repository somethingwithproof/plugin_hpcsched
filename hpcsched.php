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
 * HPC Scheduler overview (HPC Scheduler View realm). Must parse on PHP 8.0;
 * see hpcsched_clusters.php. Read only, counts only. The pending reasons
 * view of a cluster checks Cacti's device permission for the cluster's
 * device first.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/hpcsched/setup.php');

if (!hpcsched_page_start()) {
	exit;
}

$hpcsched_clock    = new \Somethingwithproof\Hpcsched\SystemClock();
$hpcsched_clusters = new \Somethingwithproof\Hpcsched\ClusterRepository(false, $hpcsched_clock);
$hpcsched_access   = hpcsched_device_access();
$hpcsched_page     = new \Somethingwithproof\Hpcsched\OverviewPage(
	$hpcsched_clusters,
	new \Somethingwithproof\Hpcsched\ViewRepository(false),
	new \Somethingwithproof\Hpcsched\FreshnessEvaluator($hpcsched_clock),
	$hpcsched_clock,
	hpcsched_poller_interval(),
	$hpcsched_access,
	api_plugin_user_realm_auth('hpcsched_clusters.php') === true
);

if (get_nfilter_request_var('action') === 'reasons') {
	$hpcsched_cluster = $hpcsched_clusters->get((int) get_filter_request_var('id'));

	if (!$hpcsched_access->cluster($hpcsched_cluster)) {
		header('Location: hpcsched.php');
		exit;
	}

	top_header();
	$hpcsched_page->renderReasons($hpcsched_cluster);
	bottom_footer();
	exit;
}

top_header();
$hpcsched_page->render();
bottom_footer();
