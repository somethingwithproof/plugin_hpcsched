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

namespace Somethingwithproof\Hpcsched;

/**
 * Provides plain-array Google AIP resource descriptors for the optional
 * api_register_resources hook. Imports no external plugin classes and stays
 * standalone when no API plugin is installed.
 */
final class ApiResourceProvider {
	/**
	 * Build read-only Google AIP resource descriptors for HPC Scheduler
	 * entities. Token file paths and secret contents are excluded, and share
	 * resources expose account-level associations only (ADR 0010).
	 *
	 * @return array<string, array{
	 *   type: string,
	 *   pattern: string,
	 *   singular: string,
	 *   plural: string,
	 *   plugin: string,
	 *   realm: string,
	 *   read_only: bool,
	 *   methods: list<string>,
	 *   fields: list<string>
	 * }>
	 */
	public function resources(): array {
		return [
			'hpcsched.clusters' => [
				'type'      => 'hpcsched.cacti.net/Cluster',
				'pattern'   => 'clusters/{cluster}',
				'singular'  => 'cluster',
				'plural'    => 'clusters',
				'plugin'    => 'hpcsched',
				'realm'     => 'hpcsched.php',
				'read_only' => true,
				'methods'   => ['get', 'list'],
				'fields'    => ['name', 'poller_id', 'host_id', 'admin_state', 'transport', 'collect_interval', 'status', 'freshness', 'slurm_release'],
			],
			'hpcsched.partitions' => [
				'type'      => 'hpcsched.cacti.net/Partition',
				'pattern'   => 'clusters/{cluster}/partitions/{partition}',
				'singular'  => 'partition',
				'plural'    => 'partitions',
				'plugin'    => 'hpcsched',
				'realm'     => 'hpcsched.php',
				'read_only' => true,
				'methods'   => ['get', 'list'],
				'fields'    => ['name', 'state', 'total_nodes', 'total_cpus', 'observed_at'],
			],
			'hpcsched.shares' => [
				'type'      => 'hpcsched.cacti.net/AccountShare',
				'pattern'   => 'clusters/{cluster}/shares/{share}',
				'singular'  => 'share',
				'plural'    => 'shares',
				'plugin'    => 'hpcsched',
				'realm'     => 'hpcsched.php',
				'read_only' => true,
				'methods'   => ['get', 'list'],
				'fields'    => ['name', 'account', 'partition_name', 'parent', 'usage_raw', 'usage_normalized', 'effective_usage', 'fairshare_factor', 'fairshare_level'],
			],
		];
	}
}
