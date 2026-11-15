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
 * Script server input for the "HPC Scheduler Cluster Values" data sources:
 * every cluster value and age as name:value pairs in one call. It answers
 * from the plugin snapshot and never contacts a scheduler.
 *
 * This file must parse on PHP 8.0: the script server includes it on
 * whatever PHP the site runs, and hpcsched_boot() refuses before any plugin
 * class loads, answering U.
 */

if (!isset($called_by_script_server)) {
	include_once(__DIR__ . '/../../../include/cli_check.php');

	$hpcsched_args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? array_values(array_filter(array_slice($_SERVER['argv'], 1), 'is_string')) : array();

	print ss_hpcsched_cluster(...$hpcsched_args);
}

/**
 * @param int|string $host_id the cluster's Cacti device
 */
function ss_hpcsched_cluster($host_id = 0): string {
	static $series = null;

	include_once(dirname(__DIR__) . '/setup.php');

	if (!hpcsched_boot()) {
		return 'U';
	}

	if ($series === null) {
		$series = hpcsched_cluster_series();
	}

	return $series->cluster((int) $host_id);
}
