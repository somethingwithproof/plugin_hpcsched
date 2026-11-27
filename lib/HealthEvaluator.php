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
 * Builds structured health and queue summary snapshots for cli/hpcsched.php,
 * Cacti Doctor checks, and MCP abilities. Read-only: never contacts a
 * scheduler or reads a token file.
 */
final class HealthEvaluator {
	/**
	 * Construct a new HealthEvaluator instance.
	 *
	 * @param ClusterRepository $clusters
	 * @param RunRepository $runs
	 * @param ViewRepository $view
	 * @param ShareRepository $shares
	 * @param MaintRepository $maint
	 * @param Settings $settings
	 * @param FreshnessEvaluator $evaluator
	 * @param Clock $clock
	 * @param Schema $schema
	 * @param int $pollerInterval
	 */
	public function __construct(
		private readonly ClusterRepository $clusters,
		private readonly RunRepository $runs,
		private readonly ViewRepository $view,
		private readonly ShareRepository $shares,
		private readonly MaintRepository $maint,
		private readonly Settings $settings,
		private readonly FreshnessEvaluator $evaluator,
		private readonly Clock $clock,
		private readonly Schema $schema,
		private readonly int $pollerInterval = 300,
	) {
	}

	/**
	 * @return array{
	 *   status: string,
	 *   schema_current: bool,
	 *   schema_version: int,
	 *   checked_at: int,
	 *   maint_available: bool,
	 *   maint_links: int,
	 *   summary: array{clusters: int, enabled_clusters: int, remote_clusters: int, stale_clusters: int, failed_clusters: int},
	 *   clusters: list<array<string, mixed>>,
	 *   warnings: list<string>
	 * }
	 */
	public function evaluate(): array {
		$now           = $this->clock->now();
		$schemaCurrent = $this->schema->isCurrent();
		$schemaVersion = $this->schema->storedVersion();
		$clusters      = $this->clusters->all();
		$runs          = $this->runs->all();
		$sections      = $this->view->sections();
		$maintAvail    = $this->maint->maintTablesAvailable();
		$maintLinks    = $this->maint->totalLinks();

		$warnings        = HealthWarnings::global($this->settings);
		$enabledCount    = 0;
		$remoteCount     = 0;
		$staleCount      = 0;
		$failedCount     = 0;
		$hasFail         = !$schemaCurrent;
		$hasWarn         = $warnings !== [];
		$clusterRows     = [];

		if (!$schemaCurrent) {
			$warnings[] = sprintf('Plugin schema is at version %d (expected %d).', $schemaVersion, Schema::VERSION);
		}

		foreach ($clusters as $cluster) {
			if ($cluster->enabled()) {
				$enabledCount++;
			}

			if ($cluster->pollerId !== 1) {
				$remoteCount++;
			}

			$run      = $runs[$cluster->id] ?? null;
			$values   = ClusterValues::of($cluster, $sections[$cluster->id] ?? [], [], [], $this->evaluator, $this->pollerInterval);
			$cWarns   = HealthWarnings::for($cluster, $run, $values->freshness, $this->settings, $this->pollerInterval, $now);
			$cStatus  = 'pass';

			if ($cluster->enabled()) {
				if ($run !== null && $run->outcome === RunOutcome::Failed) {
					$cStatus = 'fail';
					$failedCount++;
					$hasFail = true;
				} elseif ($values->freshness === Freshness::Stale || $cWarns !== []) {
					$cStatus = 'warn';
					$hasWarn = true;

					if ($values->freshness === Freshness::Stale) {
						$staleCount++;
					}
				}
			}

			foreach ($cWarns as $warn) {
				$warnings[] = sprintf('%s: %s', $cluster->name, $warn);
			}

			$clusterRows[] = [
				'id'               => $cluster->id,
				'name'             => $cluster->name,
				'poller_id'        => $cluster->pollerId,
				'host_id'          => $cluster->hostId,
				'admin_state'      => $cluster->adminState->value,
				'transport'        => $cluster->transport->value,
				'collect_interval' => $cluster->interval,
				'has_token_file'   => $cluster->tokenFile !== '',
				'status'           => $cStatus,
				'freshness'        => $values->freshness->value,
				'outcome'          => $run !== null ? $run->outcome->value : RunOutcome::Never->value,
				'slurm_release'    => $run !== null ? $run->release : '',
				'token_state'      => $run !== null ? $run->tokenState->value : TokenState::None->value,
				'last_success'     => $run !== null ? $run->lastSuccess : 0,
			];
		}

		return [
			'status'          => $hasFail ? 'fail' : ($hasWarn ? 'warn' : 'pass'),
			'schema_current'  => $schemaCurrent,
			'schema_version'  => $schemaVersion,
			'checked_at'      => $now,
			'maint_available' => $maintAvail,
			'maint_links'     => $maintLinks,
			'summary'         => [
				'clusters'         => count($clusters),
				'enabled_clusters' => $enabledCount,
				'remote_clusters'  => $remoteCount,
				'stale_clusters'   => $staleCount,
				'failed_clusters'  => $failedCount,
			],
			'clusters' => $clusterRows,
			'warnings' => $warnings,
		];
	}

	/**
	 * Queue, node, pending-reason, and account fair-share summary across
	 * permitted clusters.
	 *
	 * @param \Closure(Cluster): bool $clusterAllowed
	 *
	 * @return list<array{
	 *   clusterId: int,
	 *   clusterName: string,
	 *   freshness: string,
	 *   jobsRunning: int,
	 *   jobsPending: int,
	 *   jobsHeld: int,
	 *   nodesTotal: int,
	 *   nodesIdle: int,
	 *   nodesAllocated: int,
	 *   nodesDrained: int,
	 *   nodesDown: int,
	 *   shareAccounts: int,
	 *   topReasons: list<array{name: string, jobs: int}>
	 * }>
	 */
	public function queueSummaries(?int $clusterId, \Closure $clusterAllowed): array {
		$clusters   = $clusterId !== null
			? array_values(array_filter([$this->clusters->get($clusterId)]))
			: $this->clusters->all();
		$sections   = $this->view->sections();
		$jobStates  = $this->view->jobStates();
		$nodeStates = $this->view->nodeStates();
		$out        = [];

		foreach ($clusters as $cluster) {
			if (!$clusterAllowed($cluster)) {
				continue;
			}

			$jobs    = $jobStates[$cluster->id] ?? [];
			$nodes   = $nodeStates[$cluster->id] ?? [];
			$values  = ClusterValues::of($cluster, $sections[$cluster->id] ?? [], $jobs, $nodes, $this->evaluator, $this->pollerInterval);
			$reasons = $this->view->reasons($cluster->id, 10);
			$shares  = $this->shares->summary($cluster->id, 5);

			$topReasons = [];

			foreach ($reasons['reasons'] as $r) {
				$topReasons[] = ['name' => $r['name'], 'jobs' => $r['jobs']];
			}

			$nodesTotal = 0;

			foreach ($nodes as $stateKey => $cnt) {
				if ($stateKey !== 'drain_flag') {
					$nodesTotal += $cnt;
				}
			}

			$out[] = [
				'clusterId'      => $cluster->id,
				'clusterName'    => $cluster->name,
				'freshness'      => $values->freshness->value,
				'jobsRunning'    => $jobs['running'] ?? 0,
				'jobsPending'    => $jobs['pending'] ?? 0,
				'jobsHeld'       => $jobs['held'] ?? 0,
				'nodesTotal'     => $nodesTotal,
				'nodesIdle'      => $nodes['idle'] ?? 0,
				'nodesAllocated' => ($nodes['allocated'] ?? 0) + ($nodes['mixed'] ?? 0),
				'nodesDrained'   => $nodes['drain_flag'] ?? ($nodes['drained'] ?? 0),
				'nodesDown'      => $nodes['down'] ?? 0,
				'shareAccounts'  => $shares->accountCount,
				'topReasons'     => $topReasons,
			];
		}

		return $out;
	}
}
