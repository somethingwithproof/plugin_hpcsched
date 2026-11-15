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
 * Answers the two script server inputs of a cluster device from the
 * snapshot, never from the scheduler (REQ-OBJ-005, REQ-PERF-004):
 *
 * - the "HPC Scheduler Cluster Values" input: every cluster value and age as
 *   name:value pairs in one call (scripts/ss_hpcsched_cluster.php);
 * - the "HPC Scheduler Partitions" data query: index, num_indexes, query,
 *   and get (scripts/ss_hpcsched_partitions.php).
 *
 * Every argument arrives from Cacti's command line or the script server, so
 * each is checked against a fixed list: the field against the value names,
 * the index against the cluster's partitions. Anything else is answered
 * with U or nothing, never echoed back. Counts are U while their section is
 * stale or failed (REQ-QUEUE-005, REQ-ALERT-001); age is numeric once the
 * cluster has been collected (REQ-ALERT-002).
 */
final class ClusterSeries {
	public const NAME_FIELD = 'name';

	/** The script server answers a whole poll cycle from one process. */
	private const CACHE_SECONDS = 30;

	/** @var array<int, array{at: int, cluster: ?Cluster, partitions: list<string>, values: array<string, array<string, ?float>>, age: ?int}> */
	private array $cache = [];

	public function __construct(
		private readonly ClusterRepository $clusters,
		private readonly SeriesRepository $series,
		private readonly FreshnessEvaluator $evaluator,
		private readonly Clock $clock,
		private readonly int $pollerInterval,
	) {
	}

	public function cluster(int $hostId): string {
		$data   = $this->load($hostId);
		$values = $data['values']['p:'] ?? array_fill_keys(Series::names(), null);
		$pairs  = [];

		foreach (Series::names() as $name) {
			$pairs[] = $name . ':' . Series::format($values[$name] ?? null);
		}

		$pairs[] = Series::AGE . ':' . Series::format($data['age'] === null ? null : (float) $data['age']);

		return implode(' ', $pairs);
	}

	public function partitions(int $hostId, string $command, string $field = '', string $index = ''): string {
		$data = $this->load($hostId);

		return match ($command) {
			'index'       => self::lines($data['partitions']),
			'num_indexes' => (string) count($data['partitions']),
			'query'       => $this->query($data, $field),
			'get'         => $this->get($data, $field, $index),
			default       => '',
		};
	}

	/**
	 * @param array{partitions: list<string>, values: array<string, array<string, ?float>>} $data
	 */
	private function query(array $data, string $field): string {
		if ($field !== self::NAME_FIELD && !in_array($field, Series::names(), true)) {
			return '';
		}

		$lines = [];

		foreach ($data['partitions'] as $partition) {
			$lines[] = $partition . '!' . ($field === self::NAME_FIELD ? $partition : Series::format($data['values']["p:$partition"][$field] ?? null));
		}

		return self::lines($lines);
	}

	/**
	 * @param array{partitions: list<string>, values: array<string, array<string, ?float>>} $data
	 */
	private function get(array $data, string $field, string $index): string {
		if (!in_array($field, Series::names(), true) || !in_array($index, $data['partitions'], true)) {
			return 'U';
		}

		return Series::format($data['values']["p:$index"][$field] ?? null);
	}

	/**
	 * @return array{at: int, cluster: ?Cluster, partitions: list<string>, values: array<string, array<string, ?float>>, age: ?int}
	 */
	private function load(int $hostId): array {
		$now = $this->clock->now();

		if (isset($this->cache[$hostId]) && $now >= $this->cache[$hostId]['at'] && $now - $this->cache[$hostId]['at'] < self::CACHE_SECONDS) {
			return $this->cache[$hostId];
		}

		$cluster = $this->clusters->findByHost($hostId);
		$data    = ['at' => $now, 'cluster' => $cluster, 'partitions' => [], 'values' => [], 'age' => null];

		if ($cluster !== null) {
			$sections = $this->series->sections($cluster->id);
			$jobsOk   = ClusterValues::usable($cluster, $sections, Section::Jobs, $this->evaluator, $this->pollerInterval);
			$nodesOk  = ClusterValues::usable($cluster, $sections, Section::Nodes, $this->evaluator, $this->pollerInterval);
			$jobs     = $jobsOk ? $this->series->jobs($cluster->id) : [];
			$nodes    = $nodesOk ? $this->series->nodes($cluster->id) : [];

			$data['partitions'] = $this->series->partitions($cluster->id);
			$data['age']        = ClusterValues::of($cluster, $sections, [], [], $this->evaluator, $this->pollerInterval)->age;

			foreach (array_merge([''], $data['partitions']) as $partition) {
				$data['values']["p:$partition"] = Series::values($jobsOk, $nodesOk, $jobs["p:$partition"] ?? [], $nodes["p:$partition"] ?? []);
			}
		}

		return $this->cache[$hostId] = $data;
	}

	/**
	 * @param list<string> $lines
	 */
	private static function lines(array $lines): string {
		return $lines === [] ? '' : implode("\n", $lines) . "\n";
	}
}
