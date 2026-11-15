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
 * The values the cluster and partition data sources store, by graph, and how
 * a snapshot becomes them (ADR 0011, REQ-QUEUE-001 to REQ-QUEUE-005,
 * REQ-REASON-001, REQ-NODEAGG-001 to REQ-NODEAGG-003).
 *
 * Each graph has a data source of its own holding exactly the values it
 * draws, because Cacti 1.2 stores in an RRD file only the values a graph
 * draws when the file is created. Names are at most 19 characters, Cacti's
 * limit for a data source name.
 *
 * A value whose section cannot be vouched for is null (U), never zero. A
 * utilization with no CPUs or nodes behind it is null too.
 */
final class Series {
	/** Job series: the normalized states, Other renamed to keep it apart from the other Others. */
	public const JOBS = ['pending', 'held', 'running', 'completing', 'jobs_other'];

	public const UTILIZATION = ['cpu_util_pct', 'node_util_pct', 'nodes_alloc', 'nodes_total'];

	/** Seconds since the cluster's oldest configured section was observed. */
	public const AGE = 'age';

	/** Index names the partition data query accepts, as the parser keeps them. */
	public const PARTITION_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

	/**
	 * Values per graph, the same for the cluster and for each partition.
	 *
	 * @return array<string, list<string>> graph => value names
	 */
	public static function groups(): array {
		return [
			'Jobs'            => self::JOBS,
			'Pending Reasons' => self::reasons(),
			'Nodes'           => NodeTally::STATES,
			'CPUs'            => NodeTally::CPUS,
			'Utilization'     => self::UTILIZATION,
		];
	}

	/**
	 * @return list<string>
	 */
	public static function reasons(): array {
		return array_map(static fn (ReasonFamily $f): string => 'r_' . $f->value, ReasonFamily::cases());
	}

	/**
	 * Every per-partition value name.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		return array_merge(...array_values(self::groups()));
	}

	/**
	 * @param array<string, int> $jobs  job_count names: state values and family values
	 * @param array<string, int> $nodes NodeTally names
	 *
	 * @return array<string, ?float> value name => value, null for U
	 */
	public static function values(bool $jobsUsable, bool $nodesUsable, array $jobs, array $nodes): array {
		$out = [];

		foreach (JobState::cases() as $state) {
			$out[$state === JobState::Other ? 'jobs_other' : $state->value] = $jobsUsable ? (float) ($jobs['state:' . $state->value] ?? 0) : null;
		}

		foreach (ReasonFamily::cases() as $family) {
			$out['r_' . $family->value] = $jobsUsable ? (float) ($jobs['family:' . $family->value] ?? 0) : null;
		}

		foreach (array_merge(NodeTally::STATES, NodeTally::CPUS, NodeTally::NODES) as $name) {
			$out[$name] = $nodesUsable ? (float) ($nodes[$name] ?? 0) : null;
		}

		$out['cpu_util_pct']  = self::percent($out['cpus_alloc'], $out['cpus_total']);
		$out['node_util_pct'] = self::percent($out['nodes_alloc'], $out['nodes_total']);

		return $out;
	}

	/**
	 * A value as Cacti stores it: U for unknown, else a plain decimal.
	 */
	public static function format(?float $value): string {
		if ($value === null || !is_finite($value)) {
			return 'U';
		}

		return floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
	}

	private static function percent(?float $part, ?float $whole): ?float {
		return $part === null || $whole === null || $whole <= 0 ? null : round(100 * $part / $whole, 2);
	}
}
