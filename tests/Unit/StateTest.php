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

use Somethingwithproof\Hpcsched\AdminState;
use Somethingwithproof\Hpcsched\Cluster;
use Somethingwithproof\Hpcsched\ClusterValues;
use Somethingwithproof\Hpcsched\ErrorClass;
use Somethingwithproof\Hpcsched\FixedClock;
use Somethingwithproof\Hpcsched\Freshness;
use Somethingwithproof\Hpcsched\FreshnessEvaluator;
use Somethingwithproof\Hpcsched\JobState;
use Somethingwithproof\Hpcsched\NodeState;
use Somethingwithproof\Hpcsched\ReasonFamily;
use Somethingwithproof\Hpcsched\Reasons;
use Somethingwithproof\Hpcsched\Section;
use Somethingwithproof\Hpcsched\SectionRecord;
use Somethingwithproof\Hpcsched\SectionState;
use Somethingwithproof\Hpcsched\Transport;

describe('freshness (REQ-STATE-001)', function () {
	it('classifies each boundary of a 60 second interval', function (int $age, Freshness $expected) {
		$evaluator = new FreshnessEvaluator(new FixedClock(100000));

		expect($evaluator->evaluate(100000 - $age, 60))->toBe($expected);
	})->with([
		'just collected'        => [0, Freshness::Current],
		'one interval'          => [60, Freshness::Current],
		'two intervals'         => [120, Freshness::Current],
		'just past two'         => [121, Freshness::Delayed],
		'three intervals'       => [180, Freshness::Delayed],
		'just past three'       => [181, Freshness::Stale],
		'a day'                 => [86400, Freshness::Stale],
		'small clock skew'      => [-120, Freshness::Current],
		'future beyond skew'    => [-121, Freshness::Stale],
	]);

	it('reports never collected for no observation, and only current and delayed as usable', function () {
		$evaluator = new FreshnessEvaluator(new FixedClock(100000));

		expect($evaluator->evaluate(0, 60))->toBe(Freshness::Never)
			->and($evaluator->age(0))->toBeNull()
			->and(Freshness::Current->usable())->toBeTrue()
			->and(Freshness::Delayed->usable())->toBeTrue()
			->and(Freshness::Stale->usable())->toBeFalse()
			->and(Freshness::Never->usable())->toBeFalse();
	});

	it('lets a stopped reader age into stale with age still rising (REQ-STATE-003)', function () {
		$cluster  = new Cluster(1, 'demo', 1, AdminState::Enabled, Transport::Commands, 60);
		$sections = [];

		foreach ($cluster->sections() as $section) {
			$sections[$section->value] = new SectionRecord(1, $section, SectionState::Completed, null, 100000, 5, 100000, 100000, 60);
		}

		$jobs  = ['running' => 0, 'pending' => 4];
		$nodes = ['idle' => 3, 'drain_flag' => 0];
		$seen  = [];

		foreach ([0, 120, 180, 181, 600] as $step) {
			$v      = ClusterValues::of($cluster, $sections, $jobs, $nodes, new FreshnessEvaluator(new FixedClock(100000 + $step)), 60);
			$seen[] = ['freshness' => $v->freshness, 'age' => $v->age, 'running' => $v->values['running'], 'nodes' => $v->values['nodes_total']];
		}

		expect(array_column($seen, 'freshness'))->toBe([Freshness::Current, Freshness::Current, Freshness::Delayed, Freshness::Stale, Freshness::Stale])
			->and(array_column($seen, 'age'))->toBe([0, 120, 180, 181, 600])
			->and($seen[0]['running'])->toBe(0)
			->and($seen[2]['running'])->toBe(0)
			->and($seen[3]['running'])->toBeNull()
			->and($seen[4]['nodes'])->toBeNull();
	});

	it('serves unknown for a failed section that never completed, and keeps zero distinct', function () {
		$clock    = new FixedClock(1000);
		$cluster  = new Cluster(1, 'demo', 1, AdminState::Enabled, Transport::Commands, 60);
		$sections = [
			'jobs'       => new SectionRecord(1, Section::Jobs, SectionState::Completed, null, 1000, 0, 1000, 1000, 60),
			'nodes'      => new SectionRecord(1, Section::Nodes, SectionState::Failed, ErrorClass::Timeout, 1000, 0, 0, 0, 0),
			'partitions' => new SectionRecord(1, Section::Partitions, SectionState::Completed, null, 1000, 1, 1000, 1000, 60),
		];
		$v = ClusterValues::of($cluster, $sections, [], [], new FreshnessEvaluator($clock), 60);

		expect($v->freshness)->toBe(Freshness::Stale)
			->and($v->values['running'])->toBe(0)
			->and($v->values['nodes_total'])->toBeNull();
	});

	it('uses the poller interval when it is longer than the cluster interval', function () {
		$cluster = new Cluster(1, 'demo', 1, AdminState::Enabled, Transport::Commands, 60);

		expect($cluster->effectiveInterval(300))->toBe(300)
			->and($cluster->budget(300))->toBe(150)
			->and($cluster->budget(60))->toBe(30)
			->and($cluster->effectiveInterval(30))->toBe(60);
	});

	it('never sets the interval below the floor', function () {
		$cluster = Cluster::fromRow(['id' => 1, 'name' => 'x', 'admin_state' => 'enabled', 'transport' => 'commands', 'collect_interval' => '5']);

		expect($cluster?->interval)->toBe(Cluster::MIN_INTERVAL);
	});
});

describe('node state', function () {
	it('normalizes base state and flags with DOWN taking precedence', function (array $flags, NodeState $expected, bool $drained) {
		expect(NodeState::fromFlags($flags))->toBe($expected)
			->and(NodeState::drained($flags))->toBe($drained);
	})->with([
		'idle'                     => [['IDLE'], NodeState::Idle, false],
		'allocated'                => [['ALLOCATED'], NodeState::Allocated, false],
		'mixed'                    => [['MIXED'], NodeState::Mixed, false],
		'down and drained'         => [['DOWN', 'DRAIN'], NodeState::Down, true],
		'down, drain, no response' => [['DOWN', 'DRAIN', 'NOT_RESPONDING'], NodeState::Down, true],
		'idle drained'             => [['IDLE', 'DRAIN'], NodeState::Drained, true],
		'mixed draining'           => [['MIXED', 'DRAIN'], NodeState::Draining, true],
		'allocated draining'       => [['ALLOCATED', 'DRAIN'], NodeState::Draining, true],
		'maintenance'              => [['IDLE', 'MAINTENANCE', 'RESERVED'], NodeState::Maintenance, false],
		'reserved'                 => [['IDLE', 'RESERVED'], NodeState::Reserved, false],
		'powered down'             => [['IDLE', 'POWERED_DOWN'], NodeState::PoweredDown, false],
		'scontrol text'            => [explode('+', 'DOWN+DRAIN'), NodeState::Down, true],
		'sinfo suffix'             => [['idle*'], NodeState::Idle, false],
		'unknown later state'      => [['FUTURE_STATE_X'], NodeState::Other, false],
		'nothing'                  => [[], NodeState::Other, false],
	]);
});

describe('job state and reasons', function () {
	it('separates held from pending and keeps completing apart', function (array $state, string $reason, JobState $expected) {
		expect(JobState::of($state, $reason))->toBe($expected);
	})->with([
		[['PENDING'], 'Priority', JobState::Pending],
		[['PENDING'], 'JobHeldAdmin', JobState::Held],
		[['PENDING'], 'JobHeldUser', JobState::Held],
		[['PENDING'], 'JobHoldMaxRequeue', JobState::Held],
		[['RUNNING'], 'None', JobState::Running],
		[['RUNNING', 'COMPLETING'], 'None', JobState::Completing],
		[['SUSPENDED'], 'None', JobState::Other],
	]);

	it('maps reasons to families with the first matching rule', function (string $reason, ReasonFamily $family) {
		expect(ReasonFamily::of($reason))->toBe($family);
	})->with([
		['Priority', ReasonFamily::Priority],
		['Resources', ReasonFamily::Resources],
		['Licenses', ReasonFamily::Resources],
		['NvidiaImexChannels', ReasonFamily::Resources],
		['Dependency', ReasonFamily::Dependency],
		['DependencyNeverSatisfied', ReasonFamily::Dependency],
		['JobHeldAdmin', ReasonFamily::Held],
		['BeginTime', ReasonFamily::BeginTime],
		['ReservationDeleted', ReasonFamily::Reservation],
		['AssocGrpCpuLimit', ReasonFamily::Assoc],
		['QOSMaxJobsPerUserLimit', ReasonFamily::Qos],
		['MaxCpuPerAccount', ReasonFamily::Qos],
		['MaxMemoryPerUser', ReasonFamily::Qos],
		['PartitionTimeLimit', ReasonFamily::Partition],
		['ReqNodeNotAvail, UnavailableNodes:n[03-04]', ReasonFamily::Node],
		['NodeDown', ReasonFamily::Node],
		['MaxPoweredUpNodes', ReasonFamily::Node],
		['SomethingNew', ReasonFamily::Other],
		['', ReasonFamily::Other],
	]);

	// ADR 0011: the counts per family of every distinct reason string in
	// Slurm source, which docs/research/slurm-states-reasons.md recorded.
	it('maps every reason in Slurm source at each release to the documented family', function (string $release, array $counts) {
		$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/slurm/reasons.json'), true);
		$tally   = [];

		foreach ($fixture['reasons'][$release] as $reason) {
			$family         = ReasonFamily::of($reason)->value;
			$tally[$family] = ($tally[$family] ?? 0) + 1;
		}

		ksort($tally);
		ksort($counts);

		expect($tally)->toBe($counts);
	})->with([
		'24.11' => ['24.11', ['priority' => 1, 'resources' => 2, 'dependency' => 2, 'held' => 3, 'begin_time' => 1, 'reservation' => 3, 'assoc' => 61, 'qos' => 109, 'partition' => 5, 'node' => 3, 'other' => 26]],
		'25.05' => ['25.05', ['priority' => 1, 'resources' => 2, 'dependency' => 2, 'held' => 3, 'begin_time' => 1, 'reservation' => 3, 'assoc' => 61, 'qos' => 109, 'partition' => 5, 'node' => 3, 'other' => 25]],
		'25.11' => ['25.11', ['priority' => 1, 'resources' => 3, 'dependency' => 2, 'held' => 3, 'begin_time' => 1, 'reservation' => 3, 'assoc' => 61, 'qos' => 109, 'partition' => 5, 'node' => 3, 'other' => 25]],
		'26.05' => ['26.05', ['priority' => 1, 'resources' => 3, 'dependency' => 2, 'held' => 3, 'begin_time' => 1, 'reservation' => 3, 'assoc' => 61, 'qos' => 109, 'partition' => 5, 'node' => 3, 'other' => 25]],
		'26.11' => ['26.11', ['priority' => 1, 'resources' => 3, 'dependency' => 2, 'held' => 3, 'begin_time' => 1, 'reservation' => 3, 'assoc' => 61, 'qos' => 109, 'partition' => 6, 'node' => 3, 'other' => 26]],
	]);

	it('keeps every source reason a counter key as it is, so the page lists it by its own name', function () {
		$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/slurm/reasons.json'), true);

		foreach ($fixture['reasons'] as $reasons) {
			foreach ($reasons as $reason) {
				expect(Reasons::name($reason))->toBe($reason);
			}
		}
	});

	it('keeps family codes within the 19 characters of an RRD data source name', function () {
		foreach (ReasonFamily::cases() as $family) {
			expect(strlen($family->value))->toBeLessThanOrEqual(19);
		}
	});

	it('stores only the reason identifier, never free text', function () {
		expect(Reasons::name('ReqNodeNotAvail, UnavailableNodes:n[03-04]'))->toBe('ReqNodeNotAvail')
			->and(Reasons::name('<script>'))->toBe(Reasons::UNRECOGNIZED)
			->and(Reasons::name(''))->toBe('None')
			->and(strlen(Reasons::name(str_repeat('a', 300))))->toBe(64);
	});
});
