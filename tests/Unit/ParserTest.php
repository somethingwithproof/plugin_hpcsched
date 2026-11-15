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

use Somethingwithproof\Hpcsched\ErrorClass;
use Somethingwithproof\Hpcsched\JobState;
use Somethingwithproof\Hpcsched\JobTally;
use Somethingwithproof\Hpcsched\NodeState;
use Somethingwithproof\Hpcsched\ReasonFamily;
use Somethingwithproof\Hpcsched\SlurmJson;
use Somethingwithproof\Hpcsched\SlurmParser;
use Somethingwithproof\Hpcsched\TransportError;

/**
 * @return array<array-key, mixed>
 */
function hpcsched_fixture(string $release, string $section): array {
	return SlurmJson::decode((string) file_get_contents(dirname(__DIR__) . "/fixtures/slurm/$release/$section.json"));
}

dataset('releases', ['25.05', '25.11', '26.05']);

it('marks every fixture as synthetic', function () {
	foreach (glob(dirname(__DIR__) . '/fixtures/slurm/*/*.json') ?: [] as $file) {
		expect((string) file_get_contents($file))->toContain('"_fixture": "synthetic');
	}
});

it('counts jobs by state, family, and partition, with arrays as their task count', function (string $release) {
	$doc   = hpcsched_fixture($release, 'jobs');
	$tally = SlurmParser::jobs($doc);

	expect(SlurmJson::release($doc))->toStartWith($release)
		->and($tally->count(JobTally::CLUSTER, JobState::Running))->toBe(2)
		->and($tally->count(JobTally::CLUSTER, JobState::Pending))->toBe(63)
		->and($tally->count(JobTally::CLUSTER, JobState::Held))->toBe(1)
		->and($tally->count(JobTally::CLUSTER, JobState::Completing))->toBe(1)
		->and($tally->total())->toBe(67)
		->and($tally->pendingIn(JobTally::CLUSTER, ReasonFamily::Resources))->toBe(10)
		->and($tally->pendingIn(JobTally::CLUSTER, ReasonFamily::Assoc))->toBe(50)
		->and($tally->pendingIn(JobTally::CLUSTER, ReasonFamily::Held))->toBe(1)
		->and($tally->pendingIn(JobTally::CLUSTER, ReasonFamily::Node))->toBe(1)
		->and($tally->count('batch', JobState::Pending))->toBe(62)
		->and($tally->count('gpu', JobState::Pending))->toBe(2)
		->and($tally->count('gpu', JobState::Running))->toBe(1);
})->with('releases');

it('keeps no job name, user, or account from the jobs response', function (string $release) {
	$rows = json_encode(SlurmParser::jobs(hpcsched_fixture($release, 'jobs'))->rows());

	expect($rows)->not->toContain('alice')
		->and($rows)->not->toContain('physics')
		->and($rows)->not->toContain('secret-project')
		->and($rows)->not->toContain('UnavailableNodes');
})->with('releases');

it('parses nodes with normalized and raw state, keeping the reason text', function (string $release) {
	$nodes = SlurmParser::nodes(hpcsched_fixture($release, 'nodes'));
	$by    = [];

	foreach ($nodes as $node) {
		$by[$node->name] = $node;
	}

	expect(count($nodes))->toBe(8)
		->and($by['n03']->state)->toBe(NodeState::Down)
		->and($by['n03']->drained())->toBeTrue()
		->and($by['n03']->flags)->toBe(['DOWN', 'DRAIN', 'NOT_RESPONDING'])
		->and($by['n03']->reason)->toBe('bad DIMM <b>')
		->and($by['n03']->reasonChangedAt)->toBe(1794700000)
		->and($by['n04']->state)->toBe(NodeState::Draining)
		->and($by['n04']->partitions)->toBe(['batch', 'gpu'])
		->and($by['g01']->state)->toBe(NodeState::Drained)
		->and($by['g02']->state)->toBe(NodeState::PoweredDown)
		->and($by['g03']->state)->toBe(NodeState::Maintenance)
		->and($by['g04']->state)->toBe(NodeState::Other)
		->and($by['g04']->flags)->toBe(['FUTURE_STATE_X'])
		->and($by['n02']->allocCpus)->toBe(64);
})->with('releases');

it('parses partitions and reservations', function (string $release) {
	$partitions   = SlurmParser::partitions(hpcsched_fixture($release, 'partitions'));
	$reservations = SlurmParser::reservations(hpcsched_fixture($release, 'reservations'));

	expect($partitions[0]->name)->toBe('batch')
		->and($partitions[0]->state)->toBe('UP')
		->and($partitions[0]->totalNodes)->toBe(4)
		->and($partitions[1]->totalCpus)->toBe(192)
		->and($reservations[0]->name)->toBe('maint_n01')
		->and($reservations[0]->flags)->toContain('MAINT')
		->and($reservations[0]->nodeList)->toBe('n[01-02]')
		->and($reservations[0]->end - $reservations[0]->start)->toBe(3600);
})->with('releases');

it('keeps account associations only from the shares response, and unset values as unknown', function (string $release) {
	$shares = SlurmParser::shares(hpcsched_fixture($release, 'shares'));
	$names  = array_map(fn ($s) => $s->account, $shares);

	expect($names)->toBe(['root', 'physics', 'chem'])
		->and($shares[1]->usageNormalized)->toBe(0.75)
		->and($shares[1]->fairshareFactor)->toBe(0.25)
		->and($shares[1]->usage)->toBe(3750000.0)
		->and($shares[2]->usageNormalized)->toBeNull()
		->and($shares[2]->fairshareLevel)->toBeNull()
		->and($shares[2]->effectiveUsage)->toBe(0.0);
})->with('releases');

it('fails a broken response instead of reading it as empty', function (string $file, string $section, ErrorClass $class) {
	$parse = function () use ($file, $section): void {
		$doc = SlurmJson::decode((string) file_get_contents(dirname(__DIR__) . "/fixtures/slurm/broken/$file.json"));

		match ($section) {
			'jobs'  => SlurmParser::jobs($doc),
			'nodes' => SlurmParser::nodes($doc),
		};
	};

	try {
		$parse();
		$caught = null;
	} catch (TransportError $e) {
		$caught = $e->errorClass;
	}

	expect($caught)->toBe($class);
})->with([
	'truncated'     => ['truncated', 'jobs', ErrorClass::Malformed],
	'errors listed' => ['errors', 'jobs', ErrorClass::Access],
	'no list'       => ['nolist', 'nodes', ErrorClass::Malformed],
	'no state'      => ['nostate', 'nodes', ErrorClass::Malformed],
]);

it('refuses documents nested deeper than the limit, and non-objects', function () {
	expect(fn () => SlurmJson::decode(str_repeat('[', 100) . str_repeat(']', 100)))->toThrow(TransportError::class)
		->and(fn () => SlurmJson::decode('[]'))->toThrow(TransportError::class)
		->and(fn () => SlurmJson::decode('"x"'))->toThrow(TransportError::class);
});

it('counts array task expressions, and never counts a pending array as none', function (string $expression, int $tasks) {
	expect(SlurmJson::arrayTasks($expression))->toBe($tasks);
})->with([
	['0-9', 10],
	['0-9%2', 10],
	['1,3,5-9', 7],
	['1-99:2', 50],
	['7', 1],
	['', 1],
	['garbage', 1],
	['9-1', 1],
	['0-99999999', 4000001],
]);

it('reads plain and object numbers and treats unset or infinite as unknown', function () {
	expect(SlurmJson::int(['v' => 5], 'v'))->toBe(5)
		->and(SlurmJson::int(['v' => ['set' => true, 'infinite' => false, 'number' => 7]], 'v'))->toBe(7)
		->and(SlurmJson::int(['v' => ['set' => false, 'number' => 0]], 'v'))->toBeNull()
		->and(SlurmJson::int(['v' => ['set' => true, 'infinite' => true, 'number' => 0]], 'v'))->toBeNull()
		->and(SlurmJson::int(['v' => -1], 'v'))->toBeNull()
		->and(SlurmJson::float(['v' => '0.5'], 'v'))->toBe(0.5)
		->and(SlurmJson::release(['meta' => ['slurm' => ['release' => '25.11.2; rm -rf /']]]))->toBe('');
});
