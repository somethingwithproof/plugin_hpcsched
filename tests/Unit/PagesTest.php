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
use Somethingwithproof\Hpcsched\ClusterInput;
use Somethingwithproof\Hpcsched\ClusterRepository;
use Somethingwithproof\Hpcsched\ClustersPage;
use Somethingwithproof\Hpcsched\FixedClock;
use Somethingwithproof\Hpcsched\FreshnessEvaluator;
use Somethingwithproof\Hpcsched\HealthPage;
use Somethingwithproof\Hpcsched\OverviewPage;
use Somethingwithproof\Hpcsched\PollerRepository;
use Somethingwithproof\Hpcsched\RunRepository;
use Somethingwithproof\Hpcsched\Settings;
use Somethingwithproof\Hpcsched\Transport;
use Somethingwithproof\Hpcsched\ViewRepository;

const HPCSCHED_HOSTILE = '"><script>alert(1)</script>';

beforeEach(function () {
	$GLOBALS['__hpcsched_db_rows'] = [
		'FROM plugin_hpcsched_cluster' => [['id' => 1, 'name' => 'demo', 'poller_id' => 1, 'admin_state' => 'enabled', 'transport' => 'rest', 'collect_interval' => 60, 'rest_url' => 'https://x.example.org/' . HPCSCHED_HOSTILE, 'token_file' => '/etc/' . HPCSCHED_HOSTILE, 'updated_at' => 5]],
		'FROM plugin_hpcsched_run ORDER BY' => [['cluster_id' => 1, 'finished_at' => 99990, 'outcome' => 'completed', 'slurm_release' => HPCSCHED_HOSTILE, 'data_parser' => HPCSCHED_HOSTILE, 'token_state' => 'world_readable']],
		'FROM plugin_hpcsched_section' => [
			['cluster_id' => 1, 'section' => 'jobs', 'state' => 'completed', 'attempted_at' => 99990, 'records' => 3, 'observed_at' => 99990, 'ingested_at' => 99990, 'collect_interval' => 60],
			['cluster_id' => 1, 'section' => 'nodes', 'state' => 'failed', 'error_class' => 'timeout', 'attempted_at' => 99990, 'records' => 0, 'observed_at' => 0, 'ingested_at' => 0, 'collect_interval' => 0],
		],
		'kind = \'state\'' => [['cluster_id' => 1, 'name' => 'running', 'jobs' => 0], ['cluster_id' => 1, 'name' => 'pending', 'jobs' => 7]],
		'FROM poller' => [['id' => 1, 'name' => 'Main ' . HPCSCHED_HOSTILE]],
	];
});

afterEach(function () {
	$GLOBALS['__hpcsched_db_rows'] = [];
});

function hpcsched_render(callable $render): string {
	ob_start();
	$render();

	return (string) ob_get_clean();
}

it('escapes every stored string the health page prints, and shows the token warning', function () {
	$clock = new FixedClock(100000);
	$html  = hpcsched_render(fn () => (new HealthPage(new ClusterRepository(false, $clock), new RunRepository(false, $clock), new ViewRepository(false), Settings::fromRow([]), new FreshnessEvaluator($clock), $clock, 60))->render());

	expect($html)->not->toContain('<script>')
		->and($html)->toContain('&lt;script&gt;')
		->and($html)->toContain('readable by every user')
		->and($html)->toContain('timeout');
});

it('shows zero as zero and an unusable value as a dash on the overview', function () {
	$clock = new FixedClock(100000);
	$html  = hpcsched_render(fn () => (new OverviewPage(new ClusterRepository(false, $clock), new ViewRepository(false), new FreshnessEvaluator($clock), $clock, 60))->render());

	expect($html)->toContain('<td>demo</td><td>Stale</td>')
		->and($html)->toContain('<td>0</td><td>7</td><td>0</td><td>-</td><td>-</td>');
});

it('escapes stored strings on the cluster list and form, and never offers a token field', function () {
	$clock    = new FixedClock(100000);
	$page     = new ClustersPage(new ClusterRepository(false, $clock), new PollerRepository(false));
	$list     = hpcsched_render(fn () => $page->renderList());
	$form     = hpcsched_render(fn () => $page->renderEdit((new ClusterRepository(false, $clock))->get(1)));

	expect($list . $form)->not->toContain('<script>')
		->and($form)->toContain('name="token_file"')
		->and($form)->not->toMatch('/name="(token|password|jwt)"/')
		->and($form)->toContain('method="post"')
		->and($list)->toContain('method="post"');
});

it('exports the configuration without a token and with markup escaped', function () {
	$clock  = new FixedClock(100000);
	$export = json_encode((new ClustersPage(new ClusterRepository(false, $clock), new PollerRepository(false)))->export('0.1.0-dev', 100000), JSON_HEX_TAG);

	expect($export)->toContain('token_file')
		->and($export)->not->toContain('<script>');
});

it('validates the cluster form and keeps a deleted poller selected', function () {
	$existing = new Cluster(4, 'demo', 7, AdminState::Enabled, Transport::Commands, 60);
	$ok       = ClusterInput::parse(['name' => 'demo', 'poller_id' => '7', 'collect_interval' => '60', 'transport' => 'commands', 'enabled' => 'on'], $existing, [1 => 'Main']);
	$moved    = ClusterInput::parse(['name' => 'demo', 'poller_id' => '9', 'collect_interval' => '60', 'transport' => 'commands'], $existing, [1 => 'Main']);

	expect($ok->valid())->toBeTrue()
		->and($ok->pollerId)->toBe(7)
		->and($ok->id)->toBe(4)
		->and($moved->errors)->toHaveKey('poller_id');
});

it('rejects unsafe names, paths, URLs, and intervals', function (array $input, string $field) {
	$base = ['name' => 'demo', 'poller_id' => '1', 'collect_interval' => '60', 'transport' => 'rest', 'rest_url' => 'https://slurm.example.org', 'token_file' => '/etc/cacti/token'];

	expect(ClusterInput::parse($input + $base, null, [1 => 'Main'])->errors)->toHaveKey($field);
})->with([
	[['name' => '-rf'], 'name'],
	[['name' => 'a b'], 'name'],
	[['collect_interval' => '10'], 'collect_interval'],
	[['collect_interval' => '99999'], 'collect_interval'],
	[['transport' => 'ssh'], 'transport'],
	[['slurm_conf' => 'etc/slurm.conf'], 'slurm_conf'],
	[['slurm_conf' => '/etc/slurm.conf;id'], 'slurm_conf'],
	[['rest_url' => 'http://slurm.example.org'], 'rest_url'],
	[['rest_url' => 'https://u:p@slurm.example.org'], 'rest_url'],
	[['rest_user' => 'bad user'], 'rest_user'],
	[['token_file' => 'token'], 'token_file'],
	[['token_file' => ''], 'token_file'],
]);

it('clears REST fields when the cluster uses client commands', function () {
	$parsed = ClusterInput::parse(['name' => 'demo', 'poller_id' => '1', 'collect_interval' => '60', 'transport' => 'commands', 'rest_url' => 'http://evil.example.org', 'token_file' => 'x'], null, [1 => 'Main']);

	expect($parsed->valid())->toBeTrue()
		->and($parsed->restUrl)->toBe('')
		->and($parsed->tokenFile)->toBe('');
});
