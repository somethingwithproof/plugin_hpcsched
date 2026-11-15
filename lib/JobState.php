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
 * Normalized job state. Held jobs are pending jobs whose reason is a hold,
 * shown apart from eligible pending jobs because Slurm's own pending count
 * includes them (docs/research/slurm-states-reasons.md).
 */
enum JobState: string {
	case Pending    = 'pending';
	case Held       = 'held';
	case Running    = 'running';
	case Completing = 'completing';
	case Other      = 'other';

	/**
	 * Base states of a job that has ended. squeue leaves these out by
	 * default, but slurmrestd's jobs endpoint still lists such jobs while
	 * slurmctld keeps them (MinJobAge), so both transports drop them to
	 * count the same jobs. A job still completing is not finished.
	 */
	private const FINISHED = ['BOOT_FAIL', 'CANCELLED', 'COMPLETED', 'DEADLINE', 'FAILED', 'NODE_FAIL', 'OUT_OF_MEMORY', 'PREEMPTED', 'TIMEOUT'];

	/**
	 * @param list<string> $state the JSON job_state array (base state and flags)
	 */
	public static function finished(array $state): bool {
		$set = array_map(static fn (string $s): string => strtoupper(trim($s)), $state);

		return !in_array('COMPLETING', $set, true) && array_intersect($set, self::FINISHED) !== [];
	}

	/**
	 * @param list<string> $state the JSON job_state array (base state and flags)
	 */
	public static function of(array $state, string $reason): self {
		$set = array_map(static fn (string $s): string => strtoupper(trim($s)), $state);

		return match (true) {
			in_array('COMPLETING', $set, true) => self::Completing,
			in_array('PENDING', $set, true)    => ReasonFamily::of($reason) === ReasonFamily::Held ? self::Held : self::Pending,
			in_array('RUNNING', $set, true)    => self::Running,
			default                            => self::Other,
		};
	}

	public function label(): string {
		return match ($this) {
			self::Pending    => __('Pending', 'hpcsched'),
			self::Held       => __('Held', 'hpcsched'),
			self::Running    => __('Running', 'hpcsched'),
			self::Completing => __('Completing', 'hpcsched'),
			self::Other      => __('Other', 'hpcsched'),
		};
	}
}
