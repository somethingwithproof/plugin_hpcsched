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

use Somethingwithproof\Hpcsched\ConfigCodec;
use Somethingwithproof\Hpcsched\ConfigException;
use Somethingwithproof\Hpcsched\MaintAction;
use Somethingwithproof\Hpcsched\MaintSyncPlan;
use Somethingwithproof\Hpcsched\OperatorPage;
use Somethingwithproof\Hpcsched\ShareRecord;
use Somethingwithproof\Hpcsched\ShareSummary;
use Somethingwithproof\Hpcsched\YamlReader;

it('rejects malformed YAML constructs across YamlReader validation branches', function (string $yaml) {
	expect(fn () => YamlReader::parse($yaml))->toThrow(ConfigException::class);
})->with([
	'bare carriage return'    => ["a: 1\rb: 2"],
	'control character'       => ["a: \x01bad"],
	'tab indentation'         => ["a:\n\tb: 1"],
	'second document'         => ["a: 1\n---\nb: 2"],
	'yaml directive'          => ["%YAML 1.2\na: 1"],
	'unexpected indentation'  => ["a: 1\n  b: 2"],
	'list where map expected' => ["a: 1\n- item"],
	'missing colon'           => ['not a mapping'],
	'duplicate key'           => ["a: 1\na: 2"],
	'anchor'                  => ['a: &ref 1'],
	'flow list'               => ['a: [1, 2]'],
	'block scalar'            => ["a: |\n  hello"],
	'reserved scalar start'   => ['a: @bad'],
	'empty key'               => [': value'],
	'merge key'               => ['<<: value'],
	'unquoted colon in val'   => ['a: b: c'],
	'ambiguous yaml 1.1'      => ['a: yes'],
	'trailing after quote'    => ['a: "ok" extra'],
	'unterminated quote'      => ['a: "unterminated'],
	'bad escape'              => ['a: "\q"'],
	'nested inline list'      => ['- - item'],
]);

it('covers ConfigCodec error/file/stream paths, MaintSyncPlan/MaintAction, ShareSummary, and OperatorPage rendering', function () {
	expect(ConfigCodec::isFormat('yaml'))->toBeTrue()
		->and(ConfigCodec::isFormat('xml'))->toBeTrue()
		->and(ConfigCodec::isFormat('json'))->toBeFalse()
		->and(fn () => ConfigCodec::parse('json', '{}'))->toThrow(ConfigException::class)
		->and(fn () => ConfigCodec::write('json', []))->toThrow(ConfigException::class)
		->and(fn () => ConfigCodec::readFile('/nonexistent/hpcsched-config.yaml'))->toThrow(ConfigException::class);

	$stream = fopen('php://memory', 'r+');
	assert(is_resource($stream));
	fwrite($stream, "schema_version: 1\n");
	rewind($stream);
	expect(ConfigCodec::readStream($stream))->toBe("schema_version: 1\n");
	fclose($stream);

	$schemaPath = dirname(__DIR__, 2) . '/schema/hpcsched.schema.yaml';
	expect(ConfigCodec::readFile($schemaPath))->toContain('plugin: hpcsched');

	$create = new MaintAction('create', 1, 'node01', 42, 'drain', 'bad dimm', 1800000000, 1800003600, 0);
	$update = new MaintAction('update', 1, 'node02', 43, 'reservation', 'res1', 1800000000, 1800007200, 7);
	$remove = new MaintAction('remove', 1, 'node03', 44, 'drain', 'cleared', 1800000000, 1800003600, 8);
	$plan   = new MaintSyncPlan(true, [$create, $update, $remove], ['example warning']);

	expect($create->toArray()['operation'])->toBe('create')
		->and($plan->isEmpty())->toBeFalse()
		->and(strlen($plan->id()))->toBe(16)
		->and($plan->lines())->toHaveCount(4)
		->and($plan->lines()[0])->toStartWith('WARNING:')
		->and($plan->lines()[1])->toStartWith('+ maint')
		->and($plan->lines()[2])->toContain('schedule=7')
		->and($plan->lines()[3])->toStartWith('- maint');

	$summary = ShareSummary::fromRecords(1, [
		new ShareRecord('physics', 'batch', 'root', 100.0, 0.5, 0.4, 0.8, 0.9),
	]);
	expect($summary->toArray()['accountCount'])->toBe(1);

	if (!function_exists('form_start')) {
		function form_start(string $action): void {
			print '<form action="' . $action . '">';
		}

		function form_end(bool $trailing = true): void {
			print '</form>';
		}
	}

	$now     = 1800000000;
	$row1    = ['id' => 'c:1', 'name' => 'prod', 'state' => 'warn', 'observed' => $now - 10, 'interval' => 60, 'detail' => 'drained'];
	$row2    = ['id' => 'c:2', 'name' => 'dev', 'state' => 'warn', 'observed' => $now - 10, 'interval' => 60, 'detail' => 'pending'];
	$row3    = ['id' => 'c:3', 'name' => 'old', 'state' => 'unknown', 'observed' => 0, 'interval' => 60, 'detail' => 'never'];
	$session = [];

	OperatorPage::acknowledge($session, $row1, OperatorPage::fingerprint($row1), 5, $now);

	ob_start();
	OperatorPage::render([$row1, $row2, $row3], $session, 5, $now, ['refresh' => 'Refresh']);
	$html = (string) ob_get_clean();
	expect($html)->toContain('Acknowledged; health unchanged.')
		->and($html)->toContain('Acknowledge')
		->and($html)->toContain('Unknown or stale');

	ob_start();
	OperatorPage::render([], [], 5, $now, []);
	expect((string) ob_get_clean())->toContain('No observations are available');
});
