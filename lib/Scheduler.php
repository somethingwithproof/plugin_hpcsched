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
 * Which clusters of a poller are due a reader run. Only enabled clusters of
 * that poller (REQ-READ-002), none whose lease is held, none in a rate-limit
 * backoff, and none started less than a little under its effective interval
 * ago, because each run starts a little after its poller cycle.
 */
final class Scheduler {
	/** Due slack constant for Scheduler. */
	public const DUE_SLACK = 30;

	/**
	 * Construct a new Scheduler instance.
	 *
	 * @param ClusterRepository $clusters
	 * @param RunRepository $runs
	 * @param Clock $clock
	 */
	public function __construct(
		private readonly ClusterRepository $clusters,
		private readonly RunRepository $runs,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Due after.
	 *
	 * @param int $interval
	 * @return int
	 */
	public static function dueAfter(int $interval): int {
		return $interval - min(self::DUE_SLACK, intdiv($interval, 10));
	}

	/**
	 * @return list<int>
	 */
	public function due(int $pollerId, int $pollerInterval): array {
		$now  = $this->clock->now();
		$runs = $this->runs->all();
		$due  = [];

		foreach ($this->clusters->all() as $cluster) {
			if (!$cluster->enabled() || $cluster->pollerId !== $pollerId) {
				continue;
			}

			$run = $runs[$cluster->id] ?? null;

			if ($run !== null && ($run->leaseExpires >= $now || $run->backoffUntil > $now || ($run->startedAt > 0 && $now - $run->startedAt < self::dueAfter($cluster->effectiveInterval($pollerInterval))))) {
				continue;
			}

			$due[] = $cluster->id;
		}

		return $due;
	}
}
