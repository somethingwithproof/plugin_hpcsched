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
 * Job counts for one snapshot: by normalized state, pending jobs by reason
 * family and by raw reason, each per partition and for the cluster. Jobs
 * themselves are never kept, so no job name, user, or account leaves the
 * parser (ADR 0010).
 *
 * Partition '' is the cluster total. A job submitted to several partitions
 * counts once there and once in each partition it names; a pending array
 * counts as its number of tasks.
 */
final class JobTally {
	/** Cluster constant for JobTally. */
	public const CLUSTER = '';

	/** @var array<string, array<string, int>> partition => state => jobs */
	private array $states = [];

	/** @var array<string, array<string, int>> partition => family => pending jobs */
	private array $families = [];

	/** @var array<string, array<string, int>> partition => raw reason => pending jobs */
	private array $reasons = [];

	/**
	 * @param list<string> $partitions
	 */
	public function add(array $partitions, JobState $state, string $reason, int $count): void {
		$pending   = $state === JobState::Pending || $state === JobState::Held;
		$stateName = $state->value;
		$norm      = '';
		$family    = '';

		if ($pending) {
			$norm   = Reasons::name($reason);
			$family = ReasonFamily::of($norm)->value;
		}

		$this->states['p:'][$stateName] = ($this->states['p:'][$stateName] ?? 0) + $count;

		if ($pending) {
			$this->families['p:'][$family] = ($this->families['p:'][$family] ?? 0) + $count;
			$this->reasons['p:'][$norm]    = ($this->reasons['p:'][$norm] ?? 0) + $count;
		}

		$nPart = count($partitions);

		if ($nPart === 0) {
			return;
		}

		if ($nPart === 1) {
			if ($partitions[0] === self::CLUSTER) {
				return;
			}

			$key                             = 'p:' . $partitions[0];
			$this->states[$key][$stateName]  = ($this->states[$key][$stateName] ?? 0) + $count;

			if ($pending) {
				$this->families[$key][$family] = ($this->families[$key][$family] ?? 0) + $count;
				$this->reasons[$key][$norm]    = ($this->reasons[$key][$norm] ?? 0) + $count;
			}

			return;
		}

		$seen = [self::CLUSTER => true];

		foreach ($partitions as $partition) {
			if (isset($seen[$partition])) {
				continue;
			}

			$seen[$partition]               = true;
			$key                            = 'p:' . $partition;
			$this->states[$key][$stateName] = ($this->states[$key][$stateName] ?? 0) + $count;

			if ($pending) {
				$this->families[$key][$family] = ($this->families[$key][$family] ?? 0) + $count;
				$this->reasons[$key][$norm]    = ($this->reasons[$key][$norm] ?? 0) + $count;
			}
		}
	}

	/**
	 * Every count as rows for the job count table. Keys carry a prefix while
	 * counting so a partition or reason named like a number stays a string.
	 *
	 * @return list<array{partition: string, kind: string, name: string, jobs: int}>
	 */
	public function rows(): array {
		$rows = [];

		foreach (['state' => $this->states, 'family' => $this->families, 'reason' => $this->reasons] as $kind => $counts) {
			foreach ($counts as $key => $names) {
				foreach ($names as $name => $jobs) {
					$rows[] = ['partition' => substr((string) $key, 2), 'kind' => $kind, 'name' => (string) $name, 'jobs' => $jobs];
				}
			}
		}

		return $rows;
	}

	/**
	 * Count.
	 *
	 * @param string $partition
	 * @param JobState $state
	 * @return int
	 */
	public function count(string $partition, JobState $state): int {
		return $this->states["p:$partition"][$state->value] ?? 0;
	}

	/**
	 * Pending in.
	 *
	 * @param string $partition
	 * @param ReasonFamily $family
	 * @return int
	 */
	public function pendingIn(string $partition, ReasonFamily $family): int {
		return $this->families["p:$partition"][$family->value] ?? 0;
	}

	/**
	 * Total.
	 *
	 * @return int
	 */
	public function total(): int {
		return array_sum($this->states['p:'] ?? []);
	}
}
