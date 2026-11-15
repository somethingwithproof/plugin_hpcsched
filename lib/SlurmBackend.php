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
 * The Slurm backend: the only class that turns Slurm output into a snapshot
 * (ADR 0004). One scheduler call per section, a fixed set per run
 * (REQ-READ-006). A section that fails, for any reason, records its error
 * class and keeps no data, so a failure is never read as an empty section
 * (REQ-BACKEND-003). After a rate limit or a refused credential the rest of
 * the run is not attempted, since every later call would meet the same
 * answer and add load. A conditional read that finds nothing changed
 * completes its section with no data and is marked unchanged; the snapshot
 * write keeps that section's stored rows.
 */
final class SlurmBackend implements Backend {
	/**
	 * @param array<string, SectionRecord> $previous the cluster's stored section records, for conditional reads
	 * @param string                       $release  the release the scheduler reported on the last run
	 */
	public function __construct(
		private readonly SlurmTransport $transport,
		private readonly Clock $clock,
		private readonly array $previous = [],
		private readonly string $release = '',
	) {
	}

	public function name(): string {
		return 'slurm';
	}

	public function collect(Cluster $cluster, float $deadline): Snapshot {
		$started  = $this->clock->now();
		$outcomes = [];
		$data     = [];
		$release  = '';
		$parser   = '';
		$stop     = false;

		try {
			$parser = $this->transport->dataParser($deadline);
		} catch (TransportError $e) {
			foreach ($cluster->sections() as $section) {
				$outcomes[$section->value] = SectionOutcome::failed($e->errorClass);
			}

			return new Snapshot($started, $this->clock->now(), $this->name(), $this->transport->transport(), '', '', $outcomes, $this->transport->calls());
		}

		foreach ($cluster->sections() as $section) {
			if ($stop || microtime(true) >= $deadline) {
				$outcomes[$section->value] = SectionOutcome::notAttempted();

				continue;
			}

			$stored = $this->previous[$section->value] ?? null;
			$since  = $this->transport->since($section, $stored, $this->release, $this->clock->now());

			try {
				$doc     = SlurmJson::decode($this->transport->fetch($section, $parser, $deadline, $since));
				$release = $release !== '' ? $release : SlurmJson::release($doc);

				$parsed = match ($section) {
					Section::Jobs         => SlurmParser::jobs($doc),
					Section::Nodes        => SlurmParser::nodes($doc),
					Section::Partitions   => SlurmParser::partitions($doc),
					Section::Reservations => SlurmParser::reservations($doc),
					Section::Shares       => SlurmParser::shares($doc),
				};

				unset($doc);

				$data[$section->value]     = $parsed;
				$outcomes[$section->value] = SectionOutcome::completed($parsed instanceof JobTally ? $parsed->total() : count($parsed));
			} catch (Unchanged) {
				// Only a conditional read answers Unchanged, and only after a
				// completed one, so the stored rows are the scheduler's.
				$outcomes[$section->value] = SectionOutcome::unchanged($stored->records ?? 0);
			} catch (TransportError $e) {
				$outcomes[$section->value] = SectionOutcome::failed($e->errorClass);
				$stop                      = $e->errorClass->backsOff() || $e->errorClass === ErrorClass::Credential;

				Log::debug(sprintf('Section %s failed: %s', $section->value, $e->errorClass->value));
			}
		}

		$jobs = $data['jobs'] ?? null;

		return new Snapshot(
			startedAt: $started,
			finishedAt: $this->clock->now(),
			backend: $this->name(),
			transport: $this->transport->transport(),
			dataParser: $parser,
			release: $release,
			outcomes: $outcomes,
			calls: $this->transport->calls(),
			jobs: $jobs instanceof JobTally ? $jobs : null,
			nodes: self::listOf($data['nodes'] ?? null, NodeRecord::class),
			partitions: self::listOf($data['partitions'] ?? null, PartitionRecord::class),
			reservations: self::listOf($data['reservations'] ?? null, ReservationRecord::class),
			shares: self::listOf($data['shares'] ?? null, ShareRecord::class),
		);
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $class
	 *
	 * @return ?list<T>
	 */
	private static function listOf(mixed $value, string $class): ?array {
		if (!is_array($value)) {
			return null;
		}

		return array_values(array_filter($value, static fn (mixed $v): bool => $v instanceof $class));
	}
}
