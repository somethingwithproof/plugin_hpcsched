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

use Somethingwithproof\Hpcsched\SystemClock;
use Somethingwithproof\Hpcsched\RunRepository;
use Somethingwithproof\Hpcsched\ClusterRepository;

/** @return list<array{id: string, name: string, state: string, observed: int, interval: int, detail: string}> */
function hpcsched_operator_rows(): array {
	$rows  = [];
	$clock = new SystemClock();
	$runs  = new RunRepository(false, $clock);

	foreach ((new ClusterRepository(false, $clock))->all() as $cluster) {
		if ($cluster->hostId <= 0 || !hpcsched_device_access()->cluster($cluster)) {
			continue;
		}
		$h      = $runs->get($cluster->id);
		$rows[] = [
			'id' => (string) $cluster->id,
			'name' => $cluster->name,
			'state' => $cluster->adminState->value . ' / ' . ($h->outcome->value ?? 'never'),
			'observed' => $h->finishedAt ?? 0,
			'interval' => $cluster->effectiveInterval(max(60, (int) read_config_option('poller_interval'))),
			'detail'       => ($h->error->value ?? '') . ' ' . __('Inspect pending reason families and snapshot freshness on the overview. Escalate credentials, cluster mappings and maintenance policy to an administrator.', 'hpcsched')
		];
	}

	return $rows;
}
