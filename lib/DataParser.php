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
 * The Slurm data parser the plugin pins, chosen in Settings from the
 * versions below. v0.0.43 is the default because every supported release
 * (25.05, 25.11, 26.05; ADR 0013) ships it, so one setting
 * serves a mixed site and an upgraded client package cannot change the shape
 * under the plugin (docs/research/slurm-client-output.md). SchedMD removes
 * v0.0.43 in 27.05, so a site on 25.11 or later can move to a newer parser
 * before then. The fields the parser reads are present in v0.0.43 and
 * v0.0.45 source; no output of any version has been captured from a cluster.
 */
final class DataParser {
	public const DEFAULT_VERSION = 'v0.0.43';

	public const VERSIONS = ['v0.0.43', 'v0.0.44', 'v0.0.45'];

	/**
	 * Release => the data parsers it ships, from SchedMD's data parser
	 * lifecycle table (rest_clients.html, checked 2026-11-15) and the source
	 * of each tag (docs/research/slurm-releases.md).
	 */
	public const SHIPPED = [
		'25.05' => ['v0.0.40', 'v0.0.41', 'v0.0.42', 'v0.0.43'],
		'25.11' => ['v0.0.41', 'v0.0.42', 'v0.0.43', 'v0.0.44'],
		'26.05' => ['v0.0.42', 'v0.0.43', 'v0.0.44', 'v0.0.45'],
	];

	public const SUPPORTED_RELEASES = ['25.05', '25.11', '26.05'];

	public static function supported(string $version): bool {
		return in_array($version, self::VERSIONS, true);
	}

	/**
	 * The supported versions, newest first: the order slurmrestd discovery
	 * tries them in (REQ-REST-002).
	 *
	 * @return list<string>
	 */
	public static function newestFirst(): array {
		return array_reverse(self::VERSIONS);
	}

	/**
	 * The newest supported version a supported release ships, or null for a
	 * release the plugin does not support.
	 */
	public static function newestShippedBy(string $release): ?string {
		foreach (self::newestFirst() as $version) {
			if (self::shippedBy($release, $version)) {
				return $version;
			}
		}

		return null;
	}

	/**
	 * Whether a release is the given supported major release or a later
	 * one, for example "26.05.5" against "26.05". An unparsable release
	 * answers false.
	 */
	public static function atLeast(string $release, string $major): bool {
		return preg_match('/^(\d{2})\.(\d{2})(?:\.|$)/', $release, $m) === 1 && version_compare($m[1] . '.' . $m[2], $major, '>=');
	}

	/**
	 * Whether a reported release (for example "25.11.2") is a supported
	 * major release.
	 */
	public static function releaseSupported(string $release): bool {
		return self::major($release) !== null;
	}

	/**
	 * Whether a supported release ships the given parser. An unsupported
	 * release answers false; it has its own warning.
	 */
	public static function shippedBy(string $release, string $version): bool {
		$major = self::major($release);

		return $major !== null && in_array($version, self::SHIPPED[$major], true);
	}

	private static function major(string $release): ?string {
		return preg_match('/^(\d{2}\.\d{2})(?:\.|$)/', $release, $m) === 1 && in_array($m[1], self::SUPPORTED_RELEASES, true) ? $m[1] : null;
	}
}
