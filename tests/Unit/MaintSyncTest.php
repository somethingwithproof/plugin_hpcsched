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
use Somethingwithproof\Hpcsched\FixedClock;
use Somethingwithproof\Hpcsched\HostList;
use Somethingwithproof\Hpcsched\MaintRepository;
use Somethingwithproof\Hpcsched\MaintSynchronizer;
use Somethingwithproof\Hpcsched\NodeMapRepository;
use Somethingwithproof\Hpcsched\NodeMatcher;
use Somethingwithproof\Hpcsched\NodeMatchState;
use Somethingwithproof\Hpcsched\ReplicatorRepository;
use Somethingwithproof\Hpcsched\ShareRecord;
use Somethingwithproof\Hpcsched\ShareSummary;

it('matches every node-matching fixture case identically to the specification', function () {
	$raw     = file_get_contents(dirname(__DIR__) . '/node-matching/fixtures.json');
	$decoded = is_string($raw) ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : [];
	$matcher = new NodeMatcher();

	expect($decoded['cases'])->not->toBeEmpty();

	foreach ($decoded['cases'] as $case) {
		$got = $matcher->match($case['nodes'], $case['devices']);

		foreach ($case['expect'] as $nodeName => $want) {
			expect($got)->toHaveKey($nodeName);
			$match = $got[$nodeName];

			expect($match->state->value)->toBe($want['state'], $case['id'] . ':' . $nodeName);

			if (isset($want['device'])) {
				expect($match->hostId)->toBe($want['device'], $case['id'] . ':' . $nodeName);
			} else {
				expect($match->hostId)->toBeNull($case['id'] . ':' . $nodeName);
			}

			if (isset($want['tier'])) {
				expect($match->tier)->toBe($want['tier'], $case['id'] . ':' . $nodeName);
			} else {
				expect($match->tier)->toBeNull($case['id'] . ':' . $nodeName);
			}
		}
	}
});

it('expands bounded Slurm hostlist expressions safely', function () {
	expect(HostList::expand(''))->toBe([])
		->and(HostList::expand('n01,n02'))->toBe(['n01', 'n02'])
		->and(HostList::expand('c[01-03,07]'))->toBe(['c01', 'c02', 'c03', 'c07'])
		->and(HostList::expand('gpu[1-2]-ib[0-1]'))->toBe(['gpu1-ib0', 'gpu1-ib1', 'gpu2-ib0', 'gpu2-ib1'])
		->and(HostList::expand('huge[00001-99999]', 5))->toBe([]);
});

it('plans create, update, and removal of hpcsched-managed Maint windows without touching unowned schedules', function () {
	$now = 1800000000;

	$GLOBALS['__hpcsched_db_exec'] = [];
	$GLOBALS['__hpcsched_db_rows'] = [
		'information_schema.TABLES' => [['c' => 2]],
		'FROM host'                 => [
			['id' => 11, 'hostname' => 'n01.example.org', 'description' => 'compute 1'],
			['id' => 12, 'hostname' => 'n02.example.org', 'description' => 'compute 2'],
			['id' => 13, 'hostname' => 'n03.example.org', 'description' => 'compute 3'],
			['id' => 14, 'hostname' => 'n04.example.org', 'description' => 'compute 4'],
		],
		'FROM plugin_hpcsched_cluster' => [[
			'id'                   => 1,
			'name'                 => 'prod',
			'poller_id'            => 1,
			'host_id'              => 100,
			'admin_state'          => 'enabled',
			'transport'            => 'commands',
			'collect_interval'     => 60,
			'slurm_conf'           => '',
			'rest_url'             => '',
			'rest_user'            => '',
			'token_file'           => '',
			'collect_reservations' => 1,
			'collect_shares'       => 1,
			'created_at'           => $now - 3600,
			'updated_at'           => $now - 3600,
		]],
		'FROM plugin_hpcsched_node_map' => [
			['node_name' => 'n01', 'host_id' => 11, 'match_state' => NodeMatchState::Confirmed->value, 'match_tier' => 1, 'source' => 'manual'],
		],
		'FROM plugin_hpcsched_node ' => [
			['name' => 'n01', 'hostname' => 'n01', 'address' => '10.0.0.1', 'state' => 'drained', 'raw_state' => 'IDLE+DRAIN', 'reason' => 'firmware'],
			['name' => 'n02', 'hostname' => 'n02', 'address' => '10.0.0.2', 'state' => 'idle', 'raw_state' => 'IDLE', 'reason' => ''],
			['name' => 'n03', 'hostname' => 'n03', 'address' => '10.0.0.3', 'state' => 'idle', 'raw_state' => 'IDLE', 'reason' => ''],
			['name' => 'n04', 'hostname' => 'n04', 'address' => '10.0.0.4', 'state' => 'down', 'raw_state' => 'DOWN', 'reason' => 'bad dimm'],
		],
		'FROM plugin_hpcsched_reservation' => [[
			'name'       => 'fw-upgrade',
			'start_time' => $now - 60,
			'end_time'   => $now + 7200,
			'node_list'  => 'n[03-03]',
			'flags'      => 'MAINT,SPEC_NODES',
		]],
		'FROM plugin_hpcsched_maint_link' => [
			// n02 returned IDLE -> should be removed cleanly.
			['node_name' => 'n02', 'host_id' => 12, 'schedule_id' => 502, 'reason_kind' => 'drain', 'source_ref' => 'slurm:drain', 'start_time' => $now - 600, 'end_time' => $now + 3000],
			// n04 is still DOWN, but its window is nearing expiry -> should be updated.
			['node_name' => 'n04', 'host_id' => 14, 'schedule_id' => 504, 'reason_kind' => 'down', 'source_ref' => 'slurm:down', 'start_time' => $now - 3500, 'end_time' => $now + 100],
		],
	];

	$sync = new MaintSynchronizer(
		new ClusterRepository(false, new FixedClock($now)),
		new NodeMapRepository(false, new FixedClock($now)),
		new MaintRepository(false, new FixedClock($now)),
		new NodeMatcher(),
		new FixedClock($now),
	);

	$plan = $sync->plan();
	$byNode = [];

	foreach ($plan->actions as $action) {
		$byNode[$action->nodeName] = $action;
	}

	expect($plan->maintAvailable)->toBeTrue()
		->and($plan->warnings)->toBe([])
		->and(array_keys($byNode))->toEqualCanonicalizing(['n01', 'n02', 'n03', 'n04'])
		->and($byNode['n01']->operation)->toBe('create')
		->and($byNode['n01']->reasonKind)->toBe('drain')
		->and($byNode['n01']->hostId)->toBe(11)
		->and($byNode['n02']->operation)->toBe('remove')
		->and($byNode['n02']->scheduleId)->toBe(502)
		->and($byNode['n03']->operation)->toBe('create')
		->and($byNode['n03']->reasonKind)->toBe('reservation')
		->and($byNode['n03']->sourceRef)->toBe('fw-upgrade')
		->and($byNode['n04']->operation)->toBe('update')
		->and($byNode['n04']->scheduleId)->toBe(504);

	$result = $sync->apply();
	$sqls   = array_column($GLOBALS['__hpcsched_db_exec'], 'sql');

	expect($result['applied'])->toBe(4)
		->and($result['errors'])->toBe([])
		->and(implode("\n", $sqls))->toContain('INSERT INTO plugin_maint_schedules')
		->and(implode("\n", $sqls))->toContain('UPDATE plugin_maint_schedules')
		->and(implode("\n", $sqls))->toContain('DELETE FROM plugin_maint_schedules');
});

it('refuses Maint sync apply cleanly when Cacti Maint plugin tables are absent', function () {
	$now = 1800000000;

	$GLOBALS['__hpcsched_db_exec'] = [];
	$GLOBALS['__hpcsched_db_rows'] = [
		'information_schema.TABLES'    => [['c' => 0]],
		'FROM host'                    => [],
		'FROM plugin_hpcsched_cluster' => [],
	];

	$sync = new MaintSynchronizer(
		new ClusterRepository(false, new FixedClock($now)),
		new NodeMapRepository(false, new FixedClock($now)),
		new MaintRepository(false, new FixedClock($now)),
		new NodeMatcher(),
		new FixedClock($now),
	);

	$applied = $sync->apply();

	expect($applied['applied'])->toBe(0)
		->and($applied['errors'])->not->toBeEmpty()
		->and($GLOBALS['__hpcsched_db_exec'])->toBe([]);
});

it('aggregates account-level fair-share summaries without exposing user rows', function () {
	$summary = ShareSummary::fromRecords(1, [
		new ShareRecord('physics', 'batch', 'root', 2500.0, 0.5, 0.45, 0.72, 0.8),
		new ShareRecord('chem', 'gpu', 'root', 1000.0, 0.25, 0.20, 0.91, 0.9),
		new ShareRecord('bio', 'batch', 'root', 500.0, 0.25, 0.10, 0.50, 0.6),
	], 2);

	expect($summary->accountCount)->toBe(3)
		->and($summary->partitionCount)->toBe(2)
		->and($summary->totalRawUsage)->toBe(4000.0)
		->and($summary->totalEffectiveUsage)->toBe(0.75)
		->and($summary->minFairshareFactor)->toBe(0.5)
		->and($summary->maxFairshareFactor)->toBe(0.91)
		->and(count($summary->topAccounts))->toBe(2)
		->and($summary->topAccounts[0]['account'])->toBe('physics')
		->and($summary->topAccounts[1]['account'])->toBe('chem');
});

it('replicates only remote-poller cluster metadata and strips token_file references', function () {
	$GLOBALS['__hpcsched_db_reads'] = [];
	$GLOBALS['__hpcsched_db_rows']  = [
		'FROM plugin_hpcsched_cluster WHERE poller_id = ?' => [[
			'id'         => 2,
			'name'       => 'remote-hpc',
			'poller_id'  => 2,
			'token_file' => '',
		]],
	];

	$repo = new ReplicatorRepository(false);

	expect($repo->clustersForPoller(1))->toBe([]);

	$rows = $repo->clustersForPoller(2);

	expect($rows)->toHaveCount(1)
		->and($rows[0]['token_file'])->toBe('')
		->and($GLOBALS['__hpcsched_db_reads'][0])->toContain("'' AS token_file");
});
