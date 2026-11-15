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
 * freshness, and per cluster the pending reasons view. A value the plugin
 * cannot vouch for shows as a dash, never as zero. No job, user, or account
 * appears (REQ-UI-004, REQ-REASON-006). Clusters on a device the user may
 * not see are left out (DeviceAccess).
 */
final class OverviewPage {
	public const FILE = 'hpcsched.php';

	/** Rows the reasons view lists at most per table (REQ-REASON-004). */
	public const ROW_LIMIT = 200;

	/**
	 * @param bool $admin whether the user also has the Admin realm, which
	 *                    adds node names to the reasons view
	 */
	public function __construct(
		private readonly ClusterRepository $clusters,
		private readonly ViewRepository $view,
		private readonly FreshnessEvaluator $evaluator,
		private readonly Clock $clock,
		private readonly int $pollerInterval,
		private readonly DeviceAccess $access,
		private readonly bool $admin = false,
	) {
	}

	public function render(): void {
		$now = $this->clock->now();

		[$sections, $jobs, $nodes] = $this->view->consistent(fn (): array => [$this->view->sections(), $this->view->jobStates(), $this->view->nodeStates()]);

		$clusters = array_values(array_filter($this->access->clusters($this->clusters->all()), static fn (Cluster $c): bool => $c->enabled()));

		html_start_box(__('HPC Scheduler', 'hpcsched'), '100%', '', '3', 'center', '');
		print Format::header([__('Cluster', 'hpcsched'), __('Freshness', 'hpcsched'), __('Collected', 'hpcsched'), __('Running', 'hpcsched'), __('Pending', 'hpcsched'), __('Held', 'hpcsched'), __('Nodes', 'hpcsched'), __('Down', 'hpcsched'), __('Drained', 'hpcsched'), __('Idle', 'hpcsched'), __('Details', 'hpcsched')]);

		if ($clusters === []) {
			print '<tr><td colspan="11"><em>' . html_escape(__('No clusters are collected yet.', 'hpcsched')) . '</em></td></tr>';
		}

		foreach ($clusters as $cluster) {
			$v     = ClusterValues::of($cluster, $sections[$cluster->id] ?? [], $jobs[$cluster->id] ?? [], $nodes[$cluster->id] ?? [], $this->evaluator, $this->pollerInterval);
			$links = '<a href="' . html_escape(self::FILE . '?action=reasons&id=' . $cluster->id) . '">' . html_escape(__('Pending reasons', 'hpcsched')) . '</a>';

			// Cacti decides which of the device's graphs the user sees.
			if ($cluster->hostId > 0) {
				$links .= ' <a href="' . html_escape('../../graph_view.php?action=preview&reset=true&host_id=' . $cluster->hostId) . '">' . html_escape(__('Graphs', 'hpcsched')) . '</a>';
			}

			print '<tr class="tableRow">' . substr(Format::row([
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
			]), strlen('<tr class="tableRow">'), -strlen('</tr>')) . '<td>' . $links . '</td></tr>';
		}

		html_end_box();

		print '<p><small>' . html_escape(__('A dash means the plugin has no current data for that value: the section failed, is stale, or was never collected. Pending excludes held jobs. Drained counts every node with the drain flag, including nodes that are also down.', 'hpcsched')) . '</small></p>';
	}

	/**
	 * The raw pending reasons of one cluster with job counts and the
	 * partitions they affect, and nodes in states the plugin does not know
	 * (REQ-REASON-003 to REQ-REASON-005, REQ-NODEAGG-002). The caller has
	 * checked DeviceAccess for the cluster.
	 */
	public function renderReasons(Cluster $cluster): void {
		[$sections, $listed, $unknown] = $this->view->consistent(fn (): array => [
			$this->view->sections()[$cluster->id] ?? [],
			$this->view->reasons($cluster->id, self::ROW_LIMIT),
			$this->view->otherNodes($cluster->id, self::ROW_LIMIT),
		]);

		html_start_box(__('Pending Reasons: %s', html_escape($cluster->name), 'hpcsched'), '100%', '', '3', 'center', '');

		print '<tr><td colspan="4">' . $this->snapshotLine($cluster, $sections, Section::Jobs) . '</td></tr>';

		print Format::header([__('Reason', 'hpcsched'), __('Family', 'hpcsched'), __('Jobs', 'hpcsched'), __('Partitions', 'hpcsched')]);

		if ($listed['reasons'] === []) {
			print '<tr><td colspan="4"><em>' . html_escape(__('No pending jobs.', 'hpcsched')) . '</em></td></tr>';
		}

		foreach ($listed['reasons'] as $entry) {
			print Format::row([$entry['name'], ReasonFamily::of($entry['name'])->label(), number_format($entry['jobs']), implode(', ', $entry['partitions'])]);
		}

		if ($listed['more']) {
			print '<tr><td colspan="4"><em>' . html_escape(__('Only the %d reasons with the most jobs are listed.', self::ROW_LIMIT, 'hpcsched')) . '</em></td></tr>';
		}

		html_end_box();

		print '<p><small>' . html_escape(__('Each job counts under the last reason the scheduler examined for it, which need not be the only thing holding it back. A job in several partitions counts once here and once in each partition it names. Held jobs are listed with their hold reason.', 'hpcsched')) . '</small></p>';

		$nodesObserved = ($sections[Section::Nodes->value] ?? null)->observedAt ?? 0;

		html_start_box(__('Nodes in Unknown States', 'hpcsched'), '100%', '', '3', 'center', '');
		print '<tr><td colspan="3">' . $this->snapshotLine($cluster, $sections, Section::Nodes) . '</td></tr>';
		print Format::header($this->admin ? [__('Slurm State', 'hpcsched'), __('Nodes', 'hpcsched'), __('Names', 'hpcsched')] : [__('Slurm State', 'hpcsched'), __('Nodes', 'hpcsched')]);

		if ($unknown === [] && $nodesObserved > 0) {
			print '<tr><td colspan="3"><em>' . html_escape(__('None. Every node maps to a known state.', 'hpcsched')) . '</em></td></tr>';
		}

		foreach ($unknown as $entry) {
			$cells = [$entry['state'], number_format($entry['nodes'])];

			if ($this->admin) {
				$cells[] = implode(', ', $entry['names']);
			}

			print Format::row($cells);
		}

		html_end_box();
	}

	/**
	 * When a section's rows were observed and whether they are current, as
	 * one escaped line.
	 *
	 * @param array<string, SectionRecord> $sections section value => record
	 */
	private function snapshotLine(Cluster $cluster, array $sections, Section $section): string {
		$now      = $this->clock->now();
		$observed = ($sections[$section->value] ?? null)->observedAt ?? 0;
		$jobs     = $section === Section::Jobs;

		if ($observed <= 0) {
			return html_escape($jobs ? __('No jobs snapshot has been collected yet.', 'hpcsched') : __('No nodes snapshot has been collected yet, so the node states are unknown.', 'hpcsched'));
		}

		$taken   = date('Y-m-d H:i:s', $observed);
		$age     = Format::age($observed, $now);
		$current = ClusterValues::usable($cluster, $sections, $section, $this->evaluator, $this->pollerInterval);

		if ($current) {
			return html_escape($jobs ? __('Jobs snapshot taken %s (%s), current.', $taken, $age, 'hpcsched') : __('Nodes snapshot taken %s (%s), current.', $taken, $age, 'hpcsched'));
		}

		return '<span class="textWarning">' . html_escape($jobs
			? __('Jobs snapshot taken %s (%s), not current: it is stale or the last collection failed, and the counts below are the last ones collected.', $taken, $age, 'hpcsched')
			: __('Nodes snapshot taken %s (%s), not current: it is stale or the last collection failed, and the nodes below are the last ones collected.', $taken, $age, 'hpcsched')) . '</span>';
	}
}
