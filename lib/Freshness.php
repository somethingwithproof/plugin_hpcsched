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
 * Freshness of a cluster's data (REQ-STATE-001). Only current and delayed
 * data is served; stale and never collected read as unknown, never zero
 * (REQ-STATE-003).
 */
enum Freshness: string {
	case Current = 'current';
	case Delayed = 'delayed';
	case Stale   = 'stale';
	case Never   = 'never';

	/**
	 * Usable.
	 *
	 * @return bool
	 */
	public function usable(): bool {
		return $this === self::Current || $this === self::Delayed;
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ($this) {
			self::Current => __('Current', 'hpcsched'),
			self::Delayed => __('Delayed', 'hpcsched'),
			self::Stale   => __('Stale', 'hpcsched'),
			self::Never   => __('Never collected', 'hpcsched'),
		};
	}
}
