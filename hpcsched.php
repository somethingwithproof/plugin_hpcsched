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
 * see hpcsched_clusters.php. Read only, counts only.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/hpcsched/setup.php');

if (!hpcsched_page_start()) {
	exit;
}

$hpcsched_clock = new \Somethingwithproof\Hpcsched\SystemClock();

top_header();

(new \Somethingwithproof\Hpcsched\OverviewPage(
	new \Somethingwithproof\Hpcsched\ClusterRepository(false, $hpcsched_clock),
	new \Somethingwithproof\Hpcsched\ViewRepository(false),
	new \Somethingwithproof\Hpcsched\FreshnessEvaluator($hpcsched_clock),
	$hpcsched_clock,
	hpcsched_poller_interval()
))->render();

bottom_footer();
