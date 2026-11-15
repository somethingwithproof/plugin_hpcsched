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
 * Page files, realms, menu entries, and breadcrumbs.
 *
 * Cacti's auth.php denies any page that is not listed in a realm, so every
 * page script the plugin ships appears in exactly one realm below
 * (REQ-UI-001). Cluster configuration and the health page are HPC Scheduler
 * Admin pages (REQ-UI-003, REQ-HEALTH-004). The view realm sees cluster
 * counts and freshness only; no page in this release shows a job, user, or
 * account name (REQ-UI-004).
 */
final class Pages {
	public const VIEW  = ['hpcsched.php'];
	public const ADMIN = ['hpcsched_clusters.php', 'hpcsched_health.php'];

	/**
	 * @return list<array{name: string, files: list<string>}>
	 */
	public static function realms(): array {
		return [
			['name' => __('HPC Scheduler View', 'hpcsched'), 'files' => self::VIEW],
			['name' => __('HPC Scheduler Admin', 'hpcsched'), 'files' => self::ADMIN],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function files(): array {
		return array_merge(self::VIEW, self::ADMIN);
	}

	/**
	 * Console > Management entries. Cacti shows a menu entry only to users
	 * whose realms allow its file.
	 *
	 * @return array<string, string>
	 */
	public static function menu(): array {
		return [
			'hpcsched.php'          => __('HPC Scheduler', 'hpcsched'),
			'hpcsched_clusters.php' => __('HPC Clusters', 'hpcsched'),
			'hpcsched_health.php'   => __('HPC Scheduler Health', 'hpcsched'),
		];
	}

	/**
	 * Pages whose load re-checks the schema, matching how maintained plugins
	 * apply upgrades in a core that never calls plugin_*_upgrade() itself.
	 *
	 * @return list<string>
	 */
	public static function upgradeTriggers(): array {
		return array_merge(['plugins.php'], self::files());
	}

	/**
	 * @return array<string, array{title: string, mapping: string, url: string, level: string}>
	 */
	public static function navigation(): array {
		$nav = [];

		foreach (self::menu() as $file => $title) {
			$nav[$file . ':'] = [
				'title'   => $title,
				'mapping' => 'index.php:',
				'url'     => $file,
				'level'   => '1',
			];
		}

		return $nav;
	}
}
