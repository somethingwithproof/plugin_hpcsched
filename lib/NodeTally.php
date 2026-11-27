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

		$counts = [];

		foreach ($byName as $node) {
			$total     = max(0, $node->cpus);
			$allocated = min($total, max(0, $node->allocCpus));
			$working   = in_array($node->state, [NodeState::Idle, NodeState::Allocated, NodeState::Mixed, NodeState::Reserved], true);
			$values    = [
				self::series($node->state) => 1,
				'cpus_alloc'               => $allocated,
				'cpus_idle'                => $working ? $total - $allocated : 0,
				'cpus_other'               => $working ? 0 : $total - $allocated,
				'cpus_total'               => $total,
				'nodes_alloc'              => $allocated > 0 || in_array($node->state, [NodeState::Allocated, NodeState::Mixed], true) ? 1 : 0,
				'nodes_total'              => 1,
			];

			foreach (array_merge([''], array_values(array_unique($node->partitions))) as $partition) {
				foreach ($values as $name => $value) {
					$counts["p:$partition"][$name] = ($counts["p:$partition"][$name] ?? 0) + $value;
				}
			}
		}

		$rows = [];

		foreach ($counts as $key => $names) {
			foreach ($names as $name => $value) {
				$rows[] = ['partition' => substr((string) $key, 2), 'name' => (string) $name, 'value' => $value];
			}
		}

		return $rows;
	}
}
