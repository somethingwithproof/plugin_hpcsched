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
 * Stored state of one snapshot section: the last attempt, and when the
 * rows it holds were observed.
 */
final readonly class SectionRecord {
	/**
	 * Construct a new SectionRecord instance.
	 *
	 * @param int $clusterId
	 * @param Section $section
	 * @param SectionState $state
	 * @param ?ErrorClass $error
	 * @param int $attemptedAt
	 * @param int $records
	 * @param int $observedAt
	 * @param int $ingestedAt
	 * @param int $interval
	 */
	public function __construct(
		public int $clusterId,
		public Section $section,
		public SectionState $state,
		public ?ErrorClass $error,
		public int $attemptedAt,
		public int $records,
		public int $observedAt,
		public int $ingestedAt,
		public int $interval,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$section = Section::tryFrom(Row::string($row, 'section'));
		$state   = SectionState::tryFrom(Row::string($row, 'state'));
		$id      = Row::int($row, 'cluster_id', 0, 1);

		if ($section === null || $state === null || $id === 0) {
			return null;
		}

		return new self(
			$id,
			$section,
			$state,
			ErrorClass::tryFrom(Row::string($row, 'error_class')),
			Row::int($row, 'attempted_at', 0, 0),
			Row::int($row, 'records', 0, 0),
			Row::int($row, 'observed_at', 0, 0),
			Row::int($row, 'ingested_at', 0, 0),
			Row::int($row, 'collect_interval', 0, 0),
		);
	}
}
