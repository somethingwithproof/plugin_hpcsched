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

use Somethingwithproof\Hpcsched\FixedClock;
use Somethingwithproof\Hpcsched\HealthWarnings;
use Somethingwithproof\Hpcsched\Pages;
use Somethingwithproof\Hpcsched\Schema;
use Somethingwithproof\Hpcsched\Settings;

it('applies documented defaults to settings that were never saved', function () {
	$settings = Settings::fromRow([]);

	expect($settings->squeue)->toBe(Settings::DEFAULT_SQUEUE)
		->and($settings->prlimit)->toBe(Settings::DEFAULT_PRLIMIT)
		->and($settings->callTimeout)->toBe(Settings::DEFAULT_CALL_TIMEOUT)
		->and($settings->maxOutputBytes())->toBe(Settings::DEFAULT_MAX_OUTPUT * 1048576)
		->and($settings->keepTablesOnUninstall)->toBeTrue();
});

it('fits the answer size to the reader memory and warns when the setting is larger', function () {
	$fit = static fn (array $row): Settings => Settings::fromRow($row);

	expect($fit([])->fittedOutputMib())->toBe(64)
		->and($fit(['hpcsched_max_output' => '128'])->fittedOutputMib())->toBe(80)
		->and($fit(['hpcsched_max_output' => '128'])->maxOutputBytes())->toBe(80 * 1048576)
		->and($fit(['hpcsched_max_output' => '128', 'hpcsched_reader_memory' => '2048'])->fittedOutputMib())->toBe(128)
		->and($fit(['hpcsched_max_output' => '1024', 'hpcsched_reader_memory' => '100'])->readerMemoryMib)->toBe(Settings::DEFAULT_READER_MEMORY)
		->and($fit(['hpcsched_max_output' => '1024', 'hpcsched_reader_memory' => '256'])->fittedOutputMib())->toBe(16)
		->and($fit([])->withMemoryLimit(512 * 1048576)->fittedOutputMib())->toBe(37)
		->and($fit([])->withMemoryLimit(-1))->toEqual($fit([]))
		->and($fit([])->withMemoryLimit(4096 * 1048576))->toEqual($fit([]))
		->and(HealthWarnings::global($fit([])))->toBe([])
		->and(implode('', HealthWarnings::global($fit(['hpcsched_max_output' => '128']))))->toContain('Maximum Answer Size is 128 MiB')
		->and(implode('', HealthWarnings::global($fit(['hpcsched_max_output' => '128']))))->toContain('the reader allows 80 MiB');
});

it('records a run that ran out of memory and frees its lease and process slot', function () {
	$GLOBALS['__hpcsched_db_exec']      = [];
	$GLOBALS['__hpcsched_log']          = [];
	$GLOBALS['__hpcsched_unregistered'] = [];

	hpcsched_record_memory_failure(3, 'reader-1-ab', new FixedClock(100000));

	$sql = array_column($GLOBALS['__hpcsched_db_exec'], 'sql');

	expect($sql[0])->toContain('UPDATE plugin_hpcsched_run SET finished_at')
		->and($GLOBALS['__hpcsched_db_exec'][0]['params'])->toContain('memory')
		->and($GLOBALS['__hpcsched_db_exec'][0]['params'])->toContain('failed')
		->and($sql[1])->toContain('SET lease_expires = 0')
		->and($GLOBALS['__hpcsched_unregistered'])->toBe([['hpcsched', 'reader', 3]])
		->and(json_encode($GLOBALS['__hpcsched_log']))->toContain('ran out of memory');
});

it('starts no reader and reads no plugin table on a remote data collector', function () {
	$GLOBALS['__hpcsched_db_exec'] = [];
	$GLOBALS['__hpcsched_db_rows'] = [];
	$GLOBALS['__hpcsched_log']     = [];
	$GLOBALS['__hpcsched_db_reads'] = [];
	$GLOBALS['config']['poller_id'] = 2;

	try {
		expect(hpcsched_on_main_poller())->toBeFalse();

		hpcsched_poller_bottom();

		expect($GLOBALS['__hpcsched_db_exec'])->toBe([])
			->and($GLOBALS['__hpcsched_db_reads'])->toBe([])
			->and($GLOBALS['__hpcsched_log'])->toBe([]);
	} finally {
		$GLOBALS['config']['poller_id'] = 1;
	}

	expect(hpcsched_on_main_poller())->toBeTrue();
});

it('turns the memory limit off only when the prlimit path was saved empty', function () {
	expect(Settings::fromRow(['hpcsched_prlimit' => ''])->prlimit)->toBe('')
		->and(Settings::fromRow(['hpcsched_prlimit' => 'relative'])->prlimit)->toBe(Settings::DEFAULT_PRLIMIT);
});

it('drops tables on uninstall only after the box was cleared and saved', function () {
	expect(Settings::fromRow(['hpcsched_uninstall_keep_tables' => ''])->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['hpcsched_uninstall_keep_tables' => null])->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['hpcsched_uninstall_keep_tables' => 'on'])->keepTablesOnUninstall)->toBeTrue();
});

it('accepts only a supported data parser and defaults to v0.0.43', function (mixed $stored, string $expected) {
	expect(Settings::fromRow(['hpcsched_data_parser' => $stored])->dataParser)->toBe($expected);
})->with([
	['v0.0.44', 'v0.0.44'],
	['v0.0.45', 'v0.0.45'],
	['v0.0.42', 'v0.0.43'],
	['v0.0.46', 'v0.0.43'],
	['--json', 'v0.0.43'],
	['', 'v0.0.43'],
	[null, 'v0.0.43'],
]);

it('bounds numeric settings', function (string $name, mixed $stored, string $property, int $expected) {
	expect(Settings::fromRow([$name => $stored])->{$property})->toBe($expected);
})->with([
	['hpcsched_call_timeout', '10', 'callTimeout', 10],
	['hpcsched_call_timeout', '1', 'callTimeout', Settings::DEFAULT_CALL_TIMEOUT],
	['hpcsched_call_timeout', '9999', 'callTimeout', 300],
	['hpcsched_max_output', 'abc', 'maxOutputMib', Settings::DEFAULT_MAX_OUTPUT],
	['hpcsched_child_memory', '8', 'childMemoryMib', Settings::DEFAULT_CHILD_MEMORY],
	['hpcsched_token_warning', '0', 'tokenWarning', 0],
]);

it('accepts only plain absolute paths for the client commands', function (string $stored, string $expected) {
	expect(Settings::fromRow(['hpcsched_squeue' => $stored])->squeue)->toBe($expected);
})->with([
	['/opt/slurm/bin/squeue', '/opt/slurm/bin/squeue'],
	['squeue', Settings::DEFAULT_SQUEUE],
	['/opt/../bin/sh', Settings::DEFAULT_SQUEUE],
	['/opt/slurm/bin/squeue --all; id', Settings::DEFAULT_SQUEUE],
	["/opt/squeue\nx", Settings::DEFAULT_SQUEUE],
	['/opt//squeue', Settings::DEFAULT_SQUEUE],
	['', Settings::DEFAULT_SQUEUE],
]);

it('gives every setting in the form a default that fromRow agrees with', function () {
	$form = Settings::form();
	$row  = [];

	foreach (Settings::NAMES as $name) {
		expect($form)->toHaveKey($name);
		$row[$name] = $form[$name]['default'];
	}

	expect(Settings::fromRow($row))->toEqual(Settings::fromRow([]));
});

it('puts every page in exactly one realm, with configuration and health in the admin realm only (REQ-UI-001)', function () {
	$files = Pages::files();

	expect($files)->toBe(array_values(array_unique($files)))
		->and(Pages::ADMIN)->toBe(['hpcsched_clusters.php', 'hpcsched_health.php'])
		->and(Pages::VIEW)->toBe(['hpcsched.php']);
});

it('lists every page script the plugin ships in a realm', function () {
	$scripts = array_map('basename', glob(dirname(__DIR__, 2) . '/hpcsched*.php') ?: []);

	expect($scripts)->toEqualCanonicalizing(Pages::files());
});

it('gives every menu page a breadcrumb under the console', function () {
	$nav = Pages::navigation();

	foreach (array_keys(Pages::menu()) as $file) {
		expect($nav[$file . ':']['mapping'])->toBe('index.php:')
			->and($nav[$file . ':']['url'])->toBe($file);
	}
});

it('names every hook function that setup.php defines', function () {
	foreach (Schema::HOOKS as $function) {
		expect(function_exists($function))->toBeTrue($function);
	}
});

it('defines plugin tables with the plugin prefix, a key, and no credential column (REQ-CRED-001)', function () {
	foreach (Schema::tables() as $table => $definition) {
		expect($table)->toStartWith('plugin_hpcsched_')
			->and(isset($definition['primary']) || isset($definition['unique_keys']))->toBeTrue($table)
			->and($definition['type'])->toBe('InnoDB');

		foreach ($definition['columns'] as $column) {
			expect($column['name'] === 'token_file' || $column['name'] === 'token_state' || $column['name'] === 'token_expires' || preg_match('/token|password|secret|jwt/', $column['name']) !== 1)->toBeTrue("$table.{$column['name']}");
		}
	}
});

it('stamps every cached value table with observation, ingestion, and interval (REQ-STATE-004)', function () {
	foreach (['plugin_hpcsched_job_count', 'plugin_hpcsched_node', 'plugin_hpcsched_partition', 'plugin_hpcsched_reservation', 'plugin_hpcsched_share', 'plugin_hpcsched_section'] as $table) {
		$names = array_column(Schema::tables()[$table]['columns'], 'name');

		expect($names)->toContain('observed_at')
			->and($names)->toContain('ingested_at')
			->and($names)->toContain('collect_interval');
	}
});

it('stores enum values as strings, never positions (REQ-CODE-003)', function () {
	foreach (Schema::tables() as $table => $definition) {
		foreach ($definition['columns'] as $column) {
			expect(strtolower($column['type']))->not->toStartWith('enum', "$table.{$column['name']}");
		}
	}
});
