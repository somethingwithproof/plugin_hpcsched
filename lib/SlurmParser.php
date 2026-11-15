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
 * Parses the pinned data parser's response types into snapshot records. The
 * same parser serves both transports, because squeue --json and GET /jobs/,
 * and scontrol --json show and the node, partition, and reservation
 * endpoints, dump the same types (docs/research/slurm-client-output.md).
 *
 * Job records are reduced to counts here; no job name, user, or account
 * leaves this class (ADR 0010). Fair-share keeps account associations only.
 */
final class SlurmParser {
	/**
	 * @param array<array-key, mixed> $doc
	 *
	 * @throws TransportError
	 */
	public static function jobs(array $doc): JobTally {
		$tally = new JobTally();

		foreach (SlurmJson::records($doc, 'jobs') as $job) {
			$states = SlurmJson::strings($job, 'job_state');

			if ($states === []) {
				throw new TransportError(ErrorClass::Malformed, 'job without a state');
			}

			if (JobState::finished($states)) {
				continue;
			}

			$reason = SlurmJson::string($job, 'state_reason');
			$state  = JobState::of($states, $reason);
			$tasks  = SlurmJson::string($job, 'array_task_string');

			// A pending array that has not split into tasks is one record
			// carrying its remaining tasks as an expression.
			$count = ($state === JobState::Pending || $state === JobState::Held) && $tasks !== '' ? SlurmJson::arrayTasks($tasks) : 1;

			$tally->add(self::names(SlurmJson::strings($job, 'partition')), $state, $state === JobState::Pending || $state === JobState::Held ? $reason : '', $count);
		}

		return $tally;
	}

	/**
	 * @param array<array-key, mixed> $doc
	 *
	 * @return list<NodeRecord>
	 *
	 * @throws TransportError
	 */
	public static function nodes(array $doc): array {
		$nodes = [];

		foreach (SlurmJson::records($doc, 'nodes') as $node) {
			$name  = SlurmJson::string($node, 'name');
			$flags = NodeState::normalize(SlurmJson::strings($node, 'state'));

			if ($name === '' || $flags === []) {
				throw new TransportError(ErrorClass::Malformed, 'node without a name or state');
			}

			$nodes[] = new NodeRecord(
				name: self::clip($name, 64),
				hostname: self::clip(SlurmJson::string($node, 'hostname'), 255),
				address: self::clip(SlurmJson::string($node, 'address'), 255),
				partitions: self::names(SlurmJson::strings($node, 'partitions')),
				flags: $flags,
				state: NodeState::fromFlags($flags),
				reason: self::clip(SlurmJson::string($node, 'reason'), 255),
				reasonChangedAt: SlurmJson::int($node, 'reason_changed_at') ?? 0,
				cpus: SlurmJson::int($node, 'cpus') ?? 0,
				allocCpus: SlurmJson::int($node, 'alloc_cpus') ?? 0,
			);
		}

		return $nodes;
	}

	/**
	 * @param array<array-key, mixed> $doc
	 *
	 * @return list<PartitionRecord>
	 *
	 * @throws TransportError
	 */
	public static function partitions(array $doc): array {
		$out = [];

		foreach (SlurmJson::records($doc, 'partitions') as $partition) {
			$name = SlurmJson::string($partition, 'name');

			if ($name === '') {
				throw new TransportError(ErrorClass::Malformed, 'partition without a name');
			}

			$inner = is_array($partition['partition'] ?? null) ? $partition['partition'] : [];
			$nodes = is_array($partition['nodes'] ?? null) ? $partition['nodes'] : [];
			$cpus  = is_array($partition['cpus'] ?? null) ? $partition['cpus'] : [];

			$out[] = new PartitionRecord(
				name: self::clip($name, 64),
				state: self::clip(implode('+', SlurmJson::strings($inner, 'state')), 32),
				totalNodes: SlurmJson::int($nodes, 'total') ?? 0,
				totalCpus: SlurmJson::int($cpus, 'total') ?? 0,
			);
		}

		return $out;
	}

	/**
	 * @param array<array-key, mixed> $doc
	 *
	 * @return list<ReservationRecord>
	 *
	 * @throws TransportError
	 */
	public static function reservations(array $doc): array {
		$out = [];

		foreach (SlurmJson::records($doc, 'reservations') as $reservation) {
			$name = SlurmJson::string($reservation, 'name');

			if ($name === '') {
				throw new TransportError(ErrorClass::Malformed, 'reservation without a name');
			}

			$out[] = new ReservationRecord(
				name: self::clip($name, 128),
				flags: array_map('strtoupper', SlurmJson::strings($reservation, 'flags')),
				nodeList: self::clip(SlurmJson::string($reservation, 'node_list'), 65535),
				start: SlurmJson::int($reservation, 'start_time') ?? 0,
				end: SlurmJson::int($reservation, 'end_time') ?? 0,
			);
		}

		return $out;
	}

	/**
	 * Account associations from the shares response. User rows are dropped
	 * before they become records, so no user name is kept.
	 *
	 * @param array<array-key, mixed> $doc
	 *
	 * @return list<ShareRecord>
	 *
	 * @throws TransportError
	 */
	public static function shares(array $doc): array {
		$wrap = $doc['shares'] ?? null;

		if (!is_array($wrap) || array_is_list($wrap)) {
			throw new TransportError(ErrorClass::Malformed, 'no shares object');
		}

		$out = [];

		foreach (SlurmJson::records($wrap, 'shares') as $row) {
			$type = array_map('strtoupper', SlurmJson::strings($row, 'type'));
			$name = SlurmJson::string($row, 'name');

			// Only rows that say they are associations are kept. A row with no
			// type, any other type, or the USER flag could carry a user name,
			// and user names never reach the share table.
			if (!in_array('ASSOCIATION', $type, true) || in_array('USER', $type, true) || $name === '') {
				continue;
			}

			$fairshare = is_array($row['fairshare'] ?? null) ? $row['fairshare'] : [];

			$out[] = new ShareRecord(
				account: self::clip($name, 64),
				partition: self::clip(SlurmJson::string($row, 'partition'), 64),
				parent: self::clip(SlurmJson::string($row, 'parent'), 64),
				usage: SlurmJson::float($row, 'usage'),
				usageNormalized: SlurmJson::float($row, 'usage_normalized'),
				effectiveUsage: SlurmJson::float($row, 'effective_usage'),
				fairshareFactor: SlurmJson::float($fairshare, 'factor'),
				fairshareLevel: SlurmJson::float($fairshare, 'level'),
			);
		}

		return $out;
	}

	/**
	 * Partition names as counter keys: at most 64 characters, and names
	 * that are not plain identifiers are dropped rather than stored.
	 *
	 * @param list<string> $names
	 *
	 * @return list<string>
	 */
	private static function names(array $names): array {
		return array_values(array_unique(array_filter($names, static fn (string $n): bool => preg_match('/^[A-Za-z0-9._-]{1,64}$/', $n) === 1)));
	}

	private static function clip(string $text, int $length): string {
		return mb_substr($text, 0, $length, 'UTF-8');
	}
}
