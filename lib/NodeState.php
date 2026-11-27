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
		return self::fromNormalizedFlags(self::normalize($flags));
	}

	/**
	 * Classify already-normalized uppercase node state flags without
	 * re-running normalize().
	 *
	 * @param list<string> $set normalized uppercase base state and flags
	 *
	 * @return self
	 */
	public static function fromNormalizedFlags(array $set): self {
		$base = $set[0] ?? '';

		if ($base === 'DOWN') {
			return self::Down;
		}

		$hasMaint      = false;
		$hasDrain      = false;
		$hasCompleting = false;
		$hasPowerDown  = false;
		$hasReserved   = false;

		foreach ($set as $flag) {
			if ($flag === 'MAINTENANCE' || $flag === 'MAINT') {
				$hasMaint = true;
			} elseif ($flag === 'DRAIN' || $flag === 'FAIL') {
				$hasDrain = true;
			} elseif ($flag === 'COMPLETING') {
				$hasCompleting = true;
			} elseif ($flag === 'POWERED_DOWN') {
				$hasPowerDown = true;
			} elseif ($flag === 'RESERVED' || $flag === 'RES') {
				$hasReserved = true;
			}
		}

		if ($hasMaint) {
			return self::Maintenance;
		}

		if ($hasDrain) {
			return ($base === 'ALLOCATED' || $base === 'MIXED' || $hasCompleting) ? self::Draining : self::Drained;
		}

		if ($hasPowerDown) {
			return self::PoweredDown;
		}

		if ($base === 'IDLE') {
			return $hasReserved ? self::Reserved : self::Idle;
		}

		return match ($base) {
			'ALLOCATED' => self::Allocated,
			'MIXED'     => self::Mixed,
			default     => self::Other,
		};
	}

	/**
	 * Whether the drain flag is set, read from the flags rather than from
	 * the normalized state, because a down node can also be drained.
	 *
	 * @param list<string> $flags
	 */
	public static function drained(array $flags): bool {
		foreach (self::normalize($flags) as $flag) {
			if ($flag === 'DRAIN') {
				return true;
			}
		}

		return false;
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

	/**
	 * Label.
	 *
	 * @return string
	 */
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
}
