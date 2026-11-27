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
 * Aggregates terminal job exit outcomes (OOM, TIMEOUT, NODE_FAIL, PREEMPTED,
 * CANCELLED, FAILED, COMPLETED) per partition and cluster-wide. No job name,
 * user, or account is ever retained (ADR 0010).
 */
final class JobExitTally {
	/** Cluster aggregate partition key. */
	public const CLUSTER = '';

	/**
	 * Counts indexed by prefixed partition key ("p:<name>") and JobExitReason value.
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $counts = [];

	/**
	 * Record one or more terminal jobs under the cluster total and each unique
	 * partition name.
	 *
	 * @param list<string>  $partitions job partition list
	 * @param JobExitReason $outcome    classified terminal outcome
	 * @param int           $count      number of jobs/tasks (>= 1)
	 *
	 * @return void
	 */
	public function add(array $partitions, JobExitReason $outcome, int $count = 1): void {
		if ($count < 1) {
			return;
		}

		$code = $outcome->value;

		$this->counts['p:'][$code] = ($this->counts['p:'][$code] ?? 0) + $count;

		if ($partitions === []) {
			return;
		}

		if (count($partitions) === 1) {
			$key = 'p:' . $partitions[0];

			if ($key !== 'p:') {
				$this->counts[$key][$code] = ($this->counts[$key][$code] ?? 0) + $count;
			}

			return;
		}

		$seen = ['' => true];

		foreach ($partitions as $partition) {
			if (isset($seen[$partition])) {
				continue;
			}

			$seen[$partition]          = true;
			$key                       = 'p:' . $partition;
			$this->counts[$key][$code] = ($this->counts[$key][$code] ?? 0) + $count;
		}
	}

	/**
	 * Return the count of one exit outcome for a partition or cluster ('').
	 *
	 * @param string        $partition partition name, or '' for cluster total
	 * @param JobExitReason $outcome   terminal exit outcome
	 *
	 * @return int
	 */
	public function count(string $partition, JobExitReason $outcome): int {
		return $this->counts['p:' . $partition][$outcome->value] ?? 0;
	}

	/**
	 * Return the complete exit-reason breakdown for a partition or cluster (''),
	 * with all seven canonical outcome keys present.
	 *
	 * @param string $partition partition name, or '' for cluster total
	 *
	 * @return array{OOM: int, TIMEOUT: int, NODE_FAIL: int, PREEMPTED: int, CANCELLED: int, FAILED: int, COMPLETED: int}
	 */
	public function breakdown(string $partition = self::CLUSTER): array {
		$bucket = $this->counts['p:' . $partition] ?? [];

		return [
			'OOM'       => $bucket[JobExitReason::Oom->value] ?? 0,
			'TIMEOUT'   => $bucket[JobExitReason::Timeout->value] ?? 0,
			'NODE_FAIL' => $bucket[JobExitReason::NodeFail->value] ?? 0,
			'PREEMPTED' => $bucket[JobExitReason::Preempted->value] ?? 0,
			'CANCELLED' => $bucket[JobExitReason::Cancelled->value] ?? 0,
			'FAILED'    => $bucket[JobExitReason::Failed->value] ?? 0,
			'COMPLETED' => $bucket[JobExitReason::Completed->value] ?? 0,
		];
	}

	/**
	 * Return total terminal jobs counted for a partition or cluster ('').
	 *
	 * @param string $partition partition name, or '' for cluster total
	 *
	 * @return int
	 */
	public function total(string $partition = self::CLUSTER): int {
		return array_sum($this->counts['p:' . $partition] ?? []);
	}

	/**
	 * Return the fraction of non-COMPLETED terminal jobs in [0.0, 1.0].
	 *
	 * @param string $partition partition name, or '' for cluster total
	 *
	 * @return float
	 */
	public function failureRate(string $partition = self::CLUSTER): float {
		$total = $this->total($partition);

		if ($total === 0) {
			return 0.0;
		}

		$completed = $this->count($partition, JobExitReason::Completed);

		return round(($total - $completed) / $total, 6);
	}

	/**
	 * Return sorted partition names that have recorded terminal outcomes.
	 *
	 * @return list<string>
	 */
	public function partitions(): array {
		$names = [];

		foreach ($this->counts as $key => $_bucket) {
			if ($key !== 'p:') {
				$names[] = substr($key, 2);
			}
		}

		sort($names);

		return $names;
	}

	/**
	 * Export every non-zero exit-reason count as flat rows.
	 *
	 * @return list<array{partition: string, outcome: string, jobs: int}>
	 */
	public function rows(): array {
		$rows = [];

		foreach ($this->counts as $key => $outcomes) {
			$partition = substr($key, 2);

			foreach ($outcomes as $outcome => $jobs) {
				$rows[] = ['partition' => $partition, 'outcome' => $outcome, 'jobs' => $jobs];
			}
		}

		return $rows;
	}

	/**
	 * Export cluster and per-partition exit breakdowns as a structured array.
	 *
	 * @return array{
	 *   cluster: array{OOM: int, TIMEOUT: int, NODE_FAIL: int, PREEMPTED: int, CANCELLED: int, FAILED: int, COMPLETED: int},
	 *   partitions: array<string, array{OOM: int, TIMEOUT: int, NODE_FAIL: int, PREEMPTED: int, CANCELLED: int, FAILED: int, COMPLETED: int}>
	 * }
	 */
	public function toArray(): array {
		$partitions = [];

		foreach ($this->partitions() as $name) {
			$partitions[$name] = $this->breakdown($name);
		}

		return [
			'cluster'    => $this->breakdown(self::CLUSTER),
			'partitions' => $partitions,
		];
	}
}
