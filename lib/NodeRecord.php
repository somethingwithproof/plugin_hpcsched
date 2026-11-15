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
 * One scheduler node: its normalized state and, beside it, the raw state
 * flags and reason text exactly as Slurm reported them (REQ-BACKEND-004).
 * The reason is free text an administrator typed; it is stored and shown
 * escaped, and never logged.
 */
final readonly class NodeRecord {
	/**
	 * @param list<string> $partitions
	 * @param list<string> $flags base state and flags
	 */
	public function __construct(
		public string $name,
		public string $hostname,
		public string $address,
		public array $partitions,
		public array $flags,
		public NodeState $state,
		public string $reason,
		public int $reasonChangedAt,
		public int $cpus,
		public int $allocCpus,
	) {
	}

	public function drained(): bool {
		return NodeState::drained($this->flags);
	}
}
