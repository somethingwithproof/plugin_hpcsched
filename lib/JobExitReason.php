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
 * Canonical terminal job exit outcomes aggregated per partition and cluster:
 * OOM, TIMEOUT, NODE_FAIL, PREEMPTED, CANCELLED, FAILED, and COMPLETED.
 */
enum JobExitReason: string {
	case Oom       = 'OOM';
	case Timeout   = 'TIMEOUT';
	case NodeFail  = 'NODE_FAIL';
	case Preempted = 'PREEMPTED';
	case Cancelled = 'CANCELLED';
	case Failed    = 'FAILED';
	case Completed = 'COMPLETED';

	/**
	 * Classify a job's Slurm state list and optional state reason into a
	 * terminal exit outcome, or null when the job is still active/completing.
	 *
	 * @param list<string> $states Slurm job_state tokens (base state and flags)
	 * @param string       $reason optional Slurm state_reason string
	 *
	 * @return ?self
	 */
	public static function classify(array $states, string $reason = ''): ?self {
		$flags = [];

		foreach ($states as $raw) {
			$token = strtoupper(trim($raw));

			if ($token === '') {
				continue;
			}

			// Slurm sacct can format "CANCELLED by 1000"; keep leading state token.
			$space = strpos($token, ' ');

			if ($space !== false) {
				$token = substr($token, 0, $space);
			}

			$flags[$token] = true;
		}

		// Jobs still completing have not reached final terminal accounting state.
		if (isset($flags['COMPLETING'])) {
			return null;
		}

		$normReason = strtoupper(trim($reason));

		if (isset($flags['OUT_OF_MEMORY']) || isset($flags['OOM']) || $normReason === 'OUTOFMEMORY' || $normReason === 'OUT_OF_MEMORY') {
			return self::Oom;
		}

		if (isset($flags['TIMEOUT']) || isset($flags['DEADLINE'])) {
			return self::Timeout;
		}

		if (isset($flags['NODE_FAIL']) || isset($flags['BOOT_FAIL'])) {
			return self::NodeFail;
		}

		if (isset($flags['PREEMPTED'])) {
			return self::Preempted;
		}

		if (isset($flags['CANCELLED'])) {
			return self::Cancelled;
		}

		if (isset($flags['FAILED']) || isset($flags['SPECIAL_EXIT'])) {
			return self::Failed;
		}

		if (isset($flags['COMPLETED'])) {
			return self::Completed;
		}

		return null;
	}
}
