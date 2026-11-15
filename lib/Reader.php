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
 * One reader run for one cluster (REQ-READ-001 to REQ-READ-006).
 *
 * It takes the cluster's lease, builds the configured transport (reading a
 * token file at most once, for this run only), collects the fixed set of
 * sections within the time budget, writes the snapshot in one transaction
 * that commits only while the lease is held, and records the run. Failures
 * are recorded as error classes, never as zeros. Log lines name the cluster
 * by id and the failure by class, and pass through the Redactor, which knows
 * the run's token.
 */
final class Reader {
	public const BACKOFF_MAX = 600;

	public function __construct(
		private readonly RunRepository $runs,
		private readonly SnapshotRepository $snapshots,
		private readonly Transports $transports,
		private readonly Clock $clock,
		private readonly string $holder,
	) {
	}

	public function run(Cluster $cluster, int $pollerInterval): ClusterRun {
		$budget   = $cluster->budget($pollerInterval);
		$interval = $cluster->effectiveInterval($pollerInterval);

		if (!$this->runs->acquire($cluster->id, $this->holder, $budget + 60)) {
			Log::info(sprintf('Cluster %d skipped: another run holds the lease', $cluster->id));

			return $this->runs->get($cluster->id) ?? new ClusterRun($cluster->id);
		}

		try {
			return $this->runLeased($cluster, $budget, $interval);
		} finally {
			$this->runs->release($cluster->id, $this->holder);
		}
	}

	private function runLeased(Cluster $cluster, int $budget, int $interval): ClusterRun {
		$previous = $this->runs->get($cluster->id) ?? new ClusterRun($cluster->id);
		$redactor = new Redactor();
		$start    = microtime(true);
		$deadline = $start + $budget;
		$token    = null;
		$state    = TokenState::None;

		try {
			$transport = $this->transports->for($cluster, $redactor, $token);
			$state     = $token->state ?? TokenState::None;
			$snapshot  = (new SlurmBackend($transport, $this->clock))->collect($cluster, $deadline);
			$limited   = $transport->memoryLimited();
		} catch (TransportError $e) {
			$snapshot = $this->failed($cluster, $e->errorClass);
			$limited  = false;
		} catch (CredentialError $e) {
			$snapshot = $this->failed($cluster, ErrorClass::Credential);
			$state    = $e->state;
			$limited  = false;

			Log::warning(sprintf('Cluster %d token file: %s', $cluster->id, $redactor->clean($e->getMessage())));
		}

		$now      = $this->clock->now();
		$duration = microtime(true) - $start;
		$errors   = $snapshot->errors();
		$outcome  = $this->outcome($snapshot);
		$limited  = $limited || $cluster->transport === Transport::Rest;

		$rateLimited = in_array(ErrorClass::RateLimited, $errors, true);
		$step        = $rateLimited ? min($previous->backoffStep + 1, 10) : 0;
		$backoff     = $rateLimited ? $now + min(self::BACKOFF_MAX, $interval * (2 ** ($step - 1))) : 0;

		if (!$this->snapshots->apply($cluster->id, $this->holder, $now, $interval, $snapshot)) {
			Log::warning(sprintf('Cluster %d: snapshot discarded because the reader lease was lost or the write failed', $cluster->id));

			$outcome = RunOutcome::Failed;
			$errors  = array_merge([ErrorClass::Failed], $errors);
		}

		$run = new ClusterRun(
			clusterId: $cluster->id,
			finishedAt: $now,
			duration: round($duration, 3),
			outcome: $outcome,
			error: $errors[0] ?? null,
			release: $snapshot->release,
			transport: $snapshot->transport->value,
			dataParser: $snapshot->dataParser,
			calls: $snapshot->calls,
			lastSuccess: $outcome === RunOutcome::Completed ? $now : 0,
			lastFailure: $outcome === RunOutcome::Completed ? 0 : $now,
			backoffUntil: $backoff,
			backoffStep: $step,
			overran: $outcome === RunOutcome::Budget || $duration > $budget,
			memoryLimited: $limited,
			tokenState: $state,
			tokenExpires: $token->expires ?? 0,
		);

		$this->runs->finish($run, $this->holder);

		if ($errors !== []) {
			Log::warning($redactor->clean(sprintf('Cluster %d run %s: %s', $cluster->id, $outcome->value, implode(', ', array_unique(array_map(static fn (ErrorClass $e): string => $e->value, $errors))))));
		}

		if ($rateLimited) {
			Log::warning(sprintf('Cluster %d: the controller rate limited the reader; next run after %d seconds', $cluster->id, $backoff - $now));
		}

		return $run;
	}

	private function outcome(Snapshot $snapshot): RunOutcome {
		$states = array_map(static fn (SectionOutcome $o): SectionState => $o->state, $snapshot->outcomes);
		$done   = count(array_filter($states, static fn (SectionState $s): bool => $s === SectionState::Completed));
		$errors = $snapshot->errors();

		return match (true) {
			$done === count($states) && $states !== []                                  => RunOutcome::Completed,
			in_array(SectionState::NotAttempted, $states, true) && !self::stopped($errors) => RunOutcome::Budget,
			$done > 0                                                                   => RunOutcome::Partial,
			default                                                                     => RunOutcome::Failed,
		};
	}

	/**
	 * Whether the backend stopped early on purpose rather than for time.
	 *
	 * @param list<ErrorClass> $errors
	 */
	private static function stopped(array $errors): bool {
		return in_array(ErrorClass::RateLimited, $errors, true) || in_array(ErrorClass::Credential, $errors, true);
	}

	private function failed(Cluster $cluster, ErrorClass $error): Snapshot {
		$outcomes = [];

		foreach ($cluster->sections() as $section) {
			$outcomes[$section->value] = SectionOutcome::failed($error);
		}

		$now = $this->clock->now();

		return new Snapshot($now, $now, 'slurm', $cluster->transport, '', '', $outcomes);
	}
}
