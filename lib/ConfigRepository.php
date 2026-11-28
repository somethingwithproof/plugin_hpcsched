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
 * Persistence layer for HPC Scheduler CasC settings updates.
 */
final class ConfigRepository {
	/**
	 * Construct a new ConfigRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Save setting.
	 *
	 * @param string $name
	 * @param string $value
	 * @return bool
	 */
	public function saveSetting(string $name, string $value): bool {
		if (!in_array($name, Settings::NAMES, true)) {
			return false;
		}

		return db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', [$name, $value], true, $this->connection) !== false;
	}
}
