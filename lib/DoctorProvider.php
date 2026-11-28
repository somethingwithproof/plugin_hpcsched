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
	/**
	 * Construct a Cacti Doctor provider backed by a read-only health evaluator.
	 *
	 * @param HealthEvaluator $evaluator read-only scheduler health evaluator
	 */
	public function __construct(private readonly HealthEvaluator $evaluator) {
	}

	/**
	 * Build a redacted diagnostic debug-info section list for the optional
	 * doctor_register_debug_info hook. Excludes all token file paths, token
	 * contents, and scheduler credentials.
	 *
	 * @return list<array{
	 *   id: string,
	 *   plugin: string,
	 *   label: string,
	 *   description: string,
	 *   private: bool,
	 *   fields: array<string, array{label: string, value: string, private: bool}>
	 * }>
	 */
	public function debugInfo(): array {
		$report  = $this->evaluator->evaluate();
		$summary = $report['summary'];

		return [
			[
				'id'          => 'hpcsched.overview',
				'plugin'      => 'hpcsched',
				'label'       => 'HPC Scheduler',
				'description' => 'Read-only health and cluster summary for plugin_hpcsched. Token paths and secrets are redacted.',
				'private'     => false,
				'fields'      => [
					'schema_version' => [
						'label'   => 'Schema Version',
						'value'   => sprintf('v%d (%s)', $report['schema_version'], $report['schema_current'] ? 'current' : 'upgrade_required'),
						'private' => false,
					],
					'health_status' => [
						'label'   => 'Health Status',
						'value'   => $report['status'],
						'private' => false,
					],
					'clusters_configured' => [
						'label'   => 'Configured Clusters',
						'value'   => sprintf('%d total (%d enabled, %d remote)', $summary['clusters'], $summary['enabled_clusters'], $summary['remote_clusters']),
						'private' => false,
					],
					'cluster_freshness' => [
						'label'   => 'Cluster Freshness',
						'value'   => sprintf('%d stale, %d failed', $summary['stale_clusters'], $summary['failed_clusters']),
						'private' => false,
					],
					'maint_sync' => [
						'label'   => 'Cacti Maint Integration',
						'value'   => $report['maint_available'] ? sprintf('available (%d managed links)', $report['maint_links']) : 'not_installed',
						'private' => false,
					],
					'token_redaction' => [
						'label'   => 'Credential Exposure',
						'value'   => 'redacted',
						'private' => true,
					],
				],
			],
		];
	}

	/**
	 * Build structured diagnostic check results matching Cacti Doctor's check
	 * contract (id, plugin, category, title, status, message, details, remediation).
	 *
	 * @return list<array{
	 *   id: string,
	 *   plugin: string,
	 *   category: string,
	 *   title: string,
	 *   status: string,
	 *   message: string,
	 *   details: string,
	 *   remediation: string
	 * }>
	 */
	public function checks(): array {
		$report  = $this->evaluator->evaluate();
		$summary = $report['summary'];
		$checks  = [];

		$checks[] = [
			'id'          => 'hpcsched.schema',
			'plugin'      => 'hpcsched',
			'category'    => 'hpcsched',
			'title'       => 'HPC Scheduler schema currency',
			'status'      => $report['schema_current'] ? 'ok' : 'fail',
			'message'     => $report['schema_current']
				? sprintf('HPC Scheduler schema is current (v%d)', $report['schema_version'])
				: sprintf('HPC Scheduler schema is at v%d (expected v%d)', $report['schema_version'], Schema::VERSION),
			'details'     => sprintf('%d clusters configured (%d enabled, %d on remote pollers).', $summary['clusters'], $summary['enabled_clusters'], $summary['remote_clusters']),
			'remediation' => $report['schema_current'] ? '' : 'Re-run plugin upgrade to bring plugin_hpcsched tables to the current schema version.',
		];

		$freshStatus = $summary['failed_clusters'] > 0 ? 'fail' : ($summary['stale_clusters'] > 0 ? 'warn' : 'ok');
		$checks[]    = [
			'id'          => 'hpcsched.cluster_freshness',
			'plugin'      => 'hpcsched',
			'category'    => 'hpcsched',
			'title'       => 'HPC Scheduler cluster freshness',
			'status'      => $freshStatus,
			'message'     => $summary['failed_clusters'] > 0
				? sprintf('%d clusters failed their last reader run', $summary['failed_clusters'])
				: ($summary['stale_clusters'] > 0
					? sprintf('%d clusters have stale scheduler snapshots', $summary['stale_clusters'])
					: 'All enabled clusters have current scheduler snapshots'),
			'details'     => sprintf('Enabled: %d, Stale: %d, Failed: %d.', $summary['enabled_clusters'], $summary['stale_clusters'], $summary['failed_clusters']),
			'remediation' => $freshStatus === 'ok' ? '' : 'Verify poller_hpcsched.php execution and Slurm transport reachability for stale or failed clusters.',
		];

		$badTokens = 0;

		foreach ($report['clusters'] as $row) {
			$state = Row::string($row, 'token_state');

			if (Row::string($row, 'transport') === Transport::Rest->value && $state !== TokenState::None->value && $state !== TokenState::Ok->value) {
				$badTokens++;
			}
		}

		$checks[] = [
			'id'          => 'hpcsched.token_health',
			'plugin'      => 'hpcsched',
			'category'    => 'hpcsched',
			'title'       => 'HPC Scheduler slurmrestd token health',
			'status'      => $badTokens > 0 ? 'warn' : 'ok',
			'message'     => $badTokens > 0
				? sprintf('%d slurmrestd clusters report token file permission or state warnings', $badTokens)
				: 'All slurmrestd cluster token files are healthy or not configured',
			'details'     => 'Token file checks read stored reader state only; token contents are never disclosed.',
			'remediation' => $badTokens > 0 ? 'Check slurmrestd token file permissions, expiry, and allowed token directories on the assigned poller.' : '',
		];

		$checks[] = [
			'id'          => 'hpcsched.maint_sync',
			'plugin'      => 'hpcsched',
			'category'    => 'hpcsched',
			'title'       => 'HPC Scheduler Cacti Maint integration',
			'status'      => 'ok',
			'message'     => $report['maint_available']
				? sprintf('Cacti Maint available (%d hpcsched-managed maintenance windows active)', $report['maint_links'])
				: 'Cacti Maint plugin is not installed; maintenance sync is inactive',
			'details'     => 'Run cli/hpcsched.php sync-maint --dry-run to preview node maintenance synchronization.',
			'remediation' => '',
		];

		return $checks;
	}
}
