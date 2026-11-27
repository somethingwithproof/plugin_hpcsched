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
 * Scheduler access (ADR 0004). A backend turns one scheduler's output into
 * the normalized snapshot. It exposes no operation that changes the
 * scheduler (REQ-BACKEND-005): the one method reads.
 */
interface Backend {
	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Collect the cluster's sections, starting no scheduler call after
	 * $deadline (a microtime(true) value). Never throws for a scheduler
	 * failure: each section records its own outcome.
	 */
	public function collect(Cluster $cluster, float $deadline): Snapshot;
}
