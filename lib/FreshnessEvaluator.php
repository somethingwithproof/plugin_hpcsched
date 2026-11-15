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
 * Freshness from the age of the data a section holds, against the interval
 * it was collected on (REQ-STATE-001). Data is current up to twice the
 * interval, delayed up to three times, and stale beyond. Data stamped more
 * than SKEW_ALLOWANCE seconds in the future means the clocks disagree, and
 * the plugin cannot vouch for it, so it is stale. A page view never counts
 * as an observation (REQ-STATE-005): only the reader's collection time does.
 */
final class FreshnessEvaluator {
	public const CURRENT_MULTIPLE = 2;
	public const STALE_MULTIPLE   = 3;
	public const SKEW_ALLOWANCE   = 120;

	public function __construct(private readonly Clock $clock) {
	}

	public function evaluate(int $observedAt, int $interval): Freshness {
		if ($observedAt <= 0) {
			return Freshness::Never;
		}

		$age      = $this->clock->now() - $observedAt;
		$interval = max(1, $interval);

		return match (true) {
			$age < -self::SKEW_ALLOWANCE                  => Freshness::Stale,
			$age <= self::CURRENT_MULTIPLE * $interval    => Freshness::Current,
			$age <= self::STALE_MULTIPLE * $interval      => Freshness::Delayed,
			default                                       => Freshness::Stale,
		};
	}

	/**
	 * Seconds since the data was collected, never negative, for the `age`
	 * value that stays numeric whatever the freshness (REQ-STATE-003).
	 */
	public function age(int $observedAt): ?int {
		return $observedAt > 0 ? max(0, $this->clock->now() - $observedAt) : null;
	}
}
