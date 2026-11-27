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
 * Idempotent, opt-in Cacti Maint maintenance window synchronizer for Slurm
 * compute nodes in DRAIN, DOWN, or active MAINT reservation state (issue #13).
 *
 * Only schedules tracked in plugin_hpcsched_maint_link are ever updated or
 * removed, so administrator-created Maint schedules remain untouched.
 */
final class MaintSynchronizer {
	public const DEFAULT_DRAIN_HORIZON = 3600;
	public const RENEW_MARGIN          = 600;

	public function __construct(
		private readonly ClusterRepository $clusters,
		private readonly NodeMapRepository $nodeMaps,
		private readonly MaintRepository $maint,
		private readonly NodeMatcher $matcher,
		private readonly Clock $clock,
		private readonly int $drainHorizon = self::DEFAULT_DRAIN_HORIZON,
	) {
	}

	/**
	 * Build a dry-run maintenance synchronization plan across one or all
	 * enabled clusters.
	 */
	public function plan(?int $clusterId = null): MaintSyncPlan {
		$available = $this->maint->maintTablesAvailable();
		$warnings  = [];

		if (!$available) {
			$warnings[] = 'Cacti Maint plugin tables (plugin_maint_schedules, plugin_maint_hosts) are not installed; apply will be refused.';
		}

		$hosts    = $this->nodeMaps->cactiHosts();
		$clusters = $clusterId !== null
			? array_values(array_filter([$this->clusters->get($clusterId)]))
			: $this->clusters->all();

		$now     = $this->clock->now();
		$actions = [];

		foreach ($clusters as $cluster) {
			if (!$cluster->enabled()) {
				continue;
			}

			$nodes        = $this->nodeMaps->clusterNodes($cluster->id);
			$reservations = $this->nodeMaps->clusterReservations($cluster->id);
			$savedMaps    = $this->nodeMaps->mappings($cluster->id);
			$autoMatches  = $this->matcher->match($nodes, $hosts);
			$ownedLinks   = $this->maint->linksForCluster($cluster->id);

			// Index active maintenance reservations by node name.
			/** @var array<string, array{name: string, start: int, end: int}> $reservedNodes */
			$reservedNodes = [];

			foreach ($reservations as $res) {
				if (!self::isMaintReservation($res['flags'])) {
					continue;
				}

				if ($res['end_time'] > 0 && $res['end_time'] <= $now) {
					continue;
				}

				$start = $res['start_time'] > 0 ? $res['start_time'] : $now;
				$end   = $res['end_time'] > $start ? $res['end_time'] : ($now + $this->drainHorizon);

				foreach (HostList::expand($res['node_list']) as $nodeName) {
					if (!isset($reservedNodes[$nodeName]) || $end > $reservedNodes[$nodeName]['end']) {
						$reservedNodes[$nodeName] = ['name' => $res['name'], 'start' => $start, 'end' => $end];
					}
				}
			}

			$activeNodes = [];

			foreach ($nodes as $node) {
				$name   = $node['name'];
				$hostId = $this->resolveHostId($name, $savedMaps, $autoMatches);

				if ($hostId < 1) {
					continue;
				}

				$desired = $this->desiredWindow($node, $reservedNodes[$name] ?? null, $now);

				if ($desired === null) {
					// Node returned IDLE/ALLOC/MIXED and has no active MAINT reservation.
					if (isset($ownedLinks[$name])) {
						$link      = $ownedLinks[$name];
						$actions[] = new MaintAction(
							operation: 'remove',
							clusterId: $cluster->id,
							nodeName: $name,
							hostId: $link['host_id'],
							reasonKind: $link['reason_kind'],
							sourceRef: $link['source_ref'],
							startTime: $link['start_time'],
							endTime: $link['end_time'],
							scheduleId: $link['schedule_id'],
						);
					}

					continue;
				}

				$activeNodes[$name] = true;

				if (!isset($ownedLinks[$name])) {
					$actions[] = new MaintAction(
						operation: 'create',
						clusterId: $cluster->id,
						nodeName: $name,
						hostId: $hostId,
						reasonKind: $desired['kind'],
						sourceRef: $desired['ref'],
						startTime: $desired['start'],
						endTime: $desired['end'],
					);
				} else {
					$link       = $ownedLinks[$name];
					$needsRenew = $link['end_time'] < ($now + self::RENEW_MARGIN) && $desired['end'] > $link['end_time'];
					$changed    = $link['reason_kind'] !== $desired['kind']
						|| $link['source_ref'] !== $desired['ref']
						|| abs($link['end_time'] - $desired['end']) > self::RENEW_MARGIN
						|| $needsRenew;

					if ($changed) {
						$actions[] = new MaintAction(
							operation: 'update',
							clusterId: $cluster->id,
							nodeName: $name,
							hostId: $hostId,
							reasonKind: $desired['kind'],
							sourceRef: $desired['ref'],
							startTime: min($link['start_time'], $desired['start']),
							endTime: max($desired['end'], $now + self::RENEW_MARGIN),
							scheduleId: $link['schedule_id'],
						);
					}
				}
			}

			// Clean up links for nodes that disappeared from the snapshot or were unmapped.
			foreach ($ownedLinks as $name => $link) {
				if (!isset($activeNodes[$name]) && !$this->nodePresent($nodes, (string) $name)) {
					$actions[] = new MaintAction(
						operation: 'remove',
						clusterId: $cluster->id,
						nodeName: (string) $name,
						hostId: $link['host_id'],
						reasonKind: $link['reason_kind'],
						sourceRef: $link['source_ref'],
						startTime: $link['start_time'],
						endTime: $link['end_time'],
						scheduleId: $link['schedule_id'],
					);
				}
			}
		}

		return new MaintSyncPlan($available, $actions, $warnings);
	}

	/**
	 * Apply a maintenance synchronization plan idempotently.
	 *
	 * @return array{applied: int, errors: list<string>, plan: MaintSyncPlan}
	 */
	public function apply(?int $clusterId = null): array {
		$plan = $this->plan($clusterId);

		if (!$plan->maintAvailable) {
			return ['applied' => 0, 'errors' => ['Cacti Maint plugin tables are not installed'], 'plan' => $plan];
		}

		$applied = 0;
		$errors  = [];

		foreach ($plan->actions as $action) {
			if ($action->operation === 'create') {
				$name = sprintf('hpcsched:%d:%s (%s)', $action->clusterId, $action->nodeName, $action->reasonKind);

				if ($this->maint->createSchedule($action, $name) > 0) {
					$applied++;
				} else {
					$errors[] = sprintf('Failed to create Maint schedule for node %s (host %d)', $action->nodeName, $action->hostId);
				}
			} elseif ($action->operation === 'update') {
				if ($this->maint->updateSchedule($action)) {
					$applied++;
				} else {
					$errors[] = sprintf('Failed to update Maint schedule %d for node %s', $action->scheduleId, $action->nodeName);
				}
			} elseif ($action->operation === 'remove') {
				if ($this->maint->removeSchedule($action)) {
					$applied++;
				} else {
					$errors[] = sprintf('Failed to remove Maint schedule %d for node %s', $action->scheduleId, $action->nodeName);
				}
			}
		}

		return ['applied' => $applied, 'errors' => $errors, 'plan' => $plan];
	}

	/**
	 * @param array<string, array{host_id: int, match_state: string, match_tier: int, source: string}> $savedMaps
	 * @param array<string, NodeMatch>                                                                 $autoMatches
	 */
	private function resolveHostId(string $nodeName, array $savedMaps, array $autoMatches): int {
		if (isset($savedMaps[$nodeName]) && $savedMaps[$nodeName]['host_id'] > 0 && in_array($savedMaps[$nodeName]['match_state'], [NodeMatchState::Confirmed->value, NodeMatchState::Proposed->value], true)) {
			return $savedMaps[$nodeName]['host_id'];
		}

		$match = $autoMatches[$nodeName] ?? null;

		if ($match !== null && $match->state === NodeMatchState::Proposed && $match->hostId !== null) {
			return $match->hostId;
		}

		return 0;
	}

	/**
	 * @param array{name: string, hostname: string, address: string, state: string, raw_state: string, reason: string} $node
	 * @param array{name: string, start: int, end: int}|null                                                           $reservation
	 *
	 * @return array{kind: string, ref: string, start: int, end: int}|null
	 */
	private function desiredWindow(array $node, ?array $reservation, int $now): ?array {
		$flags = array_values(array_filter(explode('+', strtoupper($node['raw_state']))));
		$state = NodeState::tryFrom($node['state']);

		if ($reservation !== null) {
			return [
				'kind'  => 'reservation',
				'ref'   => $reservation['name'],
				'start' => $reservation['start'],
				'end'   => $reservation['end'],
			];
		}

		if ($state === NodeState::Down || in_array('DOWN', $flags, true)) {
			return [
				'kind'  => 'down',
				'ref'   => 'slurm:down',
				'start' => $now,
				'end'   => $now + $this->drainHorizon,
			];
		}

		if ($state === NodeState::Drained || $state === NodeState::Draining || NodeState::drained($flags)) {
			return [
				'kind'  => 'drain',
				'ref'   => 'slurm:drain',
				'start' => $now,
				'end'   => $now + $this->drainHorizon,
			];
		}

		return null;
	}

	private static function isMaintReservation(string $flags): bool {
		$upper = strtoupper($flags);

		return str_contains($upper, 'MAINT') || str_contains($upper, 'MAINTENANCE');
	}

	/**
	 * @param list<array{name: string, hostname: string, address: string, state: string, raw_state: string, reason: string}> $nodes
	 */
	private function nodePresent(array $nodes, string $name): bool {
		foreach ($nodes as $node) {
			if ($node['name'] === $name) {
				return true;
			}
		}

		return false;
	}
}
