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

use Somethingwithproof\Hpcsched\Schema;

beforeEach(function () {
	$GLOBALS['__hpcsched_db_exec']  = [];
	$GLOBALS['__hpcsched_db_rows']  = [];
	$GLOBALS['__hpcsched_db_reads'] = [];
	$GLOBALS['__hpcsched_log']      = [];
});

it('migrates cleanly from main schema version 2 to version 3 and is idempotent', function () {
	if (!function_exists('api_plugin_db_table_create')) {
		function api_plugin_db_table_create(string $plugin, string $table, array $data): void {
			$GLOBALS['__hpcsched_created_tables'][] = [$plugin, $table];
		}
	}

	$GLOBALS['__hpcsched_created_tables'] = [];
	$GLOBALS['__hpcsched_db_rows']        = [
		'SELECT value FROM settings WHERE name = ?' => [['value' => '2']],
		'information_schema.TABLES'                 => [['c' => 1]],
	];

	$schema = new Schema(false);
	expect($schema->migrate())->toBeTrue()
		->and($GLOBALS['__hpcsched_created_tables'])->toBe([
			['hpcsched', 'plugin_hpcsched_node_map'],
			['hpcsched', 'plugin_hpcsched_maint_link'],
		]);

	$sqls = array_column($GLOBALS['__hpcsched_db_exec'], 'sql');
	expect(array_filter($sqls, static fn (string $s): bool => str_contains($s, 'REPLACE INTO settings')))->not->toBeEmpty();

	// Idempotent second run at version 3 issues zero schema writes.
	$GLOBALS['__hpcsched_db_exec']        = [];
	$GLOBALS['__hpcsched_created_tables'] = [];
	$GLOBALS['__hpcsched_db_rows']        = [
		'SELECT value FROM settings WHERE name = ?' => [['value' => (string) Schema::VERSION]],
	];

	expect($schema->isCurrent())->toBeTrue()
		->and($schema->migrate())->toBeTrue()
		->and($GLOBALS['__hpcsched_db_exec'])->toBe([])
		->and($GLOBALS['__hpcsched_created_tables'])->toBe([]);
});

it('refuses schema downgrade when stored version exceeds code version', function () {
	$GLOBALS['__hpcsched_db_rows'] = [
		'SELECT value FROM settings WHERE name = ?' => [['value' => (string) (Schema::VERSION + 5)]],
	];

	$schema = new Schema(false);
	expect($schema->migrate())->toBeFalse()
		->and($GLOBALS['__hpcsched_db_exec'])->toBe([]);
});
