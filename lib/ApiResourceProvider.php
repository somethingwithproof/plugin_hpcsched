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
	 * Construct a read-only API resource provider backed by a HealthEvaluator
	 * and a user-scoped View realm permission callback.
	 *
	 * @param HealthEvaluator     $evaluator read-only scheduler health evaluator
	 * @param \Closure(int): bool $canView   returns true when user holds HPC Scheduler View realm
	 */
	public function __construct(
		private readonly HealthEvaluator $evaluator,
		private readonly \Closure $canView,
	) {
	}

	/**
	 * Build read-only Google AIP resource descriptors for HPC Scheduler
	 * entities. Token file paths and secret contents are excluded, and share
	 * resources expose account-level associations only (ADR 0010).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function resources(): array {
		return [
			[
				'plugin'        => 'hpcsched',
				'collection'    => 'hpcschedClusters',
				'singleton'     => 'hpcschedCluster',
				'description'   => 'Read-only configured HPC scheduler clusters with freshness and status.',
				'realm_read'    => 'hpcsched.php',
				'read_only'     => true,
				'schema'        => 'schema/hpcsched.schema.yaml',
				'filter_fields' => ['id', 'name', 'display_name', 'poller_id', 'admin_state', 'transport', 'status'],
				'order_fields'  => ['id', 'name', 'display_name', 'poller_id'],
				'handlers'      => [
					/**
					 * @param array<string, mixed> $context
					 * @return list<array<string, mixed>>
					 */
					'list' => function (array $context = []): array {
						$userId = isset($context['user_id']) && is_int($context['user_id']) ? $context['user_id'] : 0;

						if ($userId < 1 || !($this->canView)($userId)) {
							return [];
						}

						$report = $this->evaluator->evaluate();
						$items  = [];

						foreach ($report['clusters'] as $row) {
							$items[] = self::serializeCluster($row);
						}

						return $items;
					},
					/**
					 * @param string               $resourceId
					 * @param array<string, mixed> $context
					 * @return array<string, mixed>|null
					 */
					'get' => function (string $resourceId, array $context = []): ?array {
						$userId = isset($context['user_id']) && is_int($context['user_id']) ? $context['user_id'] : 0;

						if ($userId < 1 || !($this->canView)($userId)) {
							return null;
						}

						$rawId = str_starts_with($resourceId, 'hpcschedClusters/') ? substr($resourceId, strlen('hpcschedClusters/')) : $resourceId;

						if ($rawId === '' || !ctype_digit($rawId)) {
							return null;
						}

						$targetId = (int) $rawId;
						$report   = $this->evaluator->evaluate();

						foreach ($report['clusters'] as $row) {
							if (Row::int($row, 'id') === $targetId) {
								return self::serializeCluster($row);
							}
						}

						return null;
					},
				],
			],
		];
	}

	/**
	 * Serialize a cluster health row into a canonical API resource record
	 * without exposing token file paths or credentials.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function serializeCluster(array $row): array {
		$id = Row::int($row, 'id');

		return [
			'name'             => 'hpcschedClusters/' . $id,
			'id'               => $id,
			'display_name'     => Row::string($row, 'name'),
			'poller_id'        => Row::int($row, 'poller_id'),
			'host_id'          => Row::int($row, 'host_id'),
			'admin_state'      => Row::string($row, 'admin_state'),
			'transport'        => Row::string($row, 'transport'),
			'collect_interval' => Row::int($row, 'collect_interval'),
			'status'           => Row::string($row, 'status'),
			'freshness'        => Row::string($row, 'freshness'),
			'outcome'          => Row::string($row, 'outcome'),
			'slurm_release'    => Row::string($row, 'slurm_release'),
		];
	}
}
