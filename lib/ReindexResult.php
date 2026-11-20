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
 * What a reindex request did. Cacti's run_data_query() returns true for a
 * query it skips because the device is disabled or down, so the skip is
 * detected from the device row before the call.
 */
enum ReindexResult {
	/** No device, or the plugin's data query does not exist yet. */
	case NotRun;
	/** The device is disabled or down, so Cacti skipped the query. */
	case Skipped;
	/** Cacti ran the query and reported failure, for example a silent remote collector. */
	case Failed;
	case Completed;
}
