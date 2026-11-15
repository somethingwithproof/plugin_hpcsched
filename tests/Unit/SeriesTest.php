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


use Somethingwithproof\Hpcsched\ClusterRepository;
use Somethingwithproof\Hpcsched\ClusterSeries;
use Somethingwithproof\Hpcsched\FixedClock;
use Somethingwithproof\Hpcsched\FreshnessEvaluator;
use Somethingwithproof\Hpcsched\NodeTally;
use Somethingwithproof\Hpcsched\Series;
use Somethingwithproof\Hpcsched\SeriesRepository;
use Somethingwithproof\Hpcsched\SlurmJson;
use Somethingwithproof\Hpcsched\SlurmParser;

const SERIES_T = 1800000000;

function series_fixture(string $section): array {
	return SlurmJson::decode((string) file_get_contents(dirname(__DIR__) . "/fixtures/slurm/25.11/$section.json"));
}

/**
 * The 25.11 fixture snapshot as the tables hold it, observed $age seconds ago.
 */
function series_tables(int $age = 10, string $jobsState = 'completed'): void {
	$observed = SERIES_T - $age;
	$jobs     = [];
	$nodes    = [];

	foreach (SlurmParser::jobs(series_fixture('jobs'))->rows() as $row) {
		$jobs[] = ['partition_name' => $row['partition'], 'kind' => $row['kind'], 'name' => $row['name'], 'jobs' => $row['jobs']];
	}

	foreach (NodeTally::rows(SlurmParser::nodes(series_fixture('nodes'))) as $row) {
		$nodes[] = ['partition_name' => $row['partition'], 'name' => $row['name'], 'value' => $row['value']];
	}

	$section = static fn (string $name, string $state): array => ['cluster_id' => 7, 'section' => $name, 'state' => $state, 'error_class' => '', 'attempted_at' => $observed, 'records' => 1, 'observed_at' => $observed, 'ingested_at' => $observed, 'collect_interval' => 60];

	$GLOBALS['__hpcsched_db_rows'] = [
		'FROM plugin_hpcsched_cluster WHERE host_id' => [['id' => 7, 'name' => 'c1', 'poller_id' => 1, 'host_id' => 42, 'admin_state' => 'enabled', 'transport' => 'commands', 'collect_interval' => 60]],
		'FROM plugin_hpcsched_section'               => [$section('jobs', $jobsState), $section('nodes', 'completed'), $section('partitions', 'completed')],
		'FROM plugin_hpcsched_job_count'             => $jobs,
		'FROM plugin_hpcsched_node_count'            => $nodes,
		// Stored as the parser left them; a name that cannot be an index is
		// left out of the data query.
		'FROM plugin_hpcsched_partition'             => [['name' => 'batch'], ['name' => 'gpu'], ['name' => 'bad name']],
	];
}

function series_answerer(): ClusterSeries {
	$clock = new FixedClock(SERIES_T);

	return new ClusterSeries(new ClusterRepository(false, $clock), new SeriesRepository(false), new FreshnessEvaluator($clock), $clock, 60);
}

/**
 * @return array<string, string>
 */
function series_pairs(string $answer): array {
	$out = [];

	foreach (explode(' ', $answer) as $pair) {
		[$name, $value] = explode(':', $pair, 2);
		$out[$name]     = $value;
	}

	return $out;
}

beforeEach(function () {
	$GLOBALS['__hpcsched_db_rows'] = [];
});

describe('node counts per partition (REQ-NODEAGG-001, REQ-NODEAGG-003)', function () {
	it('counts a node in several partitions once for the cluster and once per partition', function () {
		$counts = [];

		foreach (NodeTally::rows(SlurmParser::nodes(series_fixture('nodes'))) as $row) {
			$counts[$row['partition']][$row['name']] = $row['value'];
		}

		// n04 is in batch and gpu: 8 nodes in all, 4 plus 5 by partition.
		expect($counts['']['nodes_total'])->toBe(8)
			->and($counts['batch']['nodes_total'])->toBe(4)
			->and($counts['gpu']['nodes_total'])->toBe(5)
			->and(array_sum(array_intersect_key($counts[''], array_flip(NodeTally::STATES))))->toBe(8);
	});

	it('puts each normalized state in one node series, an unknown state in Other', function () {
		$counts = [];

		foreach (NodeTally::rows(SlurmParser::nodes(series_fixture('nodes'))) as $row) {
			$counts[$row['partition']][$row['name']] = $row['value'];
		}

		expect(array_intersect_key($counts[''], array_flip(NodeTally::STATES)))->toBe([
			'n_idle' => 1, 'n_allocated' => 1, 'n_down' => 1, 'n_drained' => 2, 'n_powered_down' => 1, 'n_maint' => 1, 'n_other' => 1,
		]);
	});

	it('splits CPUs the way sinfo %C does, with unavailable nodes as other', function () {
		$counts = [];

		foreach (NodeTally::rows(SlurmParser::nodes(series_fixture('nodes'))) as $row) {
			$counts[$row['partition']][$row['name']] = $row['value'];
		}

		$cpus = static fn (string $p): array => array_intersect_key($counts[$p], array_flip(NodeTally::CPUS));

		// Allocated/idle/other/total: sinfo -o %C gives 72/64/248/384.
		expect($cpus(''))->toBe(['cpus_alloc' => 72, 'cpus_idle' => 64, 'cpus_other' => 248, 'cpus_total' => 384])
			->and($cpus('batch'))->toBe(['cpus_alloc' => 72, 'cpus_idle' => 64, 'cpus_other' => 120, 'cpus_total' => 256])
			->and($cpus('gpu'))->toBe(['cpus_alloc' => 8, 'cpus_idle' => 0, 'cpus_other' => 184, 'cpus_total' => 192])
			->and($counts['']['nodes_alloc'])->toBe(2)
			->and($counts['gpu']['nodes_alloc'])->toBe(1);
	});
});

describe('series values', function () {
	it('names every value within the 19 characters of an RRD data source name, once', function () {
		foreach (array_merge(Series::names(), [Series::AGE]) as $name) {
			expect(strlen($name))->toBeLessThanOrEqual(19)->and($name)->toMatch('/^[a-z][a-z0-9_]*$/');
		}

		expect(Series::names())->toBe(array_values(array_unique(Series::names())))
			->and(count(Series::names()))->toBe(32);
	});

	it('serves U for every count of a section it cannot vouch for, never zero', function () {
		$values = Series::values(false, true, ['state:pending' => 5], ['n_idle' => 3, 'cpus_total' => 10, 'cpus_alloc' => 5]);

		expect($values['pending'])->toBeNull()
			->and($values['r_priority'])->toBeNull()
			->and($values['n_idle'])->toBe(3.0)
			->and($values['cpu_util_pct'])->toBe(50.0);

		$values = Series::values(true, false, [], []);

		expect($values['pending'])->toBe(0.0)
			->and($values['cpus_total'])->toBeNull()
			->and($values['cpu_util_pct'])->toBeNull();
	});

	it('leaves utilization unknown when nothing is behind it', function () {
		expect(Series::values(true, true, [], [])['cpu_util_pct'])->toBeNull()
			->and(Series::format(null))->toBe('U')
			->and(Series::format(18.75))->toBe('18.75')
			->and(Series::format(1e20))->not->toContain('E');
	});
});

describe('cluster and partition data inputs (REQ-OBJ-005, REQ-QUEUE-001 to REQ-QUEUE-005)', function () {
	it('answers the cluster values in one call, from the snapshot tables only', function () {
		series_tables();
		$GLOBALS['__hpcsched_db_reads'] = [];

		$pairs = series_pairs(series_answerer()->cluster(42));

		// 9 job records: two running, one completing, one held, and five
		// pending of which two are arrays with 10 and 50 tasks left.
		expect($pairs['running'])->toBe('2')
			->and($pairs['completing'])->toBe('1')
			->and($pairs['held'])->toBe('1')
			->and($pairs['pending'])->toBe('63')
			->and($pairs['r_assoc'])->toBe('50')
			->and($pairs['r_held'])->toBe('1')
			->and($pairs['cpus_alloc'])->toBe('72')
			->and($pairs['cpu_util_pct'])->toBe('18.75')
			->and($pairs['node_util_pct'])->toBe('25')
			->and($pairs['age'])->toBe('10')
			->and(count($pairs))->toBe(33);

		foreach ($GLOBALS['__hpcsched_db_reads'] as $sql) {
			expect($sql)->toMatch('/FROM plugin_hpcsched_(cluster|section|job_count|node_count|partition)\b/');
		}
	});

	it('counts a job in several partitions once per partition it names', function () {
		series_tables();
		$answer = series_answerer();

		// Job 103 is pending in batch and gpu.
		expect($answer->partitions(42, 'get', 'r_priority', 'batch'))->toBe('1')
			->and($answer->partitions(42, 'get', 'r_priority', 'gpu'))->toBe('1')
			->and(series_pairs($answer->cluster(42))['r_priority'])->toBe('1')
			->and($answer->partitions(42, 'get', 'pending', 'batch'))->toBe('62')
			->and($answer->partitions(42, 'get', 'pending', 'gpu'))->toBe('2');
	});

	it('lists partitions as indexes by name, and only names that can be indexes', function () {
		series_tables();
		$answer = series_answerer();

		expect($answer->partitions(42, 'index'))->toBe("batch\ngpu\n")
			->and($answer->partitions(42, 'num_indexes'))->toBe('2')
			->and($answer->partitions(42, 'query', 'name'))->toBe("batch!batch\ngpu!gpu\n")
			->and($answer->partitions(42, 'query', 'cpus_total'))->toBe("batch!256\ngpu!192\n");
	});

	it('answers U or nothing to a hostile field, index, command, or device', function () {
		series_tables();
		$answer = series_answerer();

		expect($answer->partitions(42, 'get', 'password', 'batch'))->toBe('U')
			->and($answer->partitions(42, 'get', 'pending', '../../etc/passwd'))->toBe('U')
			->and($answer->partitions(42, 'get', 'pending', 'bad name'))->toBe('U')
			->and($answer->partitions(42, 'query', 'password'))->toBe('')
			->and($answer->partitions(42, 'drop'))->toBe('');

		$GLOBALS['__hpcsched_db_rows'] = [];

		expect(series_answerer()->partitions(43, 'index'))->toBe('')
			->and(series_answerer()->partitions(43, 'num_indexes'))->toBe('0')
			->and(series_pairs(series_answerer()->cluster(43))['pending'])->toBe('U')
			->and(series_pairs(series_answerer()->cluster(43))['age'])->toBe('U');
	});

	it('turns counts U once the snapshot is stale, and keeps age numeric (REQ-ALERT-001, REQ-ALERT-002)', function () {
		series_tables(400);
		$pairs = series_pairs(series_answerer()->cluster(42));

		expect($pairs['pending'])->toBe('U')
			->and($pairs['n_idle'])->toBe('U')
			->and($pairs['cpu_util_pct'])->toBe('U')
			->and($pairs['age'])->toBe('400')
			->and(series_answerer()->partitions(42, 'get', 'running', 'batch'))->toBe('U');
	});

	it('keeps serving nodes when only the jobs section failed', function () {
		series_tables(10, 'failed');
		$pairs = series_pairs(series_answerer()->cluster(42));

		// A failed section keeps its rows and their observation time, so it
		// is served until it turns stale; a section never collected is U.
		expect($pairs['pending'])->toBe('63')->and($pairs['n_idle'])->toBe('1');

		$GLOBALS['__hpcsched_db_rows']['FROM plugin_hpcsched_section'] = array_slice($GLOBALS['__hpcsched_db_rows']['FROM plugin_hpcsched_section'], 1);
		$pairs = series_pairs(series_answerer()->cluster(42));

		expect($pairs['pending'])->toBe('U')->and($pairs['n_idle'])->toBe('1');
	});
});
