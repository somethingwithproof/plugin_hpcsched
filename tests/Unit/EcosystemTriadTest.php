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
		expect($check)->toHaveKeys(['id', 'plugin', 'category', 'title', 'status', 'message', 'details', 'remediation'])
			->and($check['plugin'])->toBe('hpcsched')
			->and($check['category'])->toBe('hpcsched')
			->and(['ok', 'warn', 'fail', 'info'])->toContain($check['status'])
			->and($check['message'])->not->toBe('');
	}

	expect($checks[0]['status'])->toBe('ok');

	$hookChecks = hpcsched_doctor_register_checks(['mode' => 'run', 'plugin' => 'hpcsched']);
	expect(array_is_list($hookChecks))->toBeTrue()
		->and(array_key_exists('mode', $hookChecks))->toBeFalse()
		->and(array_column($hookChecks, 'id'))->toBe([
			'hpcsched.schema',
			'hpcsched.cluster_freshness',
			'hpcsched.token_health',
			'hpcsched.maint_sync',
		]);

	$clock = new FixedClock(1800000000);
	$casc  = (new CascProvider(new ConfigService(new SettingsRepository(false), new ClusterRepository(false, $clock), new ConfigRepository(false))))->descriptor();

	expect($casc['slug'])->toBe('hpcsched')
		->and($casc['schemaVersion'])->toBe('1.0')
		->and($casc['redacted_fields'])->toContain('token_file');

	$exported = ($casc['export'])([]);
	expect($exported)->toHaveKeys(['config', 'reference'])
		->and(json_encode($exported))->not->toContain('secret.jwt')
		->and(($casc['validate'])($exported['config']))->toBe([])
		->and(($casc['validate'])($exported))->toBe([])
		->and(($casc['validate'])(['schema_version' => 999]))->not->toBeEmpty()
		->and(($casc['plan'])($exported['config'], []))->toBe([]);

	$desired = $exported['config'];
	$desired['settings']['hpcsched_call_timeout'] = 45;
	expect(($casc['plan'])($desired, []))->toHaveCount(1)
		->and(($casc['plan'])(['schema_version' => 999], [])[0]['operation'])->toBe('error')
		->and(($casc['apply'])($desired, 'wrong-hash', [])['ok'])->toBeFalse();

	$cascReg = new class {
		/** @var list<array<string, mixed>> */
		public array $registered = [];

		/** @param array<string, mixed> $descriptor */
		public function register(array $descriptor): void {
			$this->registered[] = $descriptor;
		}
	};
	expect(hpcsched_casc_register_providers($cascReg))->toBe($cascReg)
		->and($cascReg->registered)->toHaveCount(1)
		->and($cascReg->registered[0]['slug'])->toBe('hpcsched')
		->and(hpcsched_casc_register_providers(null)['slug'])->toBe('hpcsched');
});

it('registers decoupled doctor_register_debug_info and api_register_resources hooks and ships a valid Config/State schema', function () {
	hpcsched_seed_triad_db();

	// doctor_register_debug_info returns a plain list and never echoes $context.
	$debugInfo = hpcsched_doctor_register_debug_info(['plugin' => 'hpcsched']);
	expect(array_is_list($debugInfo))->toBeTrue()
		->and(array_key_exists('plugin', $debugInfo))->toBeFalse()
		->and($debugInfo)->toHaveCount(1)
		->and($debugInfo[0]['id'])->toBe('hpcsched.overview')
		->and($debugInfo[0]['plugin'])->toBe('hpcsched')
		->and($debugInfo[0]['label'])->toBe('HPC Scheduler')
		->and(json_encode($debugInfo))->not->toContain('secret.jwt')
		->and($debugInfo[0]['fields'])->toHaveKeys([
			'schema_version',
			'health_status',
			'clusters_configured',
			'cluster_freshness',
			'maint_sync',
			'token_redaction',
		])
		->and($debugInfo[0]['fields']['token_redaction']['private'])->toBeTrue();

	// Google AIP resource descriptors (canonical lowerCamelCase collections, realm_read, handlers).
	$resources = hpcsched_api_register_resources([]);
	expect(array_is_list($resources))->toBeTrue()
		->and(array_column($resources, 'collection'))->toBe(['hpcschedClusters']);

	foreach ($resources as $descriptor) {
		expect($descriptor['plugin'])->toBe('hpcsched')
			->and($descriptor['collection'])->toMatch('/^[a-z][A-Za-z0-9]{2,63}$/')
			->and($descriptor['realm_read'])->toBe('hpcsched.php')
			->and($descriptor['read_only'])->toBeTrue()
			->and(array_keys($descriptor['handlers']))->toBe(['list', 'get'])
			->and(json_encode($descriptor))->not->toContain('token_file');
	}

	// Direct ApiResourceProvider handler execution (permitted and denied users).
	$apiProvider = new Somethingwithproof\Hpcsched\ApiResourceProvider(
		hpcsched_triad_evaluator(),
		static fn (int $u): bool => $u === 10,
	);
	$directResources = $apiProvider->resources();
	$list            = ($directResources[0]['handlers']['list'])(['user_id' => 10]);

	expect($list)->toHaveCount(1)
		->and($list[0]['name'])->toBe('hpcschedClusters/1')
		->and($list[0]['display_name'])->toBe('prod')
		->and(json_encode($list))->not->toContain('secret.jwt')
		->and(($directResources[0]['handlers']['get'])('hpcschedClusters/1', ['user_id' => 10])['display_name'])->toBe('prod')
		->and(($directResources[0]['handlers']['get'])('999', ['user_id' => 10]))->toBeNull()
		->and(($directResources[0]['handlers']['get'])('bad', ['user_id' => 10]))->toBeNull()
		->and(($directResources[0]['handlers']['list'])(['user_id' => 99]))->toBe([])
		->and(($directResources[0]['handlers']['get'])('1', ['user_id' => 99]))->toBeNull();

	$mergedApi = hpcsched_api_register_resources([['plugin' => 'other', 'collection' => 'otherItems']]);
	expect($mergedApi)->toHaveCount(2)
		->and($mergedApi[1]['collection'])->toBe('hpcschedClusters');

	// Static Drupal-style Config vs. State schema parses cleanly and covers all settings and tables.
	$schemaFile = dirname(__DIR__, 2) . '/schema/hpcsched.schema.yaml';
	expect(is_file($schemaFile))->toBeTrue();

	$tree = Somethingwithproof\Hpcsched\YamlReader::parse((string) file_get_contents($schemaFile))->data;
	expect($tree['plugin'])->toBe('hpcsched')
		->and($tree['storage_separation'])->toBe('strict')
		->and($tree['config']['casc_managed'])->toBeTrue()
		->and($tree['state']['casc_managed'])->toBeFalse()
		->and(array_keys($tree['config']['settings']))->toEqualCanonicalizing(Settings::NAMES);

	$configTables = $tree['config']['tables'];
	$stateTables  = $tree['state']['tables'];
	$allSchema    = array_merge($configTables, $stateTables);

	expect(array_intersect($configTables, $stateTables))->toBe([])
		->and($allSchema)->toEqualCanonicalizing(array_keys(Schema::tables()));
});
