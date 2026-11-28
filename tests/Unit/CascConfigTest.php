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
use Somethingwithproof\Hpcsched\ConfigCodec;
use Somethingwithproof\Hpcsched\ConfigException;
use Somethingwithproof\Hpcsched\ConfigRepository;
use Somethingwithproof\Hpcsched\ConfigService;
use Somethingwithproof\Hpcsched\FixedClock;
use Somethingwithproof\Hpcsched\SettingsRepository;

function hpcsched_seed_casc_db(): void {
	$now = 1800000000;

	$GLOBALS['__hpcsched_db_exec']  = [];
	$GLOBALS['__hpcsched_db_reads'] = [];
	$GLOBALS['__hpcsched_db_rows']  = [
		'FROM settings WHERE name IN'  => [
			['name' => 'hpcsched_data_parser', 'value' => 'v0.0.44'],
			['name' => 'hpcsched_call_timeout', 'value' => '20'],
		],
		'FROM plugin_hpcsched_cluster' => [[
			'id'                   => 1,
			'name'                 => 'prod-slurm',
			'poller_id'            => 1,
			'host_id'              => 42,
			'admin_state'          => 'enabled',
			'transport'            => 'rest',
			'collect_interval'     => 60,
			'slurm_conf'           => '',
			'rest_url'             => 'https://slurm.example.org:6820',
			'rest_user'            => 'cacti',
			'token_file'           => '/etc/cacti/hpcsched/prod.jwt',
			'collect_reservations' => 1,
			'collect_shares'       => 1,
			'created_at'           => $now - 3600,
			'updated_at'           => $now - 60,
		]],
	];
}

function hpcsched_casc_service(): ConfigService {
	$clock = new FixedClock(1800000000);

	return new ConfigService(
		new SettingsRepository(false),
		new ClusterRepository(false, $clock),
		new ConfigRepository(false),
	);
}

it('exports YAML and XML with token_file redacted and round-trips cleanly', function () {
	hpcsched_seed_casc_db();
	$service = hpcsched_casc_service();

	$yaml = $service->export(ConfigCodec::YAML);
	$xml  = $service->export(ConfigCodec::XML);

	expect($yaml)->not->toContain('/etc/cacti/hpcsched/prod.jwt')
		->and($yaml)->toContain(ConfigService::REDACTED)
		->and($xml)->not->toContain('/etc/cacti/hpcsched/prod.jwt')
		->and($xml)->toContain(ConfigService::REDACTED);

	$fromYaml = ConfigCodec::parse(ConfigCodec::YAML, $yaml);
	$fromXml  = ConfigCodec::parse(ConfigCodec::XML, $xml);

	expect($service->validate($fromYaml))->toBe([])
		->and($service->validate($fromXml))->toBe([])
		->and($service->plan($fromYaml)->isEmpty())->toBeTrue()
		->and($service->plan($fromXml)->isEmpty())->toBeTrue();
});

it('rejects XML with DOCTYPE/XXE declarations and inline credentials in YAML', function () {
	hpcsched_seed_casc_db();
	$service = hpcsched_casc_service();

	$xxe = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><hpcsched schema_version="1"/>';

	expect(fn () => ConfigCodec::parse(ConfigCodec::XML, $xxe))->toThrow(ConfigException::class);

	$badYaml = "schema_version: 1\nclusters:\n  - name: evil\n    transport: rest\n    rest_url: https://slurm.example.org:6820\n    jwt: secret-value\n";
	$errors  = $service->validate(ConfigCodec::parse(ConfigCodec::YAML, $badYaml));

	expect(implode("\n", $errors))->toContain('inline credentials are forbidden');
});

it('computes a deterministic plan hash and gates apply behind confirmation hash matching', function () {
	hpcsched_seed_casc_db();
	$service = hpcsched_casc_service();

	$yaml = "schema_version: 1\nsettings:\n  hpcsched_call_timeout: 45\nclusters:\n  - name: prod-slurm\n    poller_id: 2\n    admin_state: enabled\n    transport: rest\n    collect_interval: 120\n    rest_url: https://slurm.example.org:6820\n    rest_user: cacti\n    token_file: \"[REDACTED]\"\n    collect_reservations: true\n    collect_shares: true\n";
	$tree = ConfigCodec::parse(ConfigCodec::YAML, $yaml);

	$plan1 = $service->plan($tree);
	$plan2 = $service->plan($tree);

	expect($plan1->hasErrors())->toBeFalse()
		->and($plan1->isEmpty())->toBeFalse()
		->and($plan1->id())->toBe($plan2->id())
		->and(strlen($plan1->id()))->toBe(16);

	$wrong = $service->apply($tree, 'deadbeefdeadbeef');
	expect($wrong)->not->toBeEmpty()
		->and($GLOBALS['__hpcsched_db_exec'])->toBe([]);

	$ok = $service->apply($tree, $plan1->id());
	expect($ok)->toBe([])
		->and($GLOBALS['__hpcsched_db_exec'])->not->toBeEmpty();
});
