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
 * see hpcsched_clusters.php. Read only: nothing here contacts a scheduler
 * or reads a token file.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/hpcsched/setup.php');

if (!hpcsched_page_start()) {
	exit;
}

$hpcsched_clock = new \Somethingwithproof\Hpcsched\SystemClock();

top_header();

(new \Somethingwithproof\Hpcsched\HealthPage(
	new \Somethingwithproof\Hpcsched\ClusterRepository(false, $hpcsched_clock),
	new \Somethingwithproof\Hpcsched\RunRepository(false, $hpcsched_clock),
	new \Somethingwithproof\Hpcsched\ViewRepository(false),
	hpcsched_settings(),
	new \Somethingwithproof\Hpcsched\FreshnessEvaluator($hpcsched_clock),
	$hpcsched_clock,
	hpcsched_poller_interval()
))->render();

bottom_footer();
