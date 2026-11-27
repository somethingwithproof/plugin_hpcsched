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
 * Result of matching one Slurm node name to a Cacti host record.
 */
final readonly class NodeMatch {
	/**
	 * Construct a new NodeMatch instance.
	 *
	 * @param string $nodeName
	 * @param NodeMatchState $state
	 * @param ?int $hostId
	 * @param ?int $tier
	 */
	public function __construct(
		public string $nodeName,
		public NodeMatchState $state,
		public ?int $hostId,
		public ?int $tier,
	) {
	}
}
