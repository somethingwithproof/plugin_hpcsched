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
 * HPC Scheduler Health (HPC Scheduler Admin realm, REQ-HEALTH-004): per
 * cluster the last run, its freshness, the release and parser seen, the
 * token file state the reader found, and warnings; per section its state,
 * error class, and age. Every stored string is escaped where it is printed.
 * Nothing here contacts a scheduler or reads a token file.
 */
final class HealthPage {
	/**
	 * Construct a new HealthPage instance.
	 *
	 * @param ClusterRepository $clusters
	 * @param RunRepository $runs
	 * @param ViewRepository $view
	 * @param Settings $settings
	 * @param FreshnessEvaluator $evaluator
	 * @param Clock $clock
	 * @param int $pollerInterval
	 * @param DeviceAccess $access
	 * @param TemplateInstaller $templates
	 */
	public function __construct(
		private readonly ClusterRepository $clusters,
		private readonly RunRepository $runs,
		private readonly ViewRepository $view,
		private readonly Settings $settings,
		private readonly FreshnessEvaluator $evaluator,
		private readonly Clock $clock,
		private readonly int $pollerInterval,
		private readonly DeviceAccess $access,
		private readonly TemplateInstaller $templates,
	) {
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public function render(): void {
		$now      = $this->clock->now();
		$runs     = $this->runs->all();
		$sections = $this->view->sections();
		$clusters = $this->access->clusters($this->clusters->all());

		html_start_box(__('Clusters', 'hpcsched'), '100%', '', '3', 'center', '');
		print Format::header([__('Cluster', 'hpcsched'), __('State', 'hpcsched'), __('Freshness', 'hpcsched'), __('Last Run', 'hpcsched'), __('Outcome', 'hpcsched'), __('Duration', 'hpcsched'), __('Calls', 'hpcsched'), __('Slurm', 'hpcsched'), __('Transport', 'hpcsched'), __('Parser', 'hpcsched'), __('Last Success', 'hpcsched')]);

		if ($clusters === []) {
			print '<tr><td colspan="11"><em>' . html_escape(__('No clusters configured yet.', 'hpcsched')) . '</em></td></tr>';
		}

		$warnings = [];

		foreach (HealthWarnings::global($this->settings) as $warning) {
			$warnings[] = [__('All clusters', 'hpcsched'), $warning];
		}

		foreach ($this->templateProblems() as $warning) {
			$warnings[] = [__('All clusters', 'hpcsched'), $warning];
		}

		foreach ($clusters as $cluster) {
			$run    = $runs[$cluster->id] ?? null;
			$values = ClusterValues::of($cluster, $sections[$cluster->id] ?? [], [], [], $this->evaluator, $this->pollerInterval);

			print Format::row([
				$cluster->name,
				$cluster->adminState->value,
				$values->freshness->label(),
				Format::age($run->finishedAt ?? 0, $now),
				$run->outcome->value ?? RunOutcome::Never->value,
				$run?->duration === null ? '-' : sprintf('%.2f s', $run->duration),
				(string) ($run->calls ?? 0),
				($run->release ?? '') === '' ? '-' : (string) $run?->release,
				$cluster->transport->label(),
				self::parser($cluster, $run),
				Format::age($run->lastSuccess ?? 0, $now),
			]);

			foreach (HealthWarnings::for($cluster, $run, $values->freshness, $this->settings, $this->pollerInterval, $now) as $warning) {
				$warnings[] = [$cluster->name, $warning];
			}
		}

		html_end_box();

		html_start_box(__('Warnings', 'hpcsched'), '100%', '', '3', 'center', '');
		print Format::header([__('Cluster', 'hpcsched'), __('Warning', 'hpcsched')]);

		if ($warnings === []) {
			print '<tr><td colspan="2"><em>' . html_escape(__('No warnings.', 'hpcsched')) . '</em></td></tr>';
		}

		foreach ($warnings as [$name, $warning]) {
			print Format::row([$name, $warning]);
		}

		html_end_box();

		html_start_box(__('Templates', 'hpcsched'), '100%', '', '3', 'center', '');
		print '<tr><td>' . html_escape($this->templates->isCurrent() ? __('The device, data, and graph templates and the partition data query are installed.', 'hpcsched') : __('The templates are not installed or not current, so cluster devices cannot be created. See the warnings above.', 'hpcsched')) . '</td>'
			. '<td><form method="post" action="hpcsched_health.php"><input type="hidden" name="action" value="install_templates">'
			. '<input type="submit" class="ui-button" value="' . html_escape(__('Install Templates', 'hpcsched')) . '"></form></td></tr>';
		html_end_box();

		html_start_box(__('Snapshot Sections', 'hpcsched'), '100%', '', '3', 'center', '');
		print Format::header([__('Cluster', 'hpcsched'), __('Section', 'hpcsched'), __('Last Attempt', 'hpcsched'), __('State', 'hpcsched'), __('Error', 'hpcsched'), __('Records', 'hpcsched'), __('Data Age', 'hpcsched'), __('Freshness', 'hpcsched')]);

		foreach ($clusters as $cluster) {
			foreach ($cluster->sections() as $section) {
				$record = $sections[$cluster->id][$section->value] ?? null;

				print Format::row([
					$cluster->name,
					$section->label(),
					Format::age($record->attemptedAt ?? 0, $now),
					$record->state->value ?? SectionState::NotAttempted->value,
					$record?->error === null ? '-' : $record->error->value,
					$record === null || $record->observedAt === 0 ? '-' : number_format($record->records),
					Format::age($record->observedAt ?? 0, $now),
					$this->evaluator->evaluate($record->observedAt ?? 0, $cluster->effectiveInterval($this->pollerInterval))->label(),
				]);
			}
		}

		html_end_box();
	}

	/**
	 * @return list<string>
	 */
	private function templateProblems(): array {
		$problems = [];

		if ($this->templates->failure() !== '') {
			$problems[] = __('The last template install failed: %s', $this->templates->failure(), 'hpcsched');
		}

		if (!$this->templates->present()) {
			$problems[] = __('Some HPC Scheduler templates are missing from Cacti. Install them below.', 'hpcsched');
		}

		$file = $this->templates->queryFileProblem();

		if ($file !== null) {
			$problems[] = $file;
		}

		return $problems;
	}

	/**
	 * The data parser of the last run: the Settings choice for client
	 * commands, the one discovered from the daemon for slurmrestd.
	 */
	private static function parser(Cluster $cluster, ?ClusterRun $run): string {
		$parser = $run->dataParser ?? '';

		if ($parser === '') {
			return '-';
		}

		return $cluster->transport === Transport::Rest ? __('%s, discovered', $parser, 'hpcsched') : $parser;
	}
}
