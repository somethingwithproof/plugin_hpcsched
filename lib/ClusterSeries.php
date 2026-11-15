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

	/**
	 * The stored rows per device, read at most once per CACHE_SECONDS.
	 * Freshness and age are worked out from them on every answer, so a
	 * section that turns stale inside that window answers U at once.
	 *
	 * @var array<int, array{at: int, cluster: ?Cluster, sections: array<string, SectionRecord>, partitions: list<string>, jobs: array<string, array<string, int>>, nodes: array<string, array<string, int>>}>
	 */
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
	 * @return array{partitions: list<string>, values: array<string, array<string, ?float>>, age: ?int}
	 */
	private function load(int $hostId): array {
		$raw  = $this->rows($hostId);
		$data = ['partitions' => $raw['partitions'], 'values' => [], 'age' => null];

		if ($raw['cluster'] === null) {
			return $data;
		}

		$cluster  = $raw['cluster'];
		$sections = $raw['sections'];
		$jobsOk   = ClusterValues::usable($cluster, $sections, Section::Jobs, $this->evaluator, $this->pollerInterval);
		$nodesOk  = ClusterValues::usable($cluster, $sections, Section::Nodes, $this->evaluator, $this->pollerInterval);

		$data['age'] = ClusterValues::of($cluster, $sections, [], [], $this->evaluator, $this->pollerInterval)->age;

		foreach (array_merge([''], $raw['partitions']) as $partition) {
			$data['values']["p:$partition"] = Series::values($jobsOk, $nodesOk, $raw['jobs']["p:$partition"] ?? [], $raw['nodes']["p:$partition"] ?? []);
		}

		return $data;
	}

	/**
	 * The device's stored rows, from the cache while it is younger than
	 * CACHE_SECONDS. Counts are read only for a section usable at read
	 * time: one that is not cannot become usable again until the next
	 * collection, which a later read picks up.
	 *
	 * @return array{at: int, cluster: ?Cluster, sections: array<string, SectionRecord>, partitions: list<string>, jobs: array<string, array<string, int>>, nodes: array<string, array<string, int>>}
	 */
	private function rows(int $hostId): array {
		$now = $this->clock->now();

		if (isset($this->cache[$hostId]) && $now >= $this->cache[$hostId]['at'] && $now - $this->cache[$hostId]['at'] < self::CACHE_SECONDS) {
			return $this->cache[$hostId];
		}

		$cluster = $this->clusters->findByHost($hostId);
		$raw     = ['at' => $now, 'cluster' => $cluster, 'sections' => [], 'partitions' => [], 'jobs' => [], 'nodes' => []];

		if ($cluster !== null) {
			$raw['sections']   = $this->series->sections($cluster->id);
			$raw['partitions'] = $this->series->partitions($cluster->id);
			$raw['jobs']       = ClusterValues::usable($cluster, $raw['sections'], Section::Jobs, $this->evaluator, $this->pollerInterval) ? $this->series->jobs($cluster->id) : [];
			$raw['nodes']      = ClusterValues::usable($cluster, $raw['sections'], Section::Nodes, $this->evaluator, $this->pollerInterval) ? $this->series->nodes($cluster->id) : [];
		}

		return $this->cache[$hostId] = $raw;
	}

	/**
	 * @param list<string> $lines
	 */
	private static function lines(array $lines): string {
		return $lines === [] ? '' : implode("\n", $lines) . "\n";
	}
}
