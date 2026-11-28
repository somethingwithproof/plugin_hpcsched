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
 * Registers read-only HPC Scheduler abilities for Cacti MCP / Abilities API
 * v2 via the abilities_register hook. All abilities are read-only,
 * non-destructive, idempotent, View-realm gated, and never disclose token
 * files or contact schedulers.
 */
final class AbilitiesProvider {
	/** Annotations constant for AbilitiesProvider. */
	private const ANNOTATIONS = [
		'readOnly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	/**
	 * @param \Closure(int): bool          $canView
	 * @param \Closure(int, Cluster): bool $clusterAllowed
	 */
	public function __construct(
		private readonly HealthEvaluator $evaluator,
		private readonly \Closure $canView,
		private readonly \Closure $clusterAllowed,
	) {
	}

	/**
	 * Register.
	 *
	 * @param object $registrar
	 * @return void
	 */
	public function register(object $registrar): void {
		if (!method_exists($registrar, 'register')) {
			return;
		}

		if (method_exists($registrar, 'registerCategory')) {
			try {
				$registrar->registerCategory('hpcsched', 'HPC Scheduler');
			} catch (\Throwable) {
				// Category may already be registered.
			}
		}

		$permission = fn (int $userId): bool => ($this->canView)($userId);

		$registrar->register('hpcsched-list-clusters', [
			'label'         => 'List HPC scheduler clusters',
			'description'   => 'List configured Slurm clusters with poller affinity, transport, freshness, and last run status. Token paths and secrets are excluded.',
			'category'      => 'hpcsched',
			'input_schema'  => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'clusters' => [
						'type'     => 'array',
						'maxItems' => 256,
						'items'    => [
							'type'                 => 'object',
							'properties'           => [
								'id'              => ['type' => 'integer'],
								'name'            => ['type' => 'string'],
								'pollerId'        => ['type' => 'integer'],
								'hostId'          => ['type' => 'integer'],
								'adminState'      => ['type' => 'string'],
								'transport'       => ['type' => 'string'],
								'collectInterval' => ['type' => 'integer'],
								'status'          => ['type' => 'string'],
								'freshness'       => ['type' => 'string'],
								'outcome'         => ['type' => 'string'],
								'slurmRelease'    => ['type' => 'string'],
							],
							'required'             => ['id', 'name', 'pollerId', 'hostId', 'adminState', 'transport', 'collectInterval', 'status', 'freshness', 'outcome', 'slurmRelease'],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => ['clusters'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permission,
			'execute'     => function (): array {
				$report = $this->evaluator->evaluate();
				$items  = [];

				foreach (array_slice($report['clusters'], 0, 256) as $row) {
					$items[] = [
						'id'              => Row::int($row, 'id'),
						'name'            => Row::string($row, 'name'),
						'pollerId'        => Row::int($row, 'poller_id'),
						'hostId'          => Row::int($row, 'host_id'),
						'adminState'      => Row::string($row, 'admin_state'),
						'transport'       => Row::string($row, 'transport'),
						'collectInterval' => Row::int($row, 'collect_interval'),
						'status'          => Row::string($row, 'status'),
						'freshness'       => Row::string($row, 'freshness'),
						'outcome'         => Row::string($row, 'outcome'),
						'slurmRelease'    => Row::string($row, 'slurm_release'),
					];
				}

				return ['clusters' => $items];
			},
		]);

		$registrar->register('hpcsched-get-queue-summary', [
			'label'         => 'Get HPC queue summary',
			'description'   => 'Read aggregate running/pending/held job counts, node state counts, top pending reasons, and account fair-share summary counts for permitted clusters.',
			'category'      => 'hpcsched',
			'input_schema'  => [
				'type'                 => 'object',
				'properties'           => [
					'clusterId' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647],
				],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'summaries' => [
						'type'     => 'array',
						'maxItems' => 256,
						'items'    => [
							'type'                 => 'object',
							'properties'           => [
								'clusterId'      => ['type' => 'integer'],
								'clusterName'    => ['type' => 'string'],
								'freshness'      => ['type' => 'string'],
								'jobsRunning'    => ['type' => 'integer'],
								'jobsPending'    => ['type' => 'integer'],
								'jobsHeld'       => ['type' => 'integer'],
								'nodesTotal'     => ['type' => 'integer'],
								'nodesIdle'      => ['type' => 'integer'],
								'nodesAllocated' => ['type' => 'integer'],
								'nodesDrained'   => ['type' => 'integer'],
								'nodesDown'      => ['type' => 'integer'],
								'shareAccounts'  => ['type' => 'integer'],
								'topReasons'     => [
									'type'     => 'array',
									'maxItems' => 20,
									'items'    => [
										'type'                 => 'object',
										'properties'           => [
											'name' => ['type' => 'string'],
											'jobs' => ['type' => 'integer'],
										],
										'required'             => ['name', 'jobs'],
										'additionalProperties' => false,
									],
								],
							],
							'required'             => ['clusterId', 'clusterName', 'freshness', 'jobsRunning', 'jobsPending', 'jobsHeld', 'nodesTotal', 'nodesIdle', 'nodesAllocated', 'nodesDrained', 'nodesDown', 'shareAccounts', 'topReasons'],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => ['summaries'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permission,
			'execute'     => function (int $userId, \stdClass $args): array {
				$clusterId = isset($args->clusterId) && is_int($args->clusterId) ? $args->clusterId : null;
				$allowed   = fn (Cluster $cluster): bool => ($this->clusterAllowed)($userId, $cluster);

				return [
					'summaries' => array_slice($this->evaluator->queueSummaries($clusterId, $allowed), 0, 256),
				];
			},
		]);

		$registrar->register('hpcsched-get-health', [
			'label'         => 'Get HPC scheduler health',
			'description'   => 'Read overall HPC Scheduler health, schema status, cluster freshness, Maint sync status, and warnings.',
			'category'      => 'hpcsched',
			'input_schema'  => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'status'          => ['type' => 'string', 'enum' => ['pass', 'warn', 'fail']],
					'schemaCurrent'   => ['type' => 'boolean'],
					'clustersTotal'   => ['type' => 'integer'],
					'enabledClusters' => ['type' => 'integer'],
					'remoteClusters'  => ['type' => 'integer'],
					'staleClusters'   => ['type' => 'integer'],
					'failedClusters'  => ['type' => 'integer'],
					'maintAvailable'  => ['type' => 'boolean'],
					'maintLinks'      => ['type' => 'integer'],
					'warnings'        => [
						'type'     => 'array',
						'maxItems' => 100,
						'items'    => ['type' => 'string'],
					],
				],
				'required'             => ['status', 'schemaCurrent', 'clustersTotal', 'enabledClusters', 'remoteClusters', 'staleClusters', 'failedClusters', 'maintAvailable', 'maintLinks', 'warnings'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permission,
			'execute'     => function (): array {
				$report = $this->evaluator->evaluate();

				return [
					'status'          => $report['status'],
					'schemaCurrent'   => $report['schema_current'],
					'clustersTotal'   => $report['summary']['clusters'],
					'enabledClusters' => $report['summary']['enabled_clusters'],
					'remoteClusters'  => $report['summary']['remote_clusters'],
					'staleClusters'   => $report['summary']['stale_clusters'],
					'failedClusters'  => $report['summary']['failed_clusters'],
					'maintAvailable'  => $report['maint_available'],
					'maintLinks'      => $report['maint_links'],
					'warnings'        => array_slice($report['warnings'], 0, 100),
				];
			},
		]);
	}
}
