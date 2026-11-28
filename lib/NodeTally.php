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
 * Node and CPU counts per partition, made from the nodes section when it is
 * written, so the data inputs read a few rows per partition instead of every
 * node (ADR 0011).
 *
 * Partition '' is the cluster: a node in several partitions counts once
 * there, by name, and once in each partition. CPUs follow sinfo's %C split:
 * allocated, idle, other, and total, where other is the unallocated CPUs of
 * nodes that cannot take work (down, drained or draining, maintenance,
 * powered down, or a state the plugin does not know). The utilization
 * denominator is the total, other included.
 */
final class NodeTally {
	/** Node state series, each normalized state in exactly one. */
	public const STATES = ['n_idle', 'n_allocated', 'n_mixed', 'n_drained', 'n_down', 'n_maint', 'n_powered_down', 'n_other'];

	/** Cpus constant for NodeTally. */
	public const CPUS = ['cpus_alloc', 'cpus_idle', 'cpus_other', 'cpus_total'];

	/** Nodes constant for NodeTally. */
	public const NODES = ['nodes_alloc', 'nodes_total'];

	/** GPU TRES gauge series names. */
	public const GPUS = ['alloc_gpus', 'idle_gpus', 'drained_gpus', 'total_gpus'];

	/** Memory TRES gauge series names (MiB). */
	public const MEMORY = ['alloc_mem_mib', 'total_mem_mib', 'free_mem_mib'];

	/**
	 * Series.
	 *
	 * @param NodeState $state
	 * @return string
	 */
	public static function series(NodeState $state): string {
		return match ($state) {
			NodeState::Idle                           => 'n_idle',
			NodeState::Allocated                      => 'n_allocated',
			NodeState::Mixed                          => 'n_mixed',
			NodeState::Drained, NodeState::Draining   => 'n_drained',
			NodeState::Down                           => 'n_down',
			NodeState::Maintenance, NodeState::Reserved => 'n_maint',
			NodeState::PoweredDown                    => 'n_powered_down',
			NodeState::Other                          => 'n_other',
		};
	}

	/**
	 * @param list<NodeRecord> $nodes
	 *
	 * @return list<array{partition: string, name: string, value: int}>
	 */
	public static function rows(array $nodes): array {
		$byName = [];

		// A node listed twice keeps its last record, as the node table does.
		foreach ($nodes as $node) {
			$byName['n:' . $node->name] = $node;
		}

		/** @var array<string, array<string, int>> $counts */
		$counts = [];

		foreach ($byName as $node) {
			$total     = max(0, $node->cpus);
			$allocated = min($total, max(0, $node->allocCpus));
			$state     = $node->state;
			$working   = $state === NodeState::Idle || $state === NodeState::Allocated || $state === NodeState::Mixed || $state === NodeState::Reserved;
			$idle      = $working ? $total - $allocated : 0;
			$other     = $working ? 0 : $total - $allocated;
			$nodeAlloc = ($allocated > 0 || $state === NodeState::Allocated || $state === NodeState::Mixed) ? 1 : 0;
			$seriesKey = self::series($state);

			self::accumulateNode($counts, 'p:', $seriesKey, $allocated, $idle, $other, $total, $nodeAlloc);

			$parts = $node->partitions;
			$nPart = count($parts);

			if ($nPart === 1) {
				if ($parts[0] !== '') {
					self::accumulateNode($counts, 'p:' . $parts[0], $seriesKey, $allocated, $idle, $other, $total, $nodeAlloc);
				}
			} elseif ($nPart > 1) {
				$seen = ['' => true];

				foreach ($parts as $partition) {
					if (isset($seen[$partition])) {
						continue;
					}

					$seen[$partition] = true;
					self::accumulateNode($counts, 'p:' . $partition, $seriesKey, $allocated, $idle, $other, $total, $nodeAlloc);
				}
			}
		}

		$rows = [];

		foreach ($counts as $key => $names) {
			$partition = substr($key, 2);

			foreach ($names as $name => $value) {
				$rows[] = ['partition' => $partition, 'name' => (string) $name, 'value' => $value];
			}
		}

		return $rows;
	}

	/**
	 * Aggregate CPU, GPU/GRES, and RAM TRES gauges per partition and
	 * cluster-wide ('') in a single linear pass over deduplicated nodes.
	 *
	 * @param list<NodeRecord> $nodes parsed cluster nodes
	 *
	 * @return array{
	 *   cluster: array{
	 *     nodes_alloc: int,
	 *     nodes_total: int,
	 *     cpus_alloc: int,
	 *     cpus_idle: int,
	 *     cpus_other: int,
	 *     cpus_total: int,
	 *     alloc_gpus: int,
	 *     idle_gpus: int,
	 *     drained_gpus: int,
	 *     total_gpus: int,
	 *     alloc_mem_mib: int,
	 *     total_mem_mib: int,
	 *     free_mem_mib: int
	 *   },
	 *   partitions: array<string, array{
	 *     nodes_alloc: int,
	 *     nodes_total: int,
	 *     cpus_alloc: int,
	 *     cpus_idle: int,
	 *     cpus_other: int,
	 *     cpus_total: int,
	 *     alloc_gpus: int,
	 *     idle_gpus: int,
	 *     drained_gpus: int,
	 *     total_gpus: int,
	 *     alloc_mem_mib: int,
	 *     total_mem_mib: int,
	 *     free_mem_mib: int
	 *   }>
	 * }
	 */
	public static function resourceSummary(array $nodes): array {
		$byName = [];

		foreach ($nodes as $node) {
			$byName['n:' . $node->name] = $node;
		}

		$empty = [
			'nodes_alloc'   => 0,
			'nodes_total'   => 0,
			'cpus_alloc'    => 0,
			'cpus_idle'     => 0,
			'cpus_other'    => 0,
			'cpus_total'    => 0,
			'alloc_gpus'    => 0,
			'idle_gpus'     => 0,
			'drained_gpus'  => 0,
			'total_gpus'    => 0,
			'alloc_mem_mib' => 0,
			'total_mem_mib' => 0,
			'free_mem_mib'  => 0,
		];

		$cluster    = $empty;
		$partitions = [];

		foreach ($byName as $node) {
			$state      = $node->state;
			$working    = $state === NodeState::Idle || $state === NodeState::Allocated || $state === NodeState::Mixed || $state === NodeState::Reserved;
			$cpusTotal  = max(0, $node->cpus);
			$cpusAlloc  = min($cpusTotal, max(0, $node->allocCpus));
			$cpusIdle   = $working ? $cpusTotal - $cpusAlloc : 0;
			$cpusOther  = $working ? 0 : $cpusTotal - $cpusAlloc;
			$nodesAlloc = ($cpusAlloc > 0 || $state === NodeState::Allocated || $state === NodeState::Mixed) ? 1 : 0;

			$gpusTotal   = max(0, $node->totalGpus);
			$gpusAlloc   = min($gpusTotal, max(0, $node->allocGpus));
			$gpusUnalloc = $gpusTotal - $gpusAlloc;
			$gpusIdle    = $working ? $gpusUnalloc : 0;
			$gpusDrained = $working ? 0 : $gpusUnalloc;

			$memTotal = max(0, $node->totalMemMib);
			$memAlloc = min($memTotal, max(0, $node->allocMemMib));
			$memFree  = min($memTotal, max(0, $node->freeMemMib));

			self::addResources($cluster, $nodesAlloc, $cpusAlloc, $cpusIdle, $cpusOther, $cpusTotal, $gpusAlloc, $gpusIdle, $gpusDrained, $gpusTotal, $memAlloc, $memTotal, $memFree);

			$seen = ['' => true];

			foreach ($node->partitions as $partition) {
				if (isset($seen[$partition])) {
					continue;
				}

				$seen[$partition] = true;

				if (!isset($partitions[$partition])) {
					$partitions[$partition] = $empty;
				}

				self::addResources($partitions[$partition], $nodesAlloc, $cpusAlloc, $cpusIdle, $cpusOther, $cpusTotal, $gpusAlloc, $gpusIdle, $gpusDrained, $gpusTotal, $memAlloc, $memTotal, $memFree);
			}
		}

		ksort($partitions);

		return ['cluster' => $cluster, 'partitions' => $partitions];
	}

	/**
	 * Accumulate one node's CPU and node counts into a partition bucket.
	 *
	 * @param array<string, array<string, int>> $counts    accumulated counts map
	 * @param string                            $key       prefixed partition key
	 * @param string                            $seriesKey normalized node state series name
	 * @param int                               $allocated allocated CPUs
	 * @param int                               $idle      idle CPUs
	 * @param int                               $other     unavailable unallocated CPUs
	 * @param int                               $total     total CPUs
	 * @param int                               $nodeAlloc 1 if node is allocated/mixed, else 0
	 *
	 * @return void
	 */
	private static function accumulateNode(
		array &$counts,
		string $key,
		string $seriesKey,
		int $allocated,
		int $idle,
		int $other,
		int $total,
		int $nodeAlloc,
	): void {
		$counts[$key][$seriesKey]    = ($counts[$key][$seriesKey] ?? 0) + 1;
		$counts[$key]['cpus_alloc']  = ($counts[$key]['cpus_alloc'] ?? 0) + $allocated;
		$counts[$key]['cpus_idle']   = ($counts[$key]['cpus_idle'] ?? 0) + $idle;
		$counts[$key]['cpus_other']  = ($counts[$key]['cpus_other'] ?? 0) + $other;
		$counts[$key]['cpus_total']  = ($counts[$key]['cpus_total'] ?? 0) + $total;
		$counts[$key]['nodes_alloc'] = ($counts[$key]['nodes_alloc'] ?? 0) + $nodeAlloc;
		$counts[$key]['nodes_total'] = ($counts[$key]['nodes_total'] ?? 0) + 1;
	}

	/**
	 * Add one node's CPU, GPU, and RAM totals into a resource summary bucket.
	 *
	 * @param array{
	 *   nodes_alloc: int,
	 *   nodes_total: int,
	 *   cpus_alloc: int,
	 *   cpus_idle: int,
	 *   cpus_other: int,
	 *   cpus_total: int,
	 *   alloc_gpus: int,
	 *   idle_gpus: int,
	 *   drained_gpus: int,
	 *   total_gpus: int,
	 *   alloc_mem_mib: int,
	 *   total_mem_mib: int,
	 *   free_mem_mib: int
	 * } $bucket target bucket updated in place
	 * @param int $nodesAlloc  1 if node allocated, else 0
	 * @param int $cpusAlloc   allocated CPUs
	 * @param int $cpusIdle    idle CPUs
	 * @param int $cpusOther   other CPUs
	 * @param int $cpusTotal   total CPUs
	 * @param int $gpusAlloc   allocated GPUs
	 * @param int $gpusIdle    idle GPUs
	 * @param int $gpusDrained drained/unavailable GPUs
	 * @param int $gpusTotal   total GPUs
	 * @param int $memAlloc    allocated memory MiB
	 * @param int $memTotal    total memory MiB
	 * @param int $memFree     free memory MiB
	 *
	 * @return void
	 */
	private static function addResources(
		array &$bucket,
		int $nodesAlloc,
		int $cpusAlloc,
		int $cpusIdle,
		int $cpusOther,
		int $cpusTotal,
		int $gpusAlloc,
		int $gpusIdle,
		int $gpusDrained,
		int $gpusTotal,
		int $memAlloc,
		int $memTotal,
		int $memFree,
	): void {
		$bucket['nodes_alloc']   += $nodesAlloc;
		$bucket['nodes_total']   += 1;
		$bucket['cpus_alloc']    += $cpusAlloc;
		$bucket['cpus_idle']     += $cpusIdle;
		$bucket['cpus_other']    += $cpusOther;
		$bucket['cpus_total']    += $cpusTotal;
		$bucket['alloc_gpus']    += $gpusAlloc;
		$bucket['idle_gpus']     += $gpusIdle;
		$bucket['drained_gpus']  += $gpusDrained;
		$bucket['total_gpus']    += $gpusTotal;
		$bucket['alloc_mem_mib'] += $memAlloc;
		$bucket['total_mem_mib'] += $memTotal;
		$bucket['free_mem_mib']  += $memFree;
	}
}
