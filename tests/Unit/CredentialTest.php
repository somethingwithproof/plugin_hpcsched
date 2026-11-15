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
require_once dirname(__DIR__) . '/Support/StubHttp.php';

use Somethingwithproof\Hpcsched\AdminState;
use Somethingwithproof\Hpcsched\Cluster;
use Somethingwithproof\Hpcsched\CommandRunner;
use Somethingwithproof\Hpcsched\CredentialError;
use Somethingwithproof\Hpcsched\FixedClock;
use Somethingwithproof\Hpcsched\HttpResponse;
use Somethingwithproof\Hpcsched\Reader;
use Somethingwithproof\Hpcsched\Redactor;
use Somethingwithproof\Hpcsched\RunRepository;
use Somethingwithproof\Hpcsched\Secret;
use Somethingwithproof\Hpcsched\Settings;
use Somethingwithproof\Hpcsched\SnapshotRepository;
use Somethingwithproof\Hpcsched\TokenFile;
use Somethingwithproof\Hpcsched\TokenState;
use Somethingwithproof\Hpcsched\Transport;
use Somethingwithproof\Hpcsched\Transports;
use Somethingwithproof\Hpcsched\Tests\Scratch;
use Somethingwithproof\Hpcsched\Tests\StubHttp;

const HPCSCHED_FIXTURE_TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjQxMDI0NDQ4MDAsInN1biI6ImNhY3RpIn0.Zml4dHVyZS1zaWduYXR1cmU';

beforeEach(function () {
	$this->scratch = new Scratch();
	$GLOBALS['__hpcsched_log']     = [];
	$GLOBALS['__hpcsched_db_exec'] = [];
	$GLOBALS['__hpcsched_db_rows'] = [];
	$GLOBALS['__hpcsched_in_tx']   = [];
});

afterEach(function () {
	$this->scratch->remove();
	$GLOBALS['__hpcsched_db_rows'] = [];
});

function hpcsched_token_state(Scratch $scratch, int $mode, string $content = HPCSCHED_FIXTURE_TOKEN): TokenState|string {
	$path = $scratch->file('token', $content, $mode);

	try {
		return TokenFile::read($path)->state;
	} catch (CredentialError $e) {
		return 'refused:' . $e->state->value;
	}
}

it('reads a token readable only by the poller user, with its expiry', function () {
	$token = TokenFile::read($this->scratch->file('token', HPCSCHED_FIXTURE_TOKEN . "\n", 0600));

	expect($token->state)->toBe(TokenState::Ok)
		->and($token->secret->reveal())->toBe(HPCSCHED_FIXTURE_TOKEN)
		->and($token->expires)->toBe(4102444800);
});

it('reads the SLURM_JWT= form scontrol token prints', function () {
	expect(TokenFile::read($this->scratch->file('token', 'SLURM_JWT=' . HPCSCHED_FIXTURE_TOKEN . "\n", 0600))->secret->reveal())->toBe(HPCSCHED_FIXTURE_TOKEN);
});

it('warns for group or world read and refuses others writing (REQ-CRED-003)', function (int $mode, TokenState|string $expected) {
	expect(hpcsched_token_state($this->scratch, $mode))->toBe($expected);
})->with([
	'owner only'      => [0600, TokenState::Ok],
	'owner read only' => [0400, TokenState::Ok],
	'group read'      => [0640, TokenState::GroupReadable],
	'world read'      => [0644, TokenState::WorldReadable],
	'group write'     => [0660, 'refused:refused'],
	'world write'     => [0602, 'refused:refused'],
]);

it('refuses a symbolic link, a missing file, a relative path, and an empty or multi-line file', function () {
	$target = $this->scratch->file('real', HPCSCHED_FIXTURE_TOKEN, 0600);
	symlink($target, $this->scratch->dir . '/link');

	$state = function (string $path): string {
		try {
			TokenFile::read($path);

			return 'read';
		} catch (CredentialError $e) {
			return $e->state->value;
		}
	};

	expect($state($this->scratch->dir . '/link'))->toBe('refused')
		->and($state($this->scratch->dir . '/missing'))->toBe('missing')
		->and($state('token'))->toBe('missing')
		->and($state($this->scratch->file('empty', "\n", 0600)))->toBe('empty')
		->and($state($this->scratch->file('two', "a\nb\n", 0600)))->toBe('empty')
		->and($state($this->scratch->dir))->toBe('refused');
});

it('reads no expiry from a token that is not a JWT', function () {
	expect(TokenFile::expiry('opaque-token'))->toBe(0)
		->and(TokenFile::expiry('a.!!!.c'))->toBe(0)
		->and(TokenFile::expiry(HPCSCHED_FIXTURE_TOKEN))->toBe(4102444800);
});

it('keeps a secret out of dumps, exports, serialization, and copies', function () {
	$secret = new Secret(HPCSCHED_FIXTURE_TOKEN);

	expect(print_r($secret, true))->not->toContain('eyJ')
		->and(var_export($secret, true))->not->toContain('eyJ')
		->and(fn () => serialize($secret))->toThrow(LogicException::class)
		->and(fn () => clone $secret)->toThrow(LogicException::class);

	ob_start();
	var_dump($secret);
	expect((string) ob_get_clean())->not->toContain('eyJ');
});

it('redacts known tokens, token headers, and JWT-shaped text', function () {
	$redactor = new Redactor();
	$redactor->add(new Secret('opaque-secret-value'));

	$clean = $redactor->clean("X-SLURM-USER-TOKEN: abc\nAuthorization: Bearer xyz opaque-secret-value " . rawurlencode('opaque-secret-value') . ' SLURM_JWT=zzz ' . HPCSCHED_FIXTURE_TOKEN);

	expect($clean)->not->toContain('abc')
		->and($clean)->not->toContain('xyz')
		->and($clean)->not->toContain('opaque-secret-value')
		->and($clean)->not->toContain('zzz')
		->and($clean)->not->toContain('eyJ');
});

it('leaves no token text in the database statements or the log after a run (REQ-CRED-001)', function (int $status) {
	$token   = $this->scratch->file('token', HPCSCHED_FIXTURE_TOKEN, 0640);
	$cluster = new Cluster(1, 'demo', 1, AdminState::Enabled, Transport::Rest, 60, restUrl: 'https://slurm.example.org:6820', restUser: 'cactiread', tokenFile: $token);
	$body    = '{"errors":[{"error":"bad token ' . HPCSCHED_FIXTURE_TOKEN . '"}],"meta":{}}';
	$http    = new StubHttp(['/jobs/' => new HttpResponse($status, [], $body), '/nodes/' => new HttpResponse($status, [], $body), '/partitions/' => new HttpResponse($status, [], $body)]);
	$clock   = new FixedClock(100000);

	$GLOBALS['__hpcsched_db_rows'] = ['FROM plugin_hpcsched_run WHERE cluster_id = ? AND holder' => [['c' => 1]]];

	$run = (new Reader(new RunRepository(false, $clock), new SnapshotRepository(false), new Transports(Settings::fromRow([]), new CommandRunner(), $http), $clock, 'test'))->run($cluster, 60);

	$written = json_encode($GLOBALS['__hpcsched_db_exec']) . json_encode($GLOBALS['__hpcsched_log']);

	expect($http->requests[0]->headers['X-SLURM-USER-TOKEN'])->toBe(HPCSCHED_FIXTURE_TOKEN)
		->and($run->tokenState)->toBe(TokenState::GroupReadable)
		->and($run->tokenExpires)->toBe(4102444800)
		->and($written)->not->toContain(HPCSCHED_FIXTURE_TOKEN)
		->and($written)->not->toContain('eyJhbGciOiJIUzI1NiJ9')
		->and($written)->not->toContain('Zml4dHVyZS1zaWduYXR1cmU');
})->with([200, 401, 500]);
