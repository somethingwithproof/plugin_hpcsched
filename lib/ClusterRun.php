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
 * The last reader run of one cluster, as the health page and the scheduler
 * read it. Error classes and states only; no scheduler text.
 */
final readonly class ClusterRun {
	/**
	 * Construct a new ClusterRun instance.
	 *
	 * @param int $clusterId
	 * @param int $leaseExpires
	 * @param int $startedAt
	 * @param int $finishedAt
	 * @param ?float $duration
	 * @param RunOutcome $outcome
	 * @param ?ErrorClass $error
	 * @param string $release
	 * @param string $transport
	 * @param string $dataParser
	 * @param int $calls
	 * @param int $lastSuccess
	 * @param int $lastFailure
	 * @param int $backoffUntil
	 * @param int $backoffStep
	 * @param bool $overran
	 * @param bool $memoryLimited
	 * @param TokenState $tokenState
	 * @param int $tokenExpires
	 */
	public function __construct(
		public int $clusterId,
		public int $leaseExpires = 0,
		public int $startedAt = 0,
		public int $finishedAt = 0,
		public ?float $duration = null,
		public RunOutcome $outcome = RunOutcome::Never,
		public ?ErrorClass $error = null,
		public string $release = '',
		public string $transport = '',
		public string $dataParser = '',
		public int $calls = 0,
		public int $lastSuccess = 0,
		public int $lastFailure = 0,
		public int $backoffUntil = 0,
		public int $backoffStep = 0,
		public bool $overran = false,
		public bool $memoryLimited = false,
		public TokenState $tokenState = TokenState::None,
		public int $tokenExpires = 0,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id = Row::int($row, 'cluster_id', 0, 1);

		if ($id === 0) {
			return null;
		}

		return new self(
			clusterId: $id,
			leaseExpires: Row::int($row, 'lease_expires', 0, 0),
			startedAt: Row::int($row, 'started_at', 0, 0),
			finishedAt: Row::int($row, 'finished_at', 0, 0),
			duration: Row::float($row, 'duration'),
			outcome: RunOutcome::tryFrom(Row::string($row, 'outcome')) ?? RunOutcome::Never,
			error: ErrorClass::tryFrom(Row::string($row, 'error_class')),
			release: Row::string($row, 'slurm_release'),
			transport: Row::string($row, 'transport'),
			dataParser: Row::string($row, 'data_parser'),
			calls: Row::int($row, 'calls', 0, 0),
			lastSuccess: Row::int($row, 'last_success', 0, 0),
			lastFailure: Row::int($row, 'last_failure', 0, 0),
			backoffUntil: Row::int($row, 'backoff_until', 0, 0),
			backoffStep: Row::int($row, 'backoff_step', 0, 0),
			overran: Row::bool($row, 'overran'),
			memoryLimited: Row::bool($row, 'memory_limited'),
			tokenState: TokenState::tryFrom(Row::string($row, 'token_state')) ?? TokenState::None,
			tokenExpires: Row::int($row, 'token_expires', 0, 0),
		);
	}
}
