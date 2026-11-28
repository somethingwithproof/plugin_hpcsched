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
 * Raw pending reason names as they are stored and counted.
 *
 * A reason comes from a fixed list in Slurm source, but squeue's %R form can
 * append detail ("ReqNodeNotAvail, UnavailableNodes:..."), so only the
 * leading identifier is kept. Anything that is not an identifier of at most
 * 64 characters is stored under one fixed name, so no free text from the
 * scheduler becomes a counter key.
 */
final class Reasons {
	/** Unrecognized constant for Reasons. */
	public const UNRECOGNIZED = '(unrecognized)';

	/**
	 * Name.
	 *
	 * @param string $reason
	 * @return string
	 */
	public static function name(string $reason): string {
		$reason = trim($reason);

		if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}/', $reason, $m) !== 1) {
			return $reason === '' ? 'None' : self::UNRECOGNIZED;
		}

		return $m[0];
	}
}
