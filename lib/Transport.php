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
 * How a cluster's scheduler is read. Client commands are the default
 * (decision #7, provisional); slurmrestd is the configurable alternative.
 */
enum Transport: string {
	case Commands = 'commands';
	case Rest     = 'rest';

	public function label(): string {
		return match ($this) {
			self::Commands => __('Slurm client commands', 'hpcsched'),
			self::Rest     => __('slurmrestd', 'hpcsched'),
		};
	}
}
