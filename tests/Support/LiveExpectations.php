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


namespace Somethingwithproof\Hpcsched\Tests;

// Test code: refuse to run from a web request.
PHP_SAPI === 'cli' || exit(1);

use Somethingwithproof\Hpcsched\JobState;
use Somethingwithproof\Hpcsched\NodeState;
use Somethingwithproof\Hpcsched\Reasons;
use Somethingwithproof\Hpcsched\SlurmJson;
use Somethingwithproof\Hpcsched\SlurmParser;

/**
 * What tests/slurm-live/states.sh creates on a live Slurm cluster, checked
 * through the plugin's parser on one data parser's captures: client
 * command output (squeue, scontrol, sshare) and slurmrestd output. The CI
 * job runs it on fresh captures; the unit suite on the recorded ones.
 *
 * Six nodes c1 to c6 in partition cpu: c1 allocated, c2 mixed, c3 drained
 * with a reason holding a pipe, c4 down, c5 down and drained, c6 in a
 * maintenance reservation. Jobs: two running, and pending ones for
 * Resources, ReqNodeNotAvail, and a user hold, a 12 task array, and a
 * 6 task throttled array, plus a completed and a failed job that only
 * slurmrestd still lists.
 */
final class LiveExpectations {
	/**
	 * @return list<array{0: string, 1: bool, 2: mixed}>
	 */
	public static function check(string $dir, string $parser): array {
		$out  = [];
		$load = static fn (string $file): array => SlurmJson::decode((string) file_get_contents("$dir/$file"));
		$add  = static function (string $label, bool $pass, mixed $got = null) use (&$out, $parser): void {
			$out[] = ["$parser $label", $pass, $got];
		};

		$reservations = [];

		foreach (SlurmParser::reservations($load("scontrol-reservation.$parser.json")) as $reservation) {
			$reservations[$reservation->name] = $reservation;
		}

		$add('two reservations', count($reservations) === 2, array_keys($reservations));
		$add('maint_c6 is a MAINT reservation', in_array('MAINT', $reservations['maint_c6']->flags ?? [], true), $reservations['maint_c6'] ?? null);

		// slurmrestd may serve fewer parsers than the client lists.
		$rest = is_file("$dir/rest-$parser-jobs.json");

		foreach ($rest ? ["scontrol-node.$parser.json", "rest-$parser-nodes.json"] : ["scontrol-node.$parser.json"] as $file) {
			$nodes = [];

			foreach (SlurmParser::nodes($load($file)) as $node) {
				$nodes[$node->name] = $node;
			}

			$c6 = $nodes['c6']->state ?? null;

			$add("$file six nodes", count($nodes) === 6, array_keys($nodes));
			$add("$file c1 allocated", ($nodes['c1']->state ?? null) === NodeState::Allocated);
			$add("$file c2 mixed", ($nodes['c2']->state ?? null) === NodeState::Mixed);
			$add("$file c3 drained", ($nodes['c3']->state ?? null) === NodeState::Drained && $nodes['c3']->drained());
			$add("$file c4 down, not drained", ($nodes['c4']->state ?? null) === NodeState::Down && !$nodes['c4']->drained());
			// sinfo's %T prints "drained" here; the JSON keeps both.
			$add("$file c5 down and drained", ($nodes['c5']->state ?? null) === NodeState::Down && $nodes['c5']->drained(), $nodes['c5']->flags ?? null);
			// 25.11.8 sets the node flags minutes after the reservation
			// starts; until then c6 is idle and the reservation is the
			// evidence (checked above).
			$add("$file c6 in maintenance, or idle before its flags arrive", $c6 === NodeState::Maintenance || ($c6 === NodeState::Idle && isset($reservations['maint_c6'])), $nodes['c6']->flags ?? null);
			$add("$file c3 reason keeps its pipe", ($nodes['c3']->reason ?? null) === 'fan fail | rack 4', $nodes['c3']->reason ?? null);
		}

		$tallies = [];

		foreach ($rest ? ["squeue.$parser.json", "rest-$parser-jobs.json"] : ["squeue.$parser.json"] as $file) {
			$tally   = SlurmParser::jobs($load($file));
			$reasons = [];

			foreach ($tally->rows() as $row) {
				if ($row['partition'] === 'cpu' && $row['kind'] === 'reason') {
					$reasons[$row['name']] = $row['jobs'];
				}
			}

			$tallies[$file] = $tally->rows();

			$add("$file running 2", $tally->count('cpu', JobState::Running) === 2, $tally->count('cpu', JobState::Running));
			// 1 + 1 + 12 + 6 tasks; the held job is apart.
			$add("$file pending 20, counted by task", $tally->count('cpu', JobState::Pending) === 20, $tally->count('cpu', JobState::Pending));
			$add("$file held 1", $tally->count('cpu', JobState::Held) === 1, $tally->count('cpu', JobState::Held));
			$add("$file finished jobs are not counted", $tally->count('cpu', JobState::Other) === 0, $tally->count('cpu', JobState::Other));
			$add("$file JobHeldUser 1", ($reasons['JobHeldUser'] ?? 0) === 1, $reasons);
			$add("$file every pending task has a reason", array_sum($reasons) === 21, $reasons);
			$add("$file no unrecognized reason", !isset($reasons[Reasons::UNRECOGNIZED]), $reasons);
		}

		if ($rest) {
			$add('client commands and slurmrestd give the same job counts', array_values($tallies)[0] === array_values($tallies)[1], $tallies);
		}

		$partitions = [];

		foreach (SlurmParser::partitions($load("scontrol-partition.$parser.json")) as $partition) {
			$partitions[$partition->name] = $partition;
		}

		$add('partition cpu has 6 nodes', ($partitions['cpu']->totalNodes ?? null) === 6, $partitions['cpu']->totalNodes ?? null);
		$add('partition gpu is empty', ($partitions['gpu']->totalNodes ?? null) === 0, $partitions['gpu']->totalNodes ?? null);
		$add('fair-share rows', count(SlurmParser::shares($load("sshare.$parser.json"))) >= 3);

		return $out;
	}
}
