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
 * The Slurm data parser the plugin pins. v0.0.43 is the one parser every
 * supported release (25.05, 25.11, 26.05; decision #8, provisional) and the
 * 26.11 candidate all ship, so one parser serves every release and an
 * upgraded client package cannot change the shape under the plugin
 * (docs/research/slurm-client-output.md).
 */
final class DataParser {
	public const VERSION = 'v0.0.43';

	public const SUPPORTED_RELEASES = ['25.05', '25.11', '26.05'];

	/**
	 * Whether a reported release (for example "25.11.2") is a supported
	 * major release.
	 */
	public static function releaseSupported(string $release): bool {
		return preg_match('/^(\d{2}\.\d{2})(?:\.|$)/', $release, $m) === 1 && in_array($m[1], self::SUPPORTED_RELEASES, true);
	}
}
