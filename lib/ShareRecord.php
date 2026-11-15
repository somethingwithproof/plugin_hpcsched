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
 * Fair-share usage of one account association, with the field names of the
 * shares response (ASSOC_SHARES_OBJ_WRAP at v0.0.43: usage, usage_normalized,
 * effective_usage, fairshare/factor, fairshare/level). Null is a value the
 * scheduler did not set, which is not zero.
 */
final readonly class ShareRecord {
	public function __construct(
		public string $account,
		public string $partition,
		public string $parent,
		public ?float $usage,
		public ?float $usageNormalized,
		public ?float $effectiveUsage,
		public ?float $fairshareFactor,
		public ?float $fairshareLevel,
	) {
	}
}
