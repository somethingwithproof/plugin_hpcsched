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
 * HPC Scheduler Health (HPC Scheduler Admin realm). Must parse on PHP 8.0;
 * see hpcsched_clusters.php. Nothing here contacts a scheduler or reads a
 * token file. The one action, installing the templates, is POST only and
 * changes no cluster. Clusters on a device the user may not see are left
 * out.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/hpcsched/setup.php');

if (!hpcsched_page_start()) {
	exit;
}

hpcsched_require_post('hpcsched_health.php', array('install_templates'));

$hpcsched_clock     = new \Somethingwithproof\Hpcsched\SystemClock();
$hpcsched_templates = hpcsched_template_installer();

if (get_nfilter_request_var('action') === 'install_templates') {
	$hpcsched_ok = $hpcsched_templates->install();
	raise_message('hpcsched_templates', $hpcsched_ok ? __('The HPC Scheduler templates are installed.', 'hpcsched') : __('The templates were not installed: %s', $hpcsched_templates->failure(), 'hpcsched'), $hpcsched_ok ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR);
	header('Location: hpcsched_health.php');
	exit;
}

top_header();

(new \Somethingwithproof\Hpcsched\HealthPage(
	new \Somethingwithproof\Hpcsched\ClusterRepository(false, $hpcsched_clock),
	new \Somethingwithproof\Hpcsched\RunRepository(false, $hpcsched_clock),
	new \Somethingwithproof\Hpcsched\ViewRepository(false),
	hpcsched_settings(),
	new \Somethingwithproof\Hpcsched\FreshnessEvaluator($hpcsched_clock),
	$hpcsched_clock,
	hpcsched_poller_interval(),
	hpcsched_device_access(),
	$hpcsched_templates
))->render();

bottom_footer();
