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

use Somethingwithproof\Hpcsched\Log;

beforeEach(function () {
	$GLOBALS['__hpcsched_log'] = [];
});

it('reads its metadata from INFO, declaring remote collection and remote poller hooks', function () {
	$info = plugin_hpcsched_version();

	expect($info['name'])->toBe('hpcsched')
		->and($info['compat'])->toBe('1.2.31')
		->and($info['capabilities'])->toContain('remote_collect:1')
		->and($info['capabilities'])->toContain('remote_poller:1');
});

it('boots and loads plugin classes on a supported runtime', function () {
	expect(hpcsched_php_supported())->toBeTrue()
		->and(hpcsched_boot())->toBeTrue()
		->and(class_exists(Log::class))->toBeTrue();
});

it('does not refuse or log on a supported runtime', function () {
	expect(hpcsched_refuse_unsupported())->toBeFalse()
		->and($GLOBALS['__hpcsched_log'])->toBe([]);
});

it('ignores classes outside its namespace and nested names', function () {
	expect(class_exists('Somethingwithproof\\Hpcsched\\Nested\\Log'))->toBeFalse()
		->and(class_exists('Somethingwithproof\\HpcschedLog'))->toBeFalse();
});

it('logs under the HPCSCHED tag at the right verbosity', function () {
	Log::debug('d');
	Log::info('i');
	Log::warning('w');
	Log::error('e');

	expect($GLOBALS['__hpcsched_log'])->toBe([
		['message' => 'DEBUG: d', 'environ' => 'HPCSCHED', 'level' => POLLER_VERBOSITY_DEBUG],
		['message' => 'NOTE: i', 'environ' => 'HPCSCHED', 'level' => POLLER_VERBOSITY_MEDIUM],
		['message' => 'WARNING: w', 'environ' => 'HPCSCHED', 'level' => ''],
		['message' => 'ERROR: e', 'environ' => 'HPCSCHED', 'level' => ''],
	]);
});
