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
 * Normalized node state (docs/research/slurm-states-reasons.md).
 *
 * Slurm reports a base state plus flags. The JSON `state` array keeps both;
 * sinfo's %T string does not, so a node that is DOWN and drained would read
 * as DRAINED there. The raw flags are always kept beside the normalized
 * state (REQ-BACKEND-004), and a state a later release adds maps to Other.
 */
enum NodeState: string {
	case Idle        = 'idle';
	case Allocated   = 'allocated';
	case Mixed       = 'mixed';
	case Drained     = 'drained';
	case Draining    = 'draining';
	case Down        = 'down';
	case Maintenance = 'maintenance';
	case Reserved    = 'reserved';
	case PoweredDown = 'powered_down';
	case Other       = 'other';

	/**
	 * Precedence when several apply: a down base state first, so DOWN with
	 * DRAIN stays down (the plan's DOWN rule for maintenance); then the
	 * maintenance flag; then drain or fail; then power; then reserved on an
	 * idle node; then the base state.
	 *
	 * @param list<string> $flags base state and flags as the JSON array or
	 *                            scontrol's State= text split on '+'
	 */
	public static function fromFlags(array $flags): self {
		$set  = self::normalize($flags);
		$base = $set[0] ?? '';

		return match (true) {
			$base === 'DOWN'                                    => self::Down,
			self::has($set, 'MAINTENANCE', 'MAINT')             => self::Maintenance,
			self::has($set, 'DRAIN', 'FAIL')                    => in_array($base, ['ALLOCATED', 'MIXED'], true) || self::has($set, 'COMPLETING') ? self::Draining : self::Drained,
			self::has($set, 'POWERED_DOWN')                     => self::PoweredDown,
			$base === 'IDLE' && self::has($set, 'RESERVED', 'RES') => self::Reserved,
			$base === 'IDLE'                                    => self::Idle,
			$base === 'ALLOCATED'                               => self::Allocated,
			$base === 'MIXED'                                   => self::Mixed,
			default                                             => self::Other,
		};
	}

	/**
	 * Whether the drain flag is set, read from the flags rather than from
	 * the normalized state, because a down node can also be drained.
	 *
	 * @param list<string> $flags
	 */
	public static function drained(array $flags): bool {
		return self::has(self::normalize($flags), 'DRAIN');
	}

	/**
	 * Upper case, without sinfo's suffix characters, empty entries dropped.
	 *
	 * @param list<string> $flags
	 *
	 * @return list<string>
	 */
	public static function normalize(array $flags): array {
		$out = [];

		foreach ($flags as $flag) {
			$flag = strtoupper(rtrim(trim($flag), '*~#!%$@^-'));

			if ($flag !== '') {
				$out[] = $flag;
			}
		}

		return $out;
	}

	public function label(): string {
		return match ($this) {
			self::Idle        => __('Idle', 'hpcsched'),
			self::Allocated   => __('Allocated', 'hpcsched'),
			self::Mixed       => __('Mixed', 'hpcsched'),
			self::Drained     => __('Drained', 'hpcsched'),
			self::Draining    => __('Draining', 'hpcsched'),
			self::Down        => __('Down', 'hpcsched'),
			self::Maintenance => __('Maintenance', 'hpcsched'),
			self::Reserved    => __('Reserved', 'hpcsched'),
			self::PoweredDown => __('Powered down', 'hpcsched'),
			self::Other       => __('Other', 'hpcsched'),
		};
	}

	/**
	 * @param list<string> $set
	 */
	private static function has(array $set, string ...$names): bool {
		return array_intersect($set, $names) !== [];
	}
}
