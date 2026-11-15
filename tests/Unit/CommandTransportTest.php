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

require_once dirname(__DIR__) . '/Support/Scratch.php';

use Somethingwithproof\Hpcsched\CommandRunner;
use Somethingwithproof\Hpcsched\CommandTransport;
use Somethingwithproof\Hpcsched\ErrorClass;
use Somethingwithproof\Hpcsched\Section;
use Somethingwithproof\Hpcsched\Settings;
use Somethingwithproof\Hpcsched\Tests\Scratch;
use Somethingwithproof\Hpcsched\TransportError;

/*
 * The client command transport against stand-in binaries under
 * tests/fixtures/bin; no Slurm installation is involved.
 */

function hpcsched_bin(string $name): string {
	return dirname(__DIR__) . '/fixtures/bin/' . $name;
}

function hpcsched_settings_for(bool $prlimit = true, int $timeout = 5, int $maxMib = 8): Settings {
	return new Settings(hpcsched_bin('squeue'), hpcsched_bin('scontrol'), hpcsched_bin('sshare'), $prlimit ? hpcsched_bin('prlimit') : '', 2048, $timeout, $maxMib, 3600, true);
}

function hpcsched_scenario(Scratch $scratch, string $scenario): string {
	foreach (glob(dirname(__DIR__) . '/fixtures/slurm/25.11/*.json') ?: [] as $file) {
		$scratch->file(basename($file), (string) file_get_contents($file));
	}

	return $scratch->file('slurm.conf', "$scenario\n{$scratch->dir}\n");
}

function hpcsched_error(callable $call): ?ErrorClass {
	try {
		$call();
	} catch (TransportError $e) {
		return $e->errorClass;
	}

	return null;
}

beforeEach(function () {
	$this->scratch = new Scratch();
});

afterEach(function () {
	$this->scratch->remove();
});

it('runs each section with its fixed argument vector under prlimit', function () {
	$conf      = hpcsched_scenario($this->scratch, 'ok');
	$transport = new CommandTransport(new CommandRunner(), hpcsched_settings_for(), $conf);

	foreach (Section::cases() as $section) {
		$json = $transport->fetch($section, 'v0.0.43', microtime(true) + 10);

		expect(json_decode($json, true))->toHaveKey($section === Section::Shares ? 'shares' : $section->value);
	}

	expect(file($this->scratch->dir . '/calls.txt', FILE_IGNORE_NEW_LINES))->toBe([
		'squeue', '--all', '--json=v0.0.43',
		'scontrol', '--json=v0.0.43', 'show', 'node',
		'scontrol', '--json=v0.0.43', 'show', 'partition',
		'scontrol', '--json=v0.0.43', 'show', 'reservation',
		'sshare', '--json=v0.0.43',
	])
		->and(array_unique(file($this->scratch->dir . '/prlimit.txt', FILE_IGNORE_NEW_LINES)))->toBe(['--as=' . (2048 * 1048576)])
		->and($transport->calls())->toBe(5)
		->and($transport->memoryLimited())->toBeTrue();
});

it('gives commands only PATH, LC_ALL, and SLURM_CONF from the environment', function () {
	putenv('HPCSCHED_LEAK=visible');
	putenv('SLURM_JWT=should-not-pass');

	try {
		$conf = hpcsched_scenario($this->scratch, 'ok');
		(new CommandTransport(new CommandRunner(), hpcsched_settings_for(), $conf))->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 10);
	} finally {
		putenv('HPCSCHED_LEAK');
		putenv('SLURM_JWT');
	}

	$env = (string) file_get_contents($this->scratch->dir . '/env-jobs.txt');

	expect($env)->toContain("LC_ALL=C\n")
		->and($env)->toContain("PATH=/usr/bin:/bin\n")
		->and($env)->toContain("SLURM_CONF=$conf\n")
		->and($env)->not->toContain('HPCSCHED_LEAK')
		->and($env)->not->toContain('SLURM_JWT')
		->and($env)->not->toContain('HOME=');
});

it('reports when commands ran without a memory limit', function () {
	$conf      = hpcsched_scenario($this->scratch, 'ok');
	$transport = new CommandTransport(new CommandRunner(), hpcsched_settings_for(false), $conf);
	$transport->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 10);

	expect($transport->memoryLimited())->toBeFalse()
		->and(is_file($this->scratch->dir . '/prlimit.txt'))->toBeFalse();
});

it('kills a command that hangs past the call timeout', function () {
	$conf    = hpcsched_scenario($this->scratch, 'hang');
	$started = microtime(true);
	$error   = hpcsched_error(fn () => (new CommandTransport(new CommandRunner(), hpcsched_settings_for(true, 2), $conf))->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 30));

	expect($error)->toBe(ErrorClass::Timeout)
		->and(microtime(true) - $started)->toBeLessThan(5.0);
});

it('never runs a call past the run deadline, whatever the call timeout', function () {
	$conf    = hpcsched_scenario($this->scratch, 'hang');
	$started = microtime(true);
	$error   = hpcsched_error(fn () => (new CommandTransport(new CommandRunner(), hpcsched_settings_for(true, 300), $conf))->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 1.0));

	expect($error)->toBe(ErrorClass::Timeout)
		->and(microtime(true) - $started)->toBeLessThan(3.0);
});

it('discards and kills an answer larger than allowed', function () {
	$conf  = hpcsched_scenario($this->scratch, 'flood');
	$error = hpcsched_error(fn () => (new CommandTransport(new CommandRunner(), hpcsched_settings_for(true, 10, 1), $conf))->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 30));

	expect($error)->toBe(ErrorClass::TooLarge);
});

it('classifies failures from standard error without passing the text on', function (string $scenario, ErrorClass $class) {
	$conf = hpcsched_scenario($this->scratch, $scenario);

	try {
		(new CommandTransport(new CommandRunner(), hpcsched_settings_for(), $conf))->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 10);
		$caught = null;
	} catch (TransportError $e) {
		$caught = $e;
	}

	expect($caught?->errorClass)->toBe($class)
		->and($caught?->getMessage())->not->toContain('eyJ')
		->and($caught?->getMessage())->not->toContain('controller');
})->with([
	['unreachable', ErrorClass::Unreachable],
	['ratelimit', ErrorClass::RateLimited],
	['parser', ErrorClass::Config],
	['stderr-secret', ErrorClass::Failed],
]);

it('refuses a missing binary, a relative path, and a bad slurm.conf path as configuration errors', function () {
	$conf     = hpcsched_scenario($this->scratch, 'ok');
	$missing  = new Settings('/nonexistent/squeue', hpcsched_bin('scontrol'), hpcsched_bin('sshare'), '', 2048, 5, 8, 3600, true);
	$relative = new Settings('squeue', hpcsched_bin('scontrol'), hpcsched_bin('sshare'), '', 2048, 5, 8, 3600, true);

	expect(hpcsched_error(fn () => (new CommandTransport(new CommandRunner(), $missing, $conf))->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 5)))->toBe(ErrorClass::Config)
		->and(hpcsched_error(fn () => (new CommandTransport(new CommandRunner(), $relative, $conf))->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 5)))->toBe(ErrorClass::Config)
		->and(hpcsched_error(fn () => (new CommandTransport(new CommandRunner(), hpcsched_settings_for(), $conf . ' x'))->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 5)))->toBe(ErrorClass::Config)
		->and(hpcsched_error(fn () => (new CommandTransport(new CommandRunner(), hpcsched_settings_for(), $conf))->fetch(Section::Jobs, 'v0.0.43; id', microtime(true) + 5)))->toBe(ErrorClass::Config);
});

it('passes shell metacharacters through as plain arguments, with no shell', function () {
	$conf = hpcsched_scenario($this->scratch, 'ok');
	$mark = $this->scratch->dir . '/pwned';

	(new CommandRunner())->run([hpcsched_bin('squeue'), '$(touch ' . $mark . ')', '; touch ' . $mark, '`touch ' . $mark . '`'], ['PATH' => '/usr/bin:/bin', 'SLURM_CONF' => $conf], 5, 1048576);

	expect(file_exists($mark))->toBeFalse()
		->and(file($this->scratch->dir . '/calls.txt', FILE_IGNORE_NEW_LINES))->toBe(['squeue', '$(touch ' . $mark . ')', '; touch ' . $mark, '`touch ' . $mark . '`']);
});

it('reports a relative or missing program as not started', function () {
	expect((new CommandRunner())->run(['squeue'], [], 1, 10)->started)->toBeFalse()
		->and((new CommandRunner())->run([], [], 1, 10)->started)->toBeFalse()
		->and((new CommandRunner())->run(['/nonexistent'], [], 1, 10)->started)->toBeFalse();
});
