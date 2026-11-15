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
use Somethingwithproof\Hpcsched\ClusterRun;
use Somethingwithproof\Hpcsched\ErrorClass;
use Somethingwithproof\Hpcsched\Freshness;
use Somethingwithproof\Hpcsched\HealthWarnings;
use Somethingwithproof\Hpcsched\RunOutcome;
use Somethingwithproof\Hpcsched\Settings;
use Somethingwithproof\Hpcsched\TokenState;
use Somethingwithproof\Hpcsched\Transport;

function hpcsched_warnings(Cluster $cluster, ?ClusterRun $run, Freshness $freshness = Freshness::Current, int $poller = 60): string {
	return implode("\n", HealthWarnings::for($cluster, $run, $freshness, Settings::fromRow([]), $poller, 100000));
}

function hpcsched_rest_config(string $url = 'https://slurm.example.org'): Cluster {
	return new Cluster(1, 'demo', 1, AdminState::Enabled, Transport::Rest, 60, restUrl: $url, tokenFile: '/etc/cacti/hpcsched/token');
}

it('is quiet for a healthy cluster', function () {
	$run = new ClusterRun(1, finishedAt: 99990, outcome: RunOutcome::Completed, release: '25.11.2', memoryLimited: true, tokenState: TokenState::Ok);

	expect(HealthWarnings::for(hpcsched_rest_config(), $run, Freshness::Current, Settings::fromRow([]), 60, 100000))->toBe([]);
});

it('warns about a token file other users can read (REQ-CRED-003)', function (TokenState $state, string $text) {
	expect(hpcsched_warnings(hpcsched_rest_config(), new ClusterRun(1, outcome: RunOutcome::Completed, release: '25.11.2', tokenState: $state)))->toContain($text);
})->with([
	[TokenState::GroupReadable, 'readable by the file group'],
	[TokenState::WorldReadable, 'readable by every user'],
	[TokenState::Owner, 'owned by a user other'],
	[TokenState::Missing, 'missing'],
]);

it('warns when the token is within its expiry window or expired', function () {
	expect(hpcsched_warnings(hpcsched_rest_config(), new ClusterRun(1, outcome: RunOutcome::Completed, release: '25.11.2', tokenState: TokenState::Ok, tokenExpires: 100000 + 600)))->toContain('expires in 10 minutes')
		->and(hpcsched_warnings(hpcsched_rest_config(), new ClusterRun(1, outcome: RunOutcome::Completed, release: '25.11.2', tokenState: TokenState::Ok, tokenExpires: 99000)))->toContain('expired')
		->and(hpcsched_warnings(hpcsched_rest_config(), new ClusterRun(1, outcome: RunOutcome::Completed, release: '25.11.2', tokenState: TokenState::Ok, tokenExpires: 100000 + 86400)))->not->toContain('expire');
});

it('warns about an interval below the poller, an overrun, an unsupported release, and no memory limit', function () {
	$commands = new Cluster(1, 'demo', 1, AdminState::Enabled, Transport::Commands, 60);
	$text     = hpcsched_warnings($commands, new ClusterRun(1, outcome: RunOutcome::Budget, release: '24.11.6', overran: true, memoryLimited: false, error: ErrorClass::Timeout), Freshness::Stale, 300);

	expect($text)->toContain('shorter than the poller interval of 300 seconds')
		->and($text)->toContain('whole time budget of 150 seconds')
		->and($text)->toContain('Slurm 24.11.6, which is not a supported release (25.05, 25.11, 26.05)')
		->and($text)->toContain('without a memory limit')
		->and($text)->toContain('took longer than allowed')
		->and($text)->toContain('stale');
});

it('warns when a cluster reports a release that does not ship the chosen data parser', function () {
	$run = static fn (string $release): ClusterRun => new ClusterRun(1, outcome: RunOutcome::Completed, release: $release, memoryLimited: true, tokenState: TokenState::Ok);
	$for = static fn (string $parser, string $release): string => implode("\n", HealthWarnings::for(hpcsched_rest_config(), $run($release), Freshness::Current, Settings::fromRow(['hpcsched_data_parser' => $parser]), 60, 100000));

	expect($for('v0.0.45', '25.11.2'))->toContain('Slurm 25.11.2, which does not ship the data parser v0.0.45')
		->and($for('v0.0.44', '25.05.4'))->toContain('does not ship the data parser v0.0.44')
		->and($for('v0.0.44', '25.11.2'))->toBe('')
		->and($for('v0.0.45', '26.05.1'))->toBe('')
		->and($for('v0.0.43', '25.05.4'))->toBe('')
		->and($for('v0.0.45', '24.11.6'))->not->toContain('does not ship');
});

it('flags plain HTTP to a loopback slurmrestd, and a cluster never collected', function () {
	expect(hpcsched_warnings(hpcsched_rest_config('http://127.0.0.1:6820'), null))->toContain('plain HTTP')
		->and(hpcsched_warnings(hpcsched_rest_config(), null))->toContain('No reader run');
});
