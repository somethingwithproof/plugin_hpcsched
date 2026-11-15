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
use Somethingwithproof\Hpcsched\CactiObjectRepository;
use Somethingwithproof\Hpcsched\DeviceAccess;
use Somethingwithproof\Hpcsched\DeviceProvisioner;
use Somethingwithproof\Hpcsched\SeriesRepository;
use Somethingwithproof\Hpcsched\TemplateInstaller;
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

/**
 * Cacti's device permission, allowing only the listed devices.
 */
function hpcsched_access(int ...$allowed): DeviceAccess {
	return new DeviceAccess(static fn (int $id): bool => in_array($id, $allowed, true));
}

function hpcsched_clusters_page(FixedClock $clock, ?DeviceAccess $access = null): ClustersPage {
	$clusters = new ClusterRepository(false, $clock);

	return new ClustersPage($clusters, new PollerRepository(false), $access ?? hpcsched_access(), new CactiObjectRepository(false), new DeviceProvisioner($clusters, new SeriesRepository(false), new CactiObjectRepository(false), '/nonexistent'));
}

function hpcsched_render(callable $render): string {
	ob_start();
	$render();

	return (string) ob_get_clean();
}

it('escapes every stored string the health page prints, and shows the token warning', function () {
	$clock = new FixedClock(100000);
	$html  = hpcsched_render(fn () => (new HealthPage(new ClusterRepository(false, $clock), new RunRepository(false, $clock), new ViewRepository(false), Settings::fromRow([]), new FreshnessEvaluator($clock), $clock, 60, hpcsched_access(), new TemplateInstaller('/nonexistent', '/nonexistent', new CactiObjectRepository(false))))->render());

	expect($html)->not->toContain('<script>')
		->and($html)->toContain('&lt;script&gt;')
		->and($html)->toContain('readable by every user')
		->and($html)->toContain('timeout');
});

it('shows zero as zero and an unusable value as a dash on the overview', function () {
	$clock = new FixedClock(100000);
	$html  = hpcsched_render(fn () => (new OverviewPage(new ClusterRepository(false, $clock), new ViewRepository(false), new FreshnessEvaluator($clock), $clock, 60, hpcsched_access()))->render());

	expect($html)->toContain('<td>demo</td><td>Stale</td>')
		->and($html)->toContain('<td>0</td><td>7</td><td>0</td><td>-</td><td>-</td>');
});

it('escapes stored strings on the cluster list and form, and never offers a token field', function () {
	$clock    = new FixedClock(100000);
	$page     = hpcsched_clusters_page($clock);
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
	$export = json_encode(hpcsched_clusters_page($clock)->export('0.1.0-dev', 100000), JSON_HEX_TAG);

	expect($export)->toContain('token_file')
		->and($export)->not->toContain('<script>');
});

describe('device permissions (REQ-SEC-001, REQ-SEC-002)', function () {
	beforeEach(function () {
		$GLOBALS['__hpcsched_db_rows']['FROM plugin_hpcsched_cluster'] = [
			['id' => 1, 'name' => 'open', 'poller_id' => 1, 'host_id' => 0, 'admin_state' => 'enabled', 'transport' => 'commands', 'collect_interval' => 60],
			['id' => 2, 'name' => 'mine', 'poller_id' => 1, 'host_id' => 20, 'admin_state' => 'enabled', 'transport' => 'commands', 'collect_interval' => 60],
			['id' => 3, 'name' => 'theirs', 'poller_id' => 1, 'host_id' => 30, 'admin_state' => 'enabled', 'transport' => 'commands', 'collect_interval' => 60],
		];
	});

	it('lets a user see an unmapped cluster and one on an allowed device, never one on a denied device', function () {
		$access   = hpcsched_access(20);
		$clusters = (new ClusterRepository(false, new FixedClock(100000)))->all();

		expect(array_map(static fn (Cluster $c): string => $c->name, $access->clusters($clusters)))->toBe(['open', 'mine'])
			->and($access->cluster(null))->toBeFalse()
			->and($access->device(0))->toBeFalse()
			->and($access->device(30))->toBeFalse();
	});

	it('leaves a cluster whose device no longer exists to administrators, who can give it a device again', function () {
		$clusters = (new ClusterRepository(false, new FixedClock(100000)))->all();
		$cluster  = $clusters[2];
		$gone     = new DeviceAccess(static fn (int $id): bool => false, static fn (int $id): bool => $id !== 30, true);
		$viewer   = new DeviceAccess(static fn (int $id): bool => false, static fn (int $id): bool => $id !== 30);
		$there    = new DeviceAccess(static fn (int $id): bool => false, static fn (int $id): bool => true, true);

		expect($gone->cluster($cluster))->toBeTrue()
			->and($gone->orphaned($cluster))->toBeTrue()
			->and($gone->unmapped($cluster))->toBeTrue()
			->and($gone->device(30))->toBeFalse()
			// No device permission is left to check, so the View realm alone does not see it.
			->and($viewer->cluster($cluster))->toBeFalse()
			->and($viewer->unmapped($cluster))->toBeFalse()
			// A device that exists but is denied keeps the cluster hidden, administrators included.
			->and($there->cluster($cluster))->toBeFalse()
			->and($there->unmapped($cluster))->toBeFalse()
			->and($gone->unmapped($clusters[0]))->toBeTrue()
			->and($gone->unmapped($clusters[1]))->toBeFalse();
	});

	it('offers Create Device and Map Device for a cluster whose device no longer exists, and says why', function () {
		$clock = new FixedClock(100000);
		$GLOBALS['__hpcsched_db_rows']['WHERE hash = ?'] = [['id' => 9]];
		$GLOBALS['__hpcsched_db_rows']['FROM plugin_hpcsched_partition'] = [];
		$GLOBALS['__hpcsched_db_rows']['FROM graph_local'] = [['n' => 0]];
		$GLOBALS['__hpcsched_db_rows']['FROM host_snmp_query'] = [['n' => 0]];
		$GLOBALS['__hpcsched_db_rows']['ORDER BY description, id'] = [['id' => 40, 'description' => 'spare']];
		$html = hpcsched_render(fn () => hpcsched_clusters_page($clock, hpcsched_access(40))->renderDevice((new ClusterRepository(false, $clock))->all()[2]));

		expect($html)->toContain('still mapped to device 30, which no longer exists')
			->and($html)->toContain('value="create_device"')
			->and($html)->toContain('value="map_device"')
			->and($html)->not->toContain('unmap_device');
	});

	it('leaves clusters on denied devices out of the list, the export, the overview, and the health page', function () {
		$clock  = new FixedClock(100000);
		$access = hpcsched_access(20);
		$list   = hpcsched_render(fn () => hpcsched_clusters_page($clock, $access)->renderList());
		$export = hpcsched_clusters_page($clock, $access)->export('0.1.0-dev', 100000);
		$view   = hpcsched_render(fn () => (new OverviewPage(new ClusterRepository(false, $clock), new ViewRepository(false), new FreshnessEvaluator($clock), $clock, 60, $access))->render());
		$health = hpcsched_render(fn () => (new HealthPage(new ClusterRepository(false, $clock), new RunRepository(false, $clock), new ViewRepository(false), Settings::fromRow([]), new FreshnessEvaluator($clock), $clock, 60, $access, new TemplateInstaller('/nonexistent', '/nonexistent', new CactiObjectRepository(false))))->render());

		foreach ([$list, $view, $health] as $html) {
			expect($html)->toContain('>mine<')->and($html)->not->toContain('theirs');
		}

		expect(array_column($export['clusters'], 'name'))->toBe(['open', 'mine']);
	});

	it('refuses to offer devices when the templates are missing, and shows what creating one adds before it runs', function () {
		$clock = new FixedClock(100000);
		$html  = hpcsched_render(fn () => hpcsched_clusters_page($clock)->renderDevice((new ClusterRepository(false, $clock))->get(1)));

		expect($html)->toContain('templates are not installed')->and($html)->not->toContain('create_device');

		// With the templates found by hash, the plan states the counts.
		$GLOBALS['__hpcsched_db_rows']['WHERE hash = ?'] = [['id' => 9]];
		$GLOBALS['__hpcsched_db_rows']['FROM plugin_hpcsched_partition'] = [['name' => 'batch'], ['name' => 'gpu']];
		$GLOBALS['__hpcsched_db_rows']['FROM graph_local'] = [['n' => 0]];
		$GLOBALS['__hpcsched_db_rows']['FROM host_snmp_query'] = [['n' => 0]];
		$html = hpcsched_render(fn () => hpcsched_clusters_page($clock)->renderDevice((new ClusterRepository(false, $clock))->get(1)));

		// 6 cluster graphs plus 5 per partition; 1 call per cluster data
		// source and 32 per partition.
		expect($html)->toContain('In all: 16 data sources and 16 RRD files, and 70 script server calls per poll')
			->and($html)->toContain('indexing the 2 partitions known now')
			->and($html)->toContain('name="action" value="create_device"')
			->and($html)->toContain('method="post"');
	});
});

describe('pending reasons view (REQ-REASON-003 to REQ-REASON-006, REQ-NODEAGG-002)', function () {
	beforeEach(function () {
		$GLOBALS['__hpcsched_db_rows']["kind = 'reason' AND partition_name = ''"] = [['name' => 'Priority', 'jobs' => 3], ['name' => 'SomethingNew', 'jobs' => 2], ['name' => HPCSCHED_HOSTILE, 'jobs' => 1]];
		$GLOBALS['__hpcsched_db_rows']["partition_name <> ''"] = [['name' => 'Priority', 'partition_name' => 'batch'], ['name' => 'Priority', 'partition_name' => 'gpu']];
		$GLOBALS['__hpcsched_db_rows']['GROUP BY raw_state'] = [['raw_state' => 'FUTURE_STATE_X', 'nodes' => 1]];
		$GLOBALS['__hpcsched_db_rows']['SELECT name, raw_state'] = [['name' => 'g04', 'raw_state' => 'FUTURE_STATE_X']];
	});

	it('lists raw reasons with families, counts, and partitions, unknown ones as Other, escaped', function () {
		$clock = new FixedClock(100000);
		$html  = hpcsched_render(fn () => (new OverviewPage(new ClusterRepository(false, $clock), new ViewRepository(false), new FreshnessEvaluator($clock), $clock, 60, hpcsched_access()))->renderReasons((new ClusterRepository(false, $clock))->get(1)));

		expect($html)->toContain('<td>Priority</td><td>Priority</td><td>3</td><td>batch, gpu</td>')
			->and($html)->toContain('<td>SomethingNew</td><td>Other</td><td>2</td>')
			->and($html)->toContain('<td>FUTURE_STATE_X</td><td>1</td>')
			->and($html)->not->toContain('g04')
			->and($html)->not->toContain('<script>')
			->and($html)->toContain('Jobs snapshot taken');
	});

	it('gives the node table its own snapshot time and freshness, and reads everything in one transaction', function () {
		$clock = new FixedClock(100000);
		$page  = fn (): string => hpcsched_render(fn () => (new OverviewPage(new ClusterRepository(false, $clock), new ViewRepository(false), new FreshnessEvaluator($clock), $clock, 60, hpcsched_access()))->renderReasons((new ClusterRepository(false, $clock))->get(1)));

		// Nodes never collected: unknown, not "every node maps to a known state".
		$GLOBALS['__hpcsched_db_rows']['GROUP BY raw_state'] = [];
		$GLOBALS['__hpcsched_db_exec']                      = [];
		$html                                               = $page();

		expect($html)->toContain('No nodes snapshot has been collected yet')
			->and($html)->not->toContain('Every node maps to a known state')
			->and(array_column($GLOBALS['__hpcsched_db_exec'], 'sql'))->toBe(['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'BEGIN', 'COMMIT']);

		// Nodes kept from an older collection whose last attempt failed.
		$GLOBALS['__hpcsched_db_rows']['FROM plugin_hpcsched_section'][1]['observed_at']      = 99000;
		$GLOBALS['__hpcsched_db_rows']['FROM plugin_hpcsched_section'][1]['collect_interval'] = 60;
		$html                                                                                 = $page();

		expect($html)->toContain('Jobs snapshot taken ' . date('Y-m-d H:i:s', 99990))
			->and($html)->toContain('current.')
			->and($html)->toContain('<span class="textWarning">Nodes snapshot taken ' . date('Y-m-d H:i:s', 99000))
			->and($html)->toContain('the nodes below are the last ones collected')
			->and($html)->toContain('Every node maps to a known state');
	});

	it('names nodes in unknown states only for the Admin realm, and limits the rows it asks for', function () {
		$clock = new FixedClock(100000);
		$GLOBALS['__hpcsched_db_reads'] = [];
		$html  = hpcsched_render(fn () => (new OverviewPage(new ClusterRepository(false, $clock), new ViewRepository(false), new FreshnessEvaluator($clock), $clock, 60, hpcsched_access(), true))->renderReasons((new ClusterRepository(false, $clock))->get(1)));

		expect($html)->toContain('<td>g04</td>');

		foreach ($GLOBALS['__hpcsched_db_reads'] as $sql) {
			if (str_contains($sql, "kind = 'reason' AND partition_name = ''")) {
				expect($sql)->toContain('LIMIT ' . (OverviewPage::ROW_LIMIT + 1));
			}
		}
	});
});

function hpcsched_form_settings(string $dirs = '/etc/cacti', string $hosts = ''): Settings {
	return Settings::fromRow(['hpcsched_token_dirs' => $dirs, 'hpcsched_rest_hosts' => $hosts]);
}

it('validates the cluster form and keeps a deleted poller selected', function () {
	$existing = new Cluster(4, 'demo', 7, AdminState::Enabled, Transport::Commands, 60);
	$ok       = ClusterInput::parse(['name' => 'demo', 'poller_id' => '7', 'collect_interval' => '60', 'transport' => 'commands', 'enabled' => 'on'], $existing, [1 => 'Main'], hpcsched_form_settings());
	$moved    = ClusterInput::parse(['name' => 'demo', 'poller_id' => '9', 'collect_interval' => '60', 'transport' => 'commands'], $existing, [1 => 'Main'], hpcsched_form_settings());

	expect($ok->valid())->toBeTrue()
		->and($ok->pollerId)->toBe(7)
		->and($ok->id)->toBe(4)
		->and($moved->errors)->toHaveKey('poller_id');
});

it('rejects unsafe names, paths, URLs, and intervals', function (array $input, string $field) {
	$base = ['name' => 'demo', 'poller_id' => '1', 'collect_interval' => '60', 'transport' => 'rest', 'rest_url' => 'https://slurm.example.org', 'token_file' => '/etc/cacti/token'];

	expect(ClusterInput::parse($input + $base, null, [1 => 'Main'], hpcsched_form_settings())->errors)->toHaveKey($field);
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
	[['token_file' => '/etc/passwd'], 'token_file'],
	[['token_file' => '/etc/cacti-other/token'], 'token_file'],
	[['token_file' => '/etc/cacti/../shadow'], 'token_file'],
]);

it('accepts a REST cluster only inside the token directories and on the host list from Settings', function () {
	$input = ['name' => 'demo', 'poller_id' => '1', 'collect_interval' => '60', 'transport' => 'rest', 'rest_url' => 'https://slurm.example.org:6820', 'token_file' => '/etc/cacti/hpcsched/token'];
	$parse = static fn (Settings $settings, array $override = []): array => ClusterInput::parse($override + $input, null, [1 => 'Main'], $settings)->errors;

	expect($parse(hpcsched_form_settings()))->toBe([])
		->and($parse(hpcsched_form_settings(''))['token_file'])->toContain('no token file directories are set')
		->and($parse(hpcsched_form_settings('/etc/cacti', 'slurm.example.org')))->toBe([])
		->and($parse(hpcsched_form_settings('/etc/cacti', 'slurm.example.org:6820')))->toBe([])
		->and($parse(hpcsched_form_settings('/etc/cacti', 'slurm.example.org:443')))->toHaveKey('rest_url')
		->and($parse(hpcsched_form_settings('/etc/cacti', 'other.example.org')))->toHaveKey('rest_url')
		->and($parse(hpcsched_form_settings('/etc/cacti', 'other.example.org, [::1]:6820'), ['rest_url' => 'http://[::1]:6820']))->toBe([])
		->and($parse(hpcsched_form_settings('/etc/cacti', 'slurm.example.org'), ['rest_url' => 'https://10.0.0.5:6820']))->toHaveKey('rest_url');
});

it('parses the token directories and host list conservatively', function () {
	expect(Settings::directories("/etc/cacti/, /srv/tokens\n relative / /a/../b /etc//x"))->toBe(['/etc/cacti', '/srv/tokens'])
		->and(Settings::hosts('Slurm.Example.org:6820 bad_host [::1] 10.0.0.5:99999999 x:y'))->toBe(['slurm.example.org:6820', '[::1]'])
		->and(Settings::fromRow([])->tokenDirs)->toBe([])
		->and(Settings::fromRow([])->restHosts)->toBe([]);
});

it('clears REST fields when the cluster uses client commands', function () {
	$parsed = ClusterInput::parse(['name' => 'demo', 'poller_id' => '1', 'collect_interval' => '60', 'transport' => 'commands', 'rest_url' => 'http://evil.example.org', 'token_file' => 'x'], null, [1 => 'Main'], hpcsched_form_settings());

	expect($parsed->valid())->toBeTrue()
		->and($parsed->restUrl)->toBe('')
		->and($parsed->tokenFile)->toBe('');
});
