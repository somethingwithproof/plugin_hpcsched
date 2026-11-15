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
 * A cluster's freshness and headline values, as the overview shows them now
 * and the data input will serve them (REQ-STATE-001, REQ-STATE-003).
 *
 * The cluster's snapshot age is the age of its oldest configured section.
 * A value whose section is stale or was never collected is null (U), never
 * zero; age stays numeric once anything was collected. Rendering a page
 * reads stored observation times only, so a page view is never an
 * observation (REQ-STATE-005).
 */
final readonly class ClusterValues {
	public const JOB_VALUES  = ['running', 'pending', 'held', 'completing'];
	public const NODE_VALUES = ['nodes_total', 'nodes_down', 'nodes_drained', 'nodes_idle', 'nodes_allocated', 'nodes_mixed'];

	/**
	 * @param array<string, ?int> $values
	 */
	public function __construct(
		public Freshness $freshness,
		public ?int $age,
		public array $values,
	) {
	}

	/**
	 * @param array<string, SectionRecord> $sections section value => record
	 * @param array<string, int>           $jobs     state => jobs
	 * @param array<string, int>           $nodes    state => nodes, plus drain_flag
	 */
	public static function of(Cluster $cluster, array $sections, array $jobs, array $nodes, FreshnessEvaluator $evaluator, int $pollerInterval): self {
		$oldest   = null;
		$missing  = false;
		$interval = $cluster->effectiveInterval($pollerInterval);

		foreach ($cluster->sections() as $section) {
			$observed = $sections[$section->value]->observedAt ?? 0;

			if ($observed <= 0) {
				$missing = true;

				continue;
			}

			$oldest = $oldest === null ? $observed : min($oldest, $observed);
		}

		$freshness = match (true) {
			$oldest === null => Freshness::Never,
			$missing         => Freshness::Stale,
			default          => $evaluator->evaluate($oldest, $interval),
		};

		$values = [];
		$jobsOk = self::usable($cluster, $sections, Section::Jobs, $evaluator, $pollerInterval);

		foreach (self::JOB_VALUES as $name) {
			$values[$name] = $jobsOk ? ($jobs[$name] ?? 0) : null;
		}

		$nodesOk = self::usable($cluster, $sections, Section::Nodes, $evaluator, $pollerInterval);
		$values += [
			'nodes_total'     => $nodesOk ? array_sum(array_diff_key($nodes, ['drain_flag' => 0])) : null,
			'nodes_down'      => $nodesOk ? ($nodes[NodeState::Down->value] ?? 0) : null,
			'nodes_drained'   => $nodesOk ? ($nodes['drain_flag'] ?? 0) : null,
			'nodes_idle'      => $nodesOk ? ($nodes[NodeState::Idle->value] ?? 0) : null,
			'nodes_allocated' => $nodesOk ? ($nodes[NodeState::Allocated->value] ?? 0) : null,
			'nodes_mixed'     => $nodesOk ? ($nodes[NodeState::Mixed->value] ?? 0) : null,
		];

		return new self($freshness, $oldest === null ? null : $evaluator->age($oldest), $values);
	}

	/**
	 * Whether a section's stored rows may be served: current or delayed on
	 * the longer of the cluster's interval and the one they were collected on.
	 *
	 * @param array<string, SectionRecord> $sections section value => record
	 */
	public static function usable(Cluster $cluster, array $sections, Section $section, FreshnessEvaluator $evaluator, int $pollerInterval): bool {
		$record = $sections[$section->value] ?? null;

		return $record !== null && $evaluator->evaluate($record->observedAt, max($cluster->effectiveInterval($pollerInterval), $record->interval))->usable();
	}
}
