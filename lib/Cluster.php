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
 * One scheduler cluster as an administrator configured it. Nothing here is a
 * command line: binaries come from the global settings and the argument
 * vectors are fixed in CommandTransport. The token file is a path; the token
 * itself is read at use time and never stored (REQ-CRED-002). A cluster
 * mapped to a Cacti device (hostId) belongs to that device for permissions
 * (DeviceAccess).
 */
final readonly class Cluster {
	/** Name pattern constant for Cluster. */
	public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

	// Provisional until the live load test (#18): 60 second default, a 30
	// second floor, and a time budget of half the interval.
	/** Default interval. */
	public const DEFAULT_INTERVAL = 60;
	/** Minimum interval. */
	public const MIN_INTERVAL     = 30;
	/** Maximum interval. */
	public const MAX_INTERVAL     = 3600;

	/**
	 * Construct a new Cluster instance.
	 *
	 * @param int $id
	 * @param string $name
	 * @param int $pollerId
	 * @param AdminState $adminState
	 * @param Transport $transport
	 * @param int $interval
	 * @param string $slurmConf
	 * @param string $restUrl
	 * @param string $restUser
	 * @param string $tokenFile
	 * @param bool $reservations
	 * @param bool $shares
	 * @param int $updatedAt
	 * @param int $hostId
	 */
	public function __construct(
		public int $id,
		public string $name,
		public int $pollerId,
		public AdminState $adminState,
		public Transport $transport,
		public int $interval,
		public string $slurmConf = '',
		public string $restUrl = '',
		public string $restUser = '',
		public string $tokenFile = '',
		public bool $reservations = false,
		public bool $shares = false,
		public int $updatedAt = 0,
		public int $hostId = 0,
	) {
	}

	/**
	 * A row that cannot describe a cluster (no id, an unknown transport or
	 * state) is null, so a damaged row is skipped instead of guessed at.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id        = Row::int($row, 'id', 0, 1);
		$transport = Transport::tryFrom(Row::string($row, 'transport'));
		$state     = AdminState::tryFrom(Row::string($row, 'admin_state'));

		if ($id === 0 || $transport === null || $state === null) {
			return null;
		}

		return new self(
			id: $id,
			name: Row::string($row, 'name'),
			pollerId: Row::int($row, 'poller_id', 1, 1),
			adminState: $state,
			transport: $transport,
			interval: max(self::MIN_INTERVAL, min(Row::int($row, 'collect_interval', self::DEFAULT_INTERVAL), self::MAX_INTERVAL)),
			slurmConf: Row::string($row, 'slurm_conf'),
			restUrl: Row::string($row, 'rest_url'),
			restUser: Row::string($row, 'rest_user'),
			tokenFile: Row::string($row, 'token_file'),
			reservations: Row::bool($row, 'collect_reservations'),
			shares: Row::bool($row, 'collect_shares'),
			updatedAt: Row::int($row, 'updated_at', 0, 0),
			hostId: Row::int($row, 'host_id', 0, 0),
		);
	}

	/**
	 * Enabled.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return $this->adminState === AdminState::Enabled;
	}

	/**
	 * The interval data is actually collected on: the reader starts from the
	 * poller cycle, so it never runs more often than the poller does.
	 */
	public function effectiveInterval(int $pollerInterval): int {
		return max($this->interval, $pollerInterval);
	}

	/**
	 * Seconds one run may spend: half the effective interval.
	 */
	public function budget(int $pollerInterval): int {
		return max(intdiv(self::MIN_INTERVAL, 2), intdiv($this->effectiveInterval($pollerInterval), 2));
	}

	/**
	 * The fixed set of sections, and so of scheduler calls, a run makes for
	 * this cluster. It never grows with job, node, or series counts
	 * (REQ-READ-006).
	 *
	 * @return list<Section>
	 */
	public function sections(): array {
		$sections = [Section::Jobs, Section::Nodes, Section::Partitions];

		if ($this->reservations) {
			$sections[] = Section::Reservations;
		}

		if ($this->shares) {
			$sections[] = Section::Shares;
		}

		return $sections;
	}
}
