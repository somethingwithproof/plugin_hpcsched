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
 * One planned or applied Cacti Maint synchronization operation for a mapped
 * Slurm compute node.
 */
final readonly class MaintAction {
	/**
	 * Construct a new MaintAction instance.
	 *
	 * @param string $operation
	 * @param int $clusterId
	 * @param string $nodeName
	 * @param int $hostId
	 * @param string $reasonKind
	 * @param string $sourceRef
	 * @param int $startTime
	 * @param int $endTime
	 * @param int $scheduleId
	 */
	public function __construct(
		public string $operation,
		public int $clusterId,
		public string $nodeName,
		public int $hostId,
		public string $reasonKind,
		public string $sourceRef,
		public int $startTime,
		public int $endTime,
		public int $scheduleId = 0,
	) {
	}

	/**
	 * @return array{operation: string, cluster_id: int, node: string, host_id: int, reason_kind: string, source_ref: string, start_time: int, end_time: int, schedule_id: int}
	 */
	public function toArray(): array {
		return [
			'operation'   => $this->operation,
			'cluster_id'  => $this->clusterId,
			'node'        => $this->nodeName,
			'host_id'     => $this->hostId,
			'reason_kind' => $this->reasonKind,
			'source_ref'  => $this->sourceRef,
			'start_time'  => $this->startTime,
			'end_time'    => $this->endTime,
			'schedule_id' => $this->scheduleId,
		];
	}
}
