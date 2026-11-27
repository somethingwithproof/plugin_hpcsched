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

use Somethingwithproof\Hpcsched\AbilitiesProvider;
use Somethingwithproof\Hpcsched\CascProvider;
use Somethingwithproof\Hpcsched\Cluster;
use Somethingwithproof\Hpcsched\ClusterRepository;
use Somethingwithproof\Hpcsched\ConfigCodec;
use Somethingwithproof\Hpcsched\ConfigRepository;
use Somethingwithproof\Hpcsched\ConfigService;
use Somethingwithproof\Hpcsched\DoctorProvider;
use Somethingwithproof\Hpcsched\FixedClock;
use Somethingwithproof\Hpcsched\FreshnessEvaluator;
use Somethingwithproof\Hpcsched\HealthEvaluator;
use Somethingwithproof\Hpcsched\MaintRepository;
use Somethingwithproof\Hpcsched\RunRepository;
use Somethingwithproof\Hpcsched\Schema;
use Somethingwithproof\Hpcsched\Settings;
use Somethingwithproof\Hpcsched\SettingsRepository;
use Somethingwithproof\Hpcsched\ShareRepository;
use Somethingwithproof\Hpcsched\ViewRepository;

function hpcsched_seed_triad_db(): void {
	$now = 1800000000;

	$GLOBALS['__hpcsched_db_exec']  = [];
	$GLOBALS['__hpcsched_db_reads'] = [];
	$GLOBALS['__hpcsched_db_rows']  = [
		'SELECT value FROM settings WHERE name = ?' => [['value' => (string) Schema::VERSION]],
		'FROM settings WHERE name IN'               => [],
		'FROM plugin_hpcsched_cluster'              => [[
			'id'                   => 1,
			'name'                 => 'prod',
			'poller_id'            => 1,
			'host_id'              => 10,
			'admin_state'          => 'enabled',
			'transport'            => 'rest',
			'collect_interval'     => 60,
			'slurm_conf'           => '',
			'rest_url'             => 'https://slurm.example.org:6820',
			'rest_user'            => 'cacti',
			'token_file'           => '/etc/cacti/hpcsched/secret.jwt',
			'collect_reservations' => 1,
			'collect_shares'       => 1,
			'created_at'           => $now - 3600,
			'updated_at'           => $now - 30,
		]],
		'FROM plugin_hpcsched_run' => [[
			'cluster_id'     => 1,
			'holder'         => '',
			'lease_expires'  => 0,
			'started_at'     => $now - 20,
			'finished_at'    => $now - 18,
			'duration'       => 2.0,
			'outcome'        => 'completed',
			'error_class'    => '',
			'slurm_release'  => '25.11.2',
			'transport'      => 'rest',
			'data_parser'    => 'v0.0.44',
			'calls'          => 5,
			'last_success'   => $now - 18,
			'last_failure'   => 0,
			'backoff_until'  => 0,
			'backoff_step'   => 0,
			'overran'        => 0,
			'memory_limited' => 1,
			'token_state'    => 'ok',
			'token_expires'  => $now + 86400,
		]],
		'FROM plugin_hpcsched_section' => [[
			'cluster_id'       => 1,
			'section'          => 'jobs',
			'state'            => 'completed',
			'error_class'      => '',
			'attempted_at'     => $now - 18,
			'records'          => 14,
			'observed_at'      => $now - 18,
			'ingested_at'      => $now - 18,
			'collect_interval' => 60,
		]],
		'FROM plugin_hpcsched_job_count' => [[
			'cluster_id'       => 1,
			'partition_name'   => '',
			'kind'             => 'state',
			'name'             => 'running',
			'jobs'             => 14,
			'observed_at'      => $now - 18,
			'ingested_at'      => $now - 18,
			'collect_interval' => 60,
		]],
		'FROM plugin_hpcsched_node_count' => [[
			'cluster_id'       => 1,
			'partition_name'   => '',
			'name'             => 'idle',
			'value'            => 8,
			'observed_at'      => $now - 18,
			'ingested_at'      => $now - 18,
			'collect_interval' => 60,
		]],
		'FROM plugin_hpcsched_share' => [[
			'account'          => 'physics',
			'partition_name'   => 'batch',
			'parent'           => 'root',
			'usage_raw'        => 1200.0,
			'usage_normalized' => 0.5,
			'effective_usage'  => 0.4,
			'fairshare_factor' => 0.85,
			'fairshare_level'  => 0.9,
		]],
		'information_schema.TABLES'       => [['c' => 2]],
		'FROM plugin_hpcsched_maint_link' => [['c' => 1]],
	];
}

function hpcsched_triad_evaluator(): HealthEvaluator {
	$clock = new FixedClock(1800000000);

	return new HealthEvaluator(
		new ClusterRepository(false, $clock),
		new RunRepository(false, $clock),
		new ViewRepository(false),
		new ShareRepository(false),
		new MaintRepository(false, $clock),
		Settings::fromRow([]),
		new FreshnessEvaluator($clock),
		$clock,
		new Schema(false),
		60,
	);
}

it('registers three read-only View-realm gated abilities with strict closed schemas', function () {
	hpcsched_seed_triad_db();

	$registered = [];
	$categories = [];
	$registrar  = new class ($registered, $categories) {
		/**
		 * @param array<string, array<string, mixed>> $registered
		 * @param array<string, string>               $categories
		 */
		public function __construct(public array &$registered, public array &$categories) {
		}

		public function registerCategory(string $id, string $label): void {
			$this->categories[$id] = $label;
		}

		/**
		 * @param array<string, mixed> $spec
		 */
		public function register(string $id, array $spec): void {
			$this->registered[$id] = $spec;
		}
	};

	$provider = new AbilitiesProvider(
		hpcsched_triad_evaluator(),
		static fn (int $userId): bool => $userId === 10,
		static fn (int $userId, Cluster $cluster): bool => $userId === 10,
	);
	$provider->register($registrar);

	expect($categories)->toBe(['hpcsched' => 'HPC Scheduler'])
		->and(array_keys($registered))->toBe(['hpcsched-list-clusters', 'hpcsched-get-queue-summary', 'hpcsched-get-health']);

	foreach ($registered as $id => $spec) {
		expect(array_keys($spec))->toEqualCanonicalizing(['label', 'description', 'category', 'input_schema', 'output_schema', 'annotations', 'permission', 'execute'])
			->and($spec['annotations'])->toBe(['readOnly' => true, 'destructive' => false, 'idempotent' => true])
			->and($spec['input_schema']['additionalProperties'])->toBeFalse($id)
			->and($spec['output_schema']['additionalProperties'])->toBeFalse($id)
			->and(($spec['permission'])(10))->toBeTrue($id)
			->and(($spec['permission'])(99))->toBeFalse($id);
	}

	$clusters = ($registered['hpcsched-list-clusters']['execute'])(10, new \stdClass());
	$queues   = ($registered['hpcsched-get-queue-summary']['execute'])(10, new \stdClass());
	$health   = ($registered['hpcsched-get-health']['execute'])(10, new \stdClass());

	expect($clusters['clusters'])->toHaveCount(1)
		->and(json_encode($clusters))->not->toContain('secret.jwt')
		->and($queues['summaries'])->toHaveCount(1)
		->and($queues['summaries'][0]['shareAccounts'])->toBe(1)
		->and($health['schemaCurrent'])->toBeTrue()
		->and($health['maintAvailable'])->toBeTrue();
});

it('exposes four read-only Doctor diagnostic checks and a CasC provider descriptor', function () {
	hpcsched_seed_triad_db();

	$doctor = new DoctorProvider(hpcsched_triad_evaluator());
	$checks = $doctor->checks();

	expect(array_column($checks, 'id'))->toBe([
		'hpcsched.schema',
		'hpcsched.cluster_freshness',
		'hpcsched.token_health',
		'hpcsched.maint_sync',
	]);

	foreach ($checks as $check) {
		expect($check['category'])->toBe('hpcsched')
			->and(['pass', 'warn', 'fail'])->toContain($check['status']);
	}

	$clock = new FixedClock(1800000000);
	$casc  = (new CascProvider(new ConfigService(new SettingsRepository(false), new ClusterRepository(false, $clock), new ConfigRepository(false))))->descriptor();

	expect($casc['plugin'])->toBe('hpcsched')
		->and($casc['formats'])->toBe([ConfigCodec::YAML, ConfigCodec::XML])
		->and($casc['redacted_fields'])->toContain('token_file')
		->and(($casc['export'])(ConfigCodec::YAML))->not->toContain('secret.jwt');
});
