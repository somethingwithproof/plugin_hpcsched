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
 * HPC Scheduler (HPC Scheduler View realm): cluster-level counts and their
 * freshness. A value the plugin cannot vouch for shows as a dash, never as
 * zero. No job, user, or account appears (REQ-UI-004).
 */
final class OverviewPage {
	public function __construct(
		private readonly ClusterRepository $clusters,
		private readonly ViewRepository $view,
		private readonly FreshnessEvaluator $evaluator,
		private readonly Clock $clock,
		private readonly int $pollerInterval,
	) {
	}

	public function render(): void {
		$now      = $this->clock->now();
		$sections = $this->view->sections();
		$jobs     = $this->view->jobStates();
		$nodes    = $this->view->nodeStates();
		$clusters = array_values(array_filter($this->clusters->all(), static fn (Cluster $c): bool => $c->enabled()));

		html_start_box(__('HPC Scheduler', 'hpcsched'), '100%', '', '3', 'center', '');
		print Format::header([__('Cluster', 'hpcsched'), __('Freshness', 'hpcsched'), __('Collected', 'hpcsched'), __('Running', 'hpcsched'), __('Pending', 'hpcsched'), __('Held', 'hpcsched'), __('Nodes', 'hpcsched'), __('Down', 'hpcsched'), __('Drained', 'hpcsched'), __('Idle', 'hpcsched')]);

		if ($clusters === []) {
			print '<tr><td colspan="10"><em>' . html_escape(__('No clusters are collected yet.', 'hpcsched')) . '</em></td></tr>';
		}

		foreach ($clusters as $cluster) {
			$v = ClusterValues::of($cluster, $sections[$cluster->id] ?? [], $jobs[$cluster->id] ?? [], $nodes[$cluster->id] ?? [], $this->evaluator, $this->pollerInterval);

			print Format::row([
				$cluster->name,
				$v->freshness->label(),
				$v->age === null ? __('Never', 'hpcsched') : Format::age($now - $v->age, $now),
				Format::count($v->values['running']),
				Format::count($v->values['pending']),
				Format::count($v->values['held']),
				Format::count($v->values['nodes_total']),
				Format::count($v->values['nodes_down']),
				Format::count($v->values['nodes_drained']),
				Format::count($v->values['nodes_idle']),
			]);
		}

		html_end_box();

		print '<p><small>' . html_escape(__('A dash means the plugin has no current data for that value: the section failed, is stale, or was never collected. Pending excludes held jobs. Drained counts every node with the drain flag, including nodes that are also down.', 'hpcsched')) . '</small></p>';
	}
}
