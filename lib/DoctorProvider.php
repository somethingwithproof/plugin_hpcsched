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
 * Provides read-only diagnostic checks for Cacti Doctor via the
 * doctor_register_checks hook.
 */
final class DoctorProvider {
	public function __construct(private readonly HealthEvaluator $evaluator) {
	}

	/**
	 * @return list<array{id: string, category: string, status: string, summary: string, detail: string}>
	 */
	public function checks(): array {
		$report  = $this->evaluator->evaluate();
		$summary = $report['summary'];
		$checks  = [];

		$checks[] = [
			'id'       => 'hpcsched.schema',
			'category' => 'hpcsched',
			'status'   => $report['schema_current'] ? 'pass' : 'fail',
			'summary'  => $report['schema_current']
				? sprintf('HPC Scheduler schema is current (v%d)', $report['schema_version'])
				: sprintf('HPC Scheduler schema is at v%d (expected v%d)', $report['schema_version'], Schema::VERSION),
			'detail'   => sprintf('%d clusters configured (%d enabled, %d on remote pollers).', $summary['clusters'], $summary['enabled_clusters'], $summary['remote_clusters']),
		];

		$checks[] = [
			'id'       => 'hpcsched.cluster_freshness',
			'category' => 'hpcsched',
			'status'   => $summary['failed_clusters'] > 0 ? 'fail' : ($summary['stale_clusters'] > 0 ? 'warn' : 'pass'),
			'summary'  => $summary['failed_clusters'] > 0
				? sprintf('%d clusters failed their last reader run', $summary['failed_clusters'])
				: ($summary['stale_clusters'] > 0
					? sprintf('%d clusters have stale scheduler snapshots', $summary['stale_clusters'])
					: 'All enabled clusters have current scheduler snapshots'),
			'detail'   => sprintf('Enabled: %d, Stale: %d, Failed: %d.', $summary['enabled_clusters'], $summary['stale_clusters'], $summary['failed_clusters']),
		];

		$badTokens = 0;

		foreach ($report['clusters'] as $row) {
			$state = Row::string($row, 'token_state');

			if (Row::string($row, 'transport') === Transport::Rest->value && $state !== TokenState::None->value && $state !== TokenState::Ok->value) {
				$badTokens++;
			}
		}

		$checks[] = [
			'id'       => 'hpcsched.token_health',
			'category' => 'hpcsched',
			'status'   => $badTokens > 0 ? 'warn' : 'pass',
			'summary'  => $badTokens > 0
				? sprintf('%d slurmrestd clusters report token file permission or state warnings', $badTokens)
				: 'All slurmrestd cluster token files are healthy or not configured',
			'detail'   => 'Token file checks read stored reader state only; token contents are never disclosed.',
		];

		$checks[] = [
			'id'       => 'hpcsched.maint_sync',
			'category' => 'hpcsched',
			'status'   => 'pass',
			'summary'  => $report['maint_available']
				? sprintf('Cacti Maint available (%d hpcsched-managed maintenance windows active)', $report['maint_links'])
				: 'Cacti Maint plugin is not installed; maintenance sync is inactive',
			'detail'   => 'Run cli/hpcsched.php sync-maint --dry-run to preview node maintenance synchronization.',
		];

		return $checks;
	}
}
