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

require_once dirname(__DIR__) . '/Support/StubTransport.php';

use Somethingwithproof\Hpcsched\AdminState;
use Somethingwithproof\Hpcsched\Cluster;
use Somethingwithproof\Hpcsched\ErrorClass;
use Somethingwithproof\Hpcsched\FixedClock;
use Somethingwithproof\Hpcsched\Section;
use Somethingwithproof\Hpcsched\SectionState;
use Somethingwithproof\Hpcsched\SlurmBackend;
use Somethingwithproof\Hpcsched\Tests\StubTransport;
use Somethingwithproof\Hpcsched\Transport;

/**
 * @return array<string, string>
 */
function hpcsched_answers(string $release = '25.11'): array {
	$out = [];

	foreach (['jobs', 'nodes', 'partitions', 'reservations', 'shares'] as $section) {
		$out[$section] = (string) file_get_contents(dirname(__DIR__) . "/fixtures/slurm/$release/$section.json");
	}

	return $out;
}

function hpcsched_cluster(bool $all = true): Cluster {
	return new Cluster(1, 'demo', 1, AdminState::Enabled, Transport::Commands, 60, reservations: $all, shares: $all);
}

it('builds a whole snapshot with one call per section', function () {
	$transport = new StubTransport(hpcsched_answers());
	$snapshot  = (new SlurmBackend($transport, new FixedClock(5000)))->collect(hpcsched_cluster(), microtime(true) + 10);

	expect($transport->fetched)->toBe(['jobs', 'nodes', 'partitions', 'reservations', 'shares'])
		->and($snapshot->calls)->toBe(5)
		->and($snapshot->release)->toBe('25.11.2')
		->and($snapshot->dataParser)->toBe('v0.0.43')
		->and($snapshot->backend)->toBe('slurm')
		->and($snapshot->errors())->toBe([])
		->and($snapshot->jobs?->total())->toBe(67)
		->and(count($snapshot->nodes ?? []))->toBe(8)
		->and($snapshot->outcome(Section::Nodes)->records)->toBe(8);
});

it('collects only the configured sections', function () {
	$transport = new StubTransport(hpcsched_answers());

	(new SlurmBackend($transport, new FixedClock(5000)))->collect(hpcsched_cluster(false), microtime(true) + 10);

	expect($transport->fetched)->toBe(['jobs', 'nodes', 'partitions']);
});

it('marks a failed section failed with no data while the others complete (REQ-BACKEND-003)', function () {
	$answers          = hpcsched_answers();
	$answers['nodes'] = ErrorClass::Timeout;
	$snapshot         = (new SlurmBackend(new StubTransport($answers), new FixedClock(5000)))->collect(hpcsched_cluster(), microtime(true) + 10);

	expect($snapshot->outcome(Section::Nodes)->state)->toBe(SectionState::Failed)
		->and($snapshot->outcome(Section::Nodes)->error)->toBe(ErrorClass::Timeout)
		->and($snapshot->nodes)->toBeNull()
		->and($snapshot->outcome(Section::Jobs)->state)->toBe(SectionState::Completed)
		->and($snapshot->partitions)->not->toBeNull();
});

it('fails a section whose answer does not parse, never reading it as empty', function () {
	$answers         = hpcsched_answers();
	$answers['jobs'] = '{"jobs": [';
	$snapshot        = (new SlurmBackend(new StubTransport($answers), new FixedClock(5000)))->collect(hpcsched_cluster(false), microtime(true) + 10);

	expect($snapshot->outcome(Section::Jobs)->error)->toBe(ErrorClass::Malformed)
		->and($snapshot->jobs)->toBeNull();
});

it('stops after a rate limit or a refused credential instead of adding load', function (ErrorClass $error) {
	$answers         = hpcsched_answers();
	$answers['jobs'] = $error;
	$transport       = new StubTransport($answers);
	$snapshot        = (new SlurmBackend($transport, new FixedClock(5000)))->collect(hpcsched_cluster(), microtime(true) + 10);

	expect($transport->fetched)->toBe(['jobs'])
		->and($snapshot->outcome(Section::Nodes)->state)->toBe(SectionState::NotAttempted);
})->with([ErrorClass::RateLimited, ErrorClass::Credential]);

it('attempts no section after the deadline', function () {
	$transport = new StubTransport(hpcsched_answers(), 0.3);
	$snapshot  = (new SlurmBackend($transport, new FixedClock(5000)))->collect(hpcsched_cluster(), microtime(true) + 0.5);

	expect($transport->fetched)->toBe(['jobs', 'nodes'])
		->and($snapshot->outcome(Section::Partitions)->state)->toBe(SectionState::NotAttempted)
		->and($snapshot->outcome(Section::Shares)->state)->toBe(SectionState::NotAttempted);
});

it('exposes no operation that changes the scheduler (REQ-BACKEND-005)', function () {
	$methods = array_map(fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(\Somethingwithproof\Hpcsched\Backend::class))->getMethods());

	expect($methods)->toBe(['name', 'collect']);
});
