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
 * Prepares cluster configuration rows for Cacti's replicate_out hook to
 * remote data collectors. Raw token files are never read or replicated across
 * pollers; each remote collector manages its own local token files.
 */
final class ReplicatorRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Fetch cluster rows assigned to $remotePollerId with token_file omitted
	 * from cross-poller replication unless configured locally on that collector.
	 *
	 * @return list<array<array-key, mixed>>
	 */
	public function clustersForPoller(int $remotePollerId): array {
		if ($remotePollerId < 2) {
			return [];
		}

		$rows = Row::rows(db_fetch_assoc_prepared(
			'SELECT id, name, poller_id, host_id, admin_state, transport, collect_interval, slurm_conf, rest_url, rest_user, \'\' AS token_file, collect_reservations, collect_shares, created_at, updated_at FROM plugin_hpcsched_cluster WHERE poller_id = ? ORDER BY id',
			[$remotePollerId],
			true,
			$this->connection,
		));

		return $rows;
	}
}
