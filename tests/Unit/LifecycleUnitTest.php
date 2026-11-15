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

it('turns the memory limit off only when the prlimit path was saved empty', function () {
	expect(Settings::fromRow(['hpcsched_prlimit' => ''])->prlimit)->toBe('')
		->and(Settings::fromRow(['hpcsched_prlimit' => 'relative'])->prlimit)->toBe(Settings::DEFAULT_PRLIMIT);
});

it('drops tables on uninstall only after the box was cleared and saved', function () {
	expect(Settings::fromRow(['hpcsched_uninstall_keep_tables' => ''])->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['hpcsched_uninstall_keep_tables' => null])->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['hpcsched_uninstall_keep_tables' => 'on'])->keepTablesOnUninstall)->toBeTrue();
});

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
