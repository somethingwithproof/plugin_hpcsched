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
 * One normalized snapshot of a cluster (REQ-BACKEND-001, REQ-BACKEND-002):
 * when it was collected, which scheduler release, backend, transport, and
 * data parser produced it, and per section either its data and a completed
 * state, or a failed or not attempted state and no data. A section that
 * failed has null data, never an empty list (REQ-BACKEND-003).
 */
final readonly class Snapshot {
	/**
	 * @param array<string, SectionOutcome>  $outcomes section value => outcome
	 * @param ?list<NodeRecord>              $nodes
	 * @param ?list<PartitionRecord>         $partitions
	 * @param ?list<ReservationRecord>       $reservations
	 * @param ?list<ShareRecord>             $shares
	 */
	public function __construct(
		public int $startedAt,
		public int $finishedAt,
		public string $backend,
		public Transport $transport,
		public string $dataParser,
		public string $release,
		public array $outcomes,
		public int $calls = 0,
		public ?JobTally $jobs = null,
		public ?array $nodes = null,
		public ?array $partitions = null,
		public ?array $reservations = null,
		public ?array $shares = null,
	) {
	}

	public function outcome(Section $section): SectionOutcome {
		return $this->outcomes[$section->value] ?? SectionOutcome::notAttempted();
	}

	/**
	 * @return list<ErrorClass>
	 */
	public function errors(): array {
		$errors = [];

		foreach ($this->outcomes as $outcome) {
			if ($outcome->error !== null) {
				$errors[] = $outcome->error;
			}
		}

		return $errors;
	}
}
