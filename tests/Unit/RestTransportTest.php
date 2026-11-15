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

require_once dirname(__DIR__) . '/Support/FakeRestd.php';

use Somethingwithproof\Hpcsched\Endpoint;
use Somethingwithproof\Hpcsched\ErrorClass;
use Somethingwithproof\Hpcsched\RestTransport;
use Somethingwithproof\Hpcsched\Secret;
use Somethingwithproof\Hpcsched\Section;
use Somethingwithproof\Hpcsched\SlurmJson;
use Somethingwithproof\Hpcsched\SocketTransport;
use Somethingwithproof\Hpcsched\Tests\FakeRestd;
use Somethingwithproof\Hpcsched\Token;
use Somethingwithproof\Hpcsched\TokenState;
use Somethingwithproof\Hpcsched\TransportError;

/*
 * The slurmrestd transport against tests/Support/fake-slurmrestd.php on a
 * loopback port; no slurmrestd is involved.
 */

const HPCSCHED_TEST_TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjQxMDI0NDQ4MDAsInN1biI6ImNhY3RpIn0.c2lnbmF0dXJl';

function hpcsched_rest(FakeRestd $server, string $scenario = 'ok', int $maxBytes = 8388608, ?string $ca = null): RestTransport {
	return new RestTransport(new SocketTransport($maxBytes, 2.0, $ca ?? ($server->mode === 'tls' ? $server->ca() : null)), Endpoint::parse($server->url()), $scenario, new Token(new Secret(HPCSCHED_TEST_TOKEN), TokenState::Ok, 0), 5.0);
}

function hpcsched_rest_error(callable $call): ?ErrorClass {
	try {
		$call();
	} catch (TransportError $e) {
		return $e->errorClass;
	}

	return null;
}

beforeAll(function () {
	$GLOBALS['__hpcsched_tls']   = new FakeRestd('tls');
	$GLOBALS['__hpcsched_plain'] = new FakeRestd('plain');
});

afterAll(function () {
	$GLOBALS['__hpcsched_tls']->stop();
	$GLOBALS['__hpcsched_plain']->stop();
});

it('reads every section over verified TLS with the token in its header only', function () {
	$server    = $GLOBALS['__hpcsched_tls'];
	$transport = hpcsched_rest($server);

	foreach (Section::cases() as $section) {
		expect(SlurmJson::release(SlurmJson::decode($transport->fetch($section, 'v0.0.43', microtime(true) + 10))))->toBe('25.11.2');
	}

	$requests = array_slice($server->requests(), -5);

	expect(array_column($requests, 'target'))->toBe(['/slurm/v0.0.43/jobs/', '/slurm/v0.0.43/nodes/', '/slurm/v0.0.43/partitions/', '/slurm/v0.0.43/reservations/', '/slurm/v0.0.43/shares'])
		->and(array_unique(array_column($requests, 'method')))->toBe(['GET'])
		->and(array_unique(array_column($requests, 'token_sha')))->toBe([hash('sha256', HPCSCHED_TEST_TOKEN)])
		->and($transport->calls())->toBe(5);
});

it('reads a loopback slurmrestd over plain HTTP', function () {
	$json = hpcsched_rest($GLOBALS['__hpcsched_plain'])->fetch(Section::Nodes, 'v0.0.43', microtime(true) + 10);

	expect(count(SlurmJson::decode($json)['nodes']))->toBe(8);
});

it('maps each failure answer to its class, never to empty data', function (string $scenario, ErrorClass $class) {
	expect(hpcsched_rest_error(fn () => hpcsched_rest($GLOBALS['__hpcsched_tls'], $scenario)->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 10)))->toBe($class);
})->with([
	'304 without a conditional request' => ['notmodified', ErrorClass::NotModified],
	'401'                              => ['unauth', ErrorClass::Credential],
	'403'                              => ['forbidden', ErrorClass::Access],
	'500'                              => ['error', ErrorClass::Failed],
	'429'                              => ['ratelimit', ErrorClass::RateLimited],
	'redirect not followed'            => ['redirect', ErrorClass::Refused],
]);

it('hands a malformed body to the parser, which fails the section', function () {
	$body = hpcsched_rest($GLOBALS['__hpcsched_tls'], 'malformed')->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 10);

	expect(hpcsched_rest_error(fn () => SlurmJson::decode($body)))->toBe(ErrorClass::Malformed);
});

it('discards an answer larger than allowed', function () {
	expect(hpcsched_rest_error(fn () => hpcsched_rest($GLOBALS['__hpcsched_tls'], 'big', 1048576)->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 10)))->toBe(ErrorClass::TooLarge);
});

it('stops a slow answer at the deadline', function () {
	$started = microtime(true);

	expect(hpcsched_rest_error(fn () => hpcsched_rest($GLOBALS['__hpcsched_tls'], 'slow')->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 1.5)))->toBe(ErrorClass::Timeout)
		->and(microtime(true) - $started)->toBeLessThan(3.0);
});

it('refuses a certificate it does not trust or that names another host', function (string $pem) {
	$bad = new FakeRestd('tls', $pem);

	try {
		$error = hpcsched_rest_error(fn () => hpcsched_rest($bad, 'ok', 8388608, $bad->ca())->fetch(Section::Jobs, 'v0.0.43', microtime(true) + 5));
	} finally {
		$bad->stop();
	}

	expect($error)->toBe(ErrorClass::Unreachable);
})->with(['selfsigned.pem', 'wrongname.pem']);

it('allows only the read paths, never reconfigure, requeue, or a query string', function (string $path, bool $allowed) {
	expect(RestTransport::allowed($path, 'v0.0.43'))->toBe($allowed);
})->with([
	['/slurm/v0.0.43/jobs/', true],
	['/slurm/v0.0.43/shares', true],
	['/slurm/v0.0.43/reconfigure/', false],
	['/slurm/v0.0.43/job/1/requeue', false],
	['/slurm/v0.0.43/jobs/?update_time=1', false],
	['/slurm/v0.0.43/job/submit', false],
	['/slurmdb/v0.0.43/jobs/', false],
	['/slurm/v0.0.42/jobs/', false],
]);

it('sends no update_time and no request outside the allowlist', function () {
	foreach (array_merge($GLOBALS['__hpcsched_tls']->requests(), $GLOBALS['__hpcsched_plain']->requests()) as $request) {
		expect(RestTransport::allowed($request['target'], 'v0.0.43'))->toBeTrue($request['target'])
			->and($request['method'])->toBe('GET');
	}
});

it('accepts https anywhere and plain http only to loopback', function (string $url, bool $ok) {
	expect(hpcsched_rest_error(fn () => Endpoint::parse($url)) === null)->toBe($ok);
})->with([
	['https://slurm.example.org:6820', true],
	['https://slurm.example.org/api', true],
	['http://127.0.0.1:6820', true],
	['http://localhost:6820', true],
	['http://[::1]:6820', true],
	['http://slurm.example.org:6820', false],
	['http://10.0.0.5:6820', false],
	['https://user:pw@slurm.example.org', false],
	['https://slurm.example.org/?x=1', false],
	['https://slurm.example.org/#x', false],
	['ftp://slurm.example.org', false],
	['https://slurm example.org', false],
	['', false],
]);
