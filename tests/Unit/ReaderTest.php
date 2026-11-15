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

require_once dirname(__DIR__) . '/Support/Scratch.php';
require_once dirname(__DIR__) . '/Support/StubHttp.php';

use Somethingwithproof\Hpcsched\AdminState;
use Somethingwithproof\Hpcsched\Cluster;
use Somethingwithproof\Hpcsched\ClusterRepository;
use Somethingwithproof\Hpcsched\CommandRunner;
use Somethingwithproof\Hpcsched\ErrorClass;
use Somethingwithproof\Hpcsched\FixedClock;
use Somethingwithproof\Hpcsched\HttpResponse;
use Somethingwithproof\Hpcsched\Reader;
use Somethingwithproof\Hpcsched\RunOutcome;
use Somethingwithproof\Hpcsched\RunRepository;
use Somethingwithproof\Hpcsched\Scheduler;
use Somethingwithproof\Hpcsched\Settings;
use Somethingwithproof\Hpcsched\SnapshotRepository;
use Somethingwithproof\Hpcsched\Tests\Scratch;
use Somethingwithproof\Hpcsched\Tests\StubHttp;
use Somethingwithproof\Hpcsched\Transport;
use Somethingwithproof\Hpcsched\Transports;

/*
 * The reader against the unit database stubs: statements are recorded, the
 * lease answers from $GLOBALS['__hpcsched_db_rows'].
 */

const HPCSCHED_LEASE = 'FROM plugin_hpcsched_run WHERE cluster_id = ? AND holder';

beforeEach(function () {
	$this->scratch = new Scratch();
	$GLOBALS['__hpcsched_log']     = [];
	$GLOBALS['__hpcsched_db_exec'] = [];
	$GLOBALS['__hpcsched_db_rows'] = [HPCSCHED_LEASE => [['c' => 1]]];
	$GLOBALS['__hpcsched_in_tx']   = [];
});

afterEach(function () {
	$this->scratch->remove();
	$GLOBALS['__hpcsched_db_rows'] = [];
	$GLOBALS['__hpcsched_in_tx']   = [];
});

function hpcsched_rest_cluster(Scratch $scratch): Cluster {
	return new Cluster(1, 'demo', 1, AdminState::Enabled, Transport::Rest, 60, restUrl: 'https://slurm.example.org', tokenFile: $scratch->file('token', 'opaque', 0600), reservations: true, shares: true);
}

/**
 * @param array<string, HttpResponse> $responses
 */
function hpcsched_reader_for(array $responses, FixedClock $clock): Reader {
	return new Reader(new RunRepository(false, $clock), new SnapshotRepository(false), new Transports(Settings::fromRow([]), new CommandRunner(), new StubHttp($responses)), $clock, 'test');
}

/**
 * @return array<string, HttpResponse>
 */
function hpcsched_fixture_responses(): array {
	$out = [];

	foreach (['jobs' => '/jobs/', 'nodes' => '/nodes/', 'partitions' => '/partitions/', 'reservations' => '/reservations/', 'shares' => '/shares'] as $section => $suffix) {
		$out[$suffix] = new HttpResponse(200, [], (string) file_get_contents(dirname(__DIR__) . "/fixtures/slurm/26.05/$section.json"));
	}

	return $out;
}

/**
 * @return list<string>
 */
function hpcsched_sql(): array {
	return array_map(fn (array $s): string => (string) preg_replace('/\s+/', ' ', $s['sql']), $GLOBALS['__hpcsched_db_exec']);
}

it('writes a whole snapshot in one transaction and records the run', function () {
	$clock = new FixedClock(100000);
	$run   = hpcsched_reader_for(hpcsched_fixture_responses(), $clock)->run(hpcsched_rest_cluster($this->scratch), 60);
	$sql   = hpcsched_sql();
	$begin = array_search('BEGIN', $sql, true);

	expect($run->outcome)->toBe(RunOutcome::Completed)
		->and($run->release)->toBe('26.05.0')
		->and($run->calls)->toBe(5)
		->and($run->lastSuccess)->toBe(100000)
		->and(array_count_values($sql)['BEGIN'])->toBe(1)
		->and(array_count_values($sql)['COMMIT'])->toBe(1)
		->and(in_array('ROLLBACK', $sql, true))->toBeFalse()
		->and(array_search('COMMIT', $sql, true))->toBeGreaterThan($begin);

	$counts = [];

	foreach ($GLOBALS['__hpcsched_db_exec'] as $statement) {
		if (str_starts_with($statement['sql'], 'INSERT INTO plugin_hpcsched_job_count')) {
			foreach (array_chunk($statement['params'], 8) as $row) {
				$counts[$row[1] . '/' . $row[2] . '/' . $row[3]] = $row[4];
			}
		}
	}

	expect($counts['/state/pending'])->toBe(63)
		->and($counts['/state/held'])->toBe(1)
		->and($counts['/family/assoc'])->toBe(50)
		->and($counts['batch/state/pending'])->toBe(62);

	// observed, ingested, and interval on every cached row (REQ-STATE-004).
	foreach ($GLOBALS['__hpcsched_db_exec'] as $statement) {
		if (preg_match('/^INSERT INTO plugin_hpcsched_(job_count|node|partition|reservation|share) /', $statement['sql']) === 1) {
			expect($statement['sql'])->toContain('observed_at, ingested_at, collect_interval)')
				->and(array_slice($statement['params'], -3))->toBe([100000, 100000, 60]);
		}
	}
});

it('keeps a failed section\'s previous rows and marks it failed', function () {
	$responses            = hpcsched_fixture_responses();
	$responses['/nodes/'] = new HttpResponse(500, [], '');
	$run                  = hpcsched_reader_for($responses, new FixedClock(100000))->run(hpcsched_rest_cluster($this->scratch), 60);
	$sql                  = hpcsched_sql();

	expect($run->outcome)->toBe(RunOutcome::Partial)
		->and($run->error)->toBe(ErrorClass::Failed)
		->and($run->lastSuccess)->toBe(0)
		->and($run->lastFailure)->toBe(100000)
		->and(in_array('DELETE FROM plugin_hpcsched_node WHERE cluster_id = ?', $sql, true))->toBeFalse()
		->and(in_array('DELETE FROM plugin_hpcsched_job_count WHERE cluster_id = ?', $sql, true))->toBeTrue();

	$failed = array_values(array_filter($GLOBALS['__hpcsched_db_exec'], fn (array $s): bool => str_starts_with($s['sql'], 'INSERT INTO plugin_hpcsched_section') && $s['params'][1] === 'nodes'));

	expect($failed[0]['params'])->toBe([1, 'nodes', 'failed', 'failed', 100000])
		->and($failed[0]['sql'])->not->toContain('observed_at');
});

it('writes nothing when the transaction ends part way, leaving the previous snapshot (REQ-READ-003)', function () {
	$GLOBALS['__hpcsched_in_tx'] = [1, 1, 0];

	$run = hpcsched_reader_for(hpcsched_fixture_responses(), new FixedClock(100000))->run(hpcsched_rest_cluster($this->scratch), 60);
	$sql = hpcsched_sql();

	expect(in_array('COMMIT', $sql, true))->toBeFalse()
		->and($run->outcome)->toBe(RunOutcome::Failed)
		->and($run->error)->toBe(ErrorClass::Failed);
});

it('refuses a run while another holds the lease', function () {
	$GLOBALS['__hpcsched_db_rows'] = [HPCSCHED_LEASE => [['c' => 0]]];

	hpcsched_reader_for(hpcsched_fixture_responses(), new FixedClock(100000))->run(hpcsched_rest_cluster($this->scratch), 60);

	expect(in_array('BEGIN', hpcsched_sql(), true))->toBeFalse();
});

it('backs off after a rate limit, doubling up to ten minutes', function () {
	$responses = ['/jobs/' => new HttpResponse(429, [], '')];
	$run       = hpcsched_reader_for($responses, new FixedClock(100000))->run(hpcsched_rest_cluster($this->scratch), 60);

	expect($run->backoffStep)->toBe(1)
		->and($run->backoffUntil)->toBe(100060)
		->and($run->outcome)->toBe(RunOutcome::Failed);

	$GLOBALS['__hpcsched_db_rows']['SELECT * FROM plugin_hpcsched_run WHERE cluster_id'] = [['cluster_id' => 1, 'backoff_step' => 9]];
	$run = hpcsched_reader_for($responses, new FixedClock(200000))->run(hpcsched_rest_cluster($this->scratch), 60);

	expect($run->backoffStep)->toBe(10)
		->and($run->backoffUntil)->toBe(200000 + Reader::BACKOFF_MAX);
});

it('records a refused token file and contacts nobody', function () {
	$cluster = new Cluster(1, 'demo', 1, AdminState::Enabled, Transport::Rest, 60, restUrl: 'https://slurm.example.org', tokenFile: $this->scratch->file('token', 'x', 0666));
	$http    = new StubHttp([]);
	$clock   = new FixedClock(100000);
	$run     = (new Reader(new RunRepository(false, $clock), new SnapshotRepository(false), new Transports(Settings::fromRow([]), new CommandRunner(), $http), $clock, 'test'))->run($cluster, 60);

	expect($http->requests)->toBe([])
		->and($run->error)->toBe(ErrorClass::Credential)
		->and($run->tokenState->value)->toBe('refused');
});

it('schedules only enabled clusters of its own poller that are due (REQ-READ-002)', function () {
	$clock = new FixedClock(100000);

	$GLOBALS['__hpcsched_db_rows'] = [
		'FROM plugin_hpcsched_cluster' => [
			['id' => 1, 'name' => 'a', 'poller_id' => 1, 'admin_state' => 'enabled', 'transport' => 'commands', 'collect_interval' => 60],
			['id' => 2, 'name' => 'b', 'poller_id' => 2, 'admin_state' => 'enabled', 'transport' => 'commands', 'collect_interval' => 60],
			['id' => 3, 'name' => 'c', 'poller_id' => 1, 'admin_state' => 'disabled', 'transport' => 'commands', 'collect_interval' => 60],
			['id' => 4, 'name' => 'd', 'poller_id' => 1, 'admin_state' => 'enabled', 'transport' => 'commands', 'collect_interval' => 600],
			['id' => 5, 'name' => 'e', 'poller_id' => 1, 'admin_state' => 'enabled', 'transport' => 'commands', 'collect_interval' => 60],
			['id' => 6, 'name' => 'f', 'poller_id' => 1, 'admin_state' => 'enabled', 'transport' => 'commands', 'collect_interval' => 60],
		],
		'FROM plugin_hpcsched_run ORDER BY' => [
			['cluster_id' => 4, 'started_at' => 100000 - 300],
			['cluster_id' => 5, 'started_at' => 100000 - 60, 'backoff_until' => 100100],
			['cluster_id' => 6, 'started_at' => 100000 - 30, 'lease_expires' => 100050],
		],
	];

	expect((new Scheduler(new ClusterRepository(false, $clock), new RunRepository(false, $clock), $clock))->due(1, 60))->toBe([1])
		->and(Scheduler::dueAfter(60))->toBe(54)
		->and(Scheduler::dueAfter(600))->toBe(570);
});

it('collects a 10,000 node, 100,000 job snapshot within the time budget (REQ-READ-006)', function () {
	$jobs = [];

	for ($i = 0; $i < 100000; $i++) {
		$jobs[] = match ($i % 4) {
			0       => ['job_state' => ['RUNNING'], 'state_reason' => 'None', 'partition' => 'p' . ($i % 8)],
			1       => ['job_state' => ['PENDING'], 'state_reason' => 'Priority', 'partition' => 'p' . ($i % 8) . ',p' . (($i + 1) % 8)],
			2       => ['job_state' => ['PENDING'], 'state_reason' => 'Resources', 'partition' => 'p' . ($i % 8), 'array_task_string' => '0-9'],
			default => ['job_state' => ['PENDING'], 'state_reason' => 'JobHeldUser', 'partition' => 'p' . ($i % 8)],
		};
	}

	$nodes = [];

	for ($i = 0; $i < 10000; $i++) {
		$nodes[] = ['name' => sprintf('n%05d', $i), 'hostname' => sprintf('n%05d', $i), 'address' => '10.1.' . intdiv($i, 250) . '.' . ($i % 250), 'state' => $i % 50 === 0 ? ['DOWN', 'DRAIN'] : ['MIXED'], 'reason' => '', 'partitions' => ['p' . ($i % 8)], 'cpus' => 128, 'alloc_cpus' => 64];
	}

	$partitions = array_map(fn (int $p): array => ['name' => "p$p", 'partition' => ['state' => ['UP']], 'nodes' => ['total' => 1250], 'cpus' => ['total' => 160000]], range(0, 7));
	$meta       = ['meta' => ['slurm' => ['release' => '25.11.2']], 'errors' => []];
	$responses  = [
		'/jobs/'       => new HttpResponse(200, [], (string) json_encode(['jobs' => $jobs] + $meta)),
		'/nodes/'      => new HttpResponse(200, [], (string) json_encode(['nodes' => $nodes] + $meta)),
		'/partitions/' => new HttpResponse(200, [], (string) json_encode(['partitions' => $partitions] + $meta)),
	];
	unset($jobs, $nodes);

	$cluster = new Cluster(1, 'big', 1, AdminState::Enabled, Transport::Rest, 60, restUrl: 'https://slurm.example.org', tokenFile: $this->scratch->file('token', 'opaque', 0600));
	$run     = hpcsched_reader_for($responses, new FixedClock(100000))->run($cluster, 60);

	$nodeRows = 0;

	foreach ($GLOBALS['__hpcsched_db_exec'] as $statement) {
		if (str_starts_with($statement['sql'], 'INSERT INTO plugin_hpcsched_node ')) {
			$nodeRows += intdiv(count($statement['params']), 14);
		}
	}

	expect($run->outcome)->toBe(RunOutcome::Completed)
		->and($run->calls)->toBe(3)
		->and($run->overran)->toBeFalse()
		->and($run->duration)->toBeLessThan((float) $cluster->budget(60))
		->and($nodeRows)->toBe(10000);
});
