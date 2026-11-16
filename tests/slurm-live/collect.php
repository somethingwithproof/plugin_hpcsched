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


// Test code: refuse to run from a web request.
PHP_SAPI === 'cli' || exit(1);

/*
 * Collects a snapshot from the live CI cluster with the plugin's own
 * transports, built by Transports::for() from a cluster record as the
 * reader builds them, and run through SlurmBackend, the collection step
 * of the reader. The client commands are tests/slurm-live/bin (docker exec
 * into slurmctld); slurmrestd is reached on loopback with the token in a
 * token file, as an operator would set it up. The reader's database write
 * is not part of this check; the Cacti replays cover it.
 *
 * Each snapshot must complete every section and parse to the same jobs,
 * nodes, partitions, and reservations as fetch.sh's captures of the same
 * data parser, taken just before.
 *
 *   php tests/slurm-live/collect.php <plugin root> <fetch output directory> <token file>
 */

[, $root, $dir, $tokenFile] = $argv + [null, null, null, null];

if (!is_string($root) || !is_string($dir) || !is_string($tokenFile) || !is_dir($dir)) {
	fwrite(STDERR, "usage: php tests/slurm-live/collect.php <plugin root> <fetch output directory> <token file>\n");
	exit(2);
}

require $root . '/include/autoload.php';

const POLLER_VERBOSITY_MEDIUM = 3;
const POLLER_VERBOSITY_DEBUG  = 5;

if (!function_exists('__')) {
	function __(string $text, mixed ...$args): string {
		return $text;
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log(string $message, bool $stdout = false, string $tag = '', int $level = 0): void {
		fwrite(STDERR, "$tag $message\n");
	}
}

use Somethingwithproof\Hpcsched\Cluster;
use Somethingwithproof\Hpcsched\ClusterRun;
use Somethingwithproof\Hpcsched\CommandRunner;
use Somethingwithproof\Hpcsched\DataParser;
use Somethingwithproof\Hpcsched\FreshnessEvaluator;
use Somethingwithproof\Hpcsched\NodeRecord;
use Somethingwithproof\Hpcsched\Redactor;
use Somethingwithproof\Hpcsched\RestTransport;
use Somethingwithproof\Hpcsched\RunOutcome;
use Somethingwithproof\Hpcsched\Section;
use Somethingwithproof\Hpcsched\SectionRecord;
use Somethingwithproof\Hpcsched\SectionState;
use Somethingwithproof\Hpcsched\Settings;
use Somethingwithproof\Hpcsched\SlurmBackend;
use Somethingwithproof\Hpcsched\SlurmJson;
use Somethingwithproof\Hpcsched\SlurmParser;
use Somethingwithproof\Hpcsched\SlurmTransport;
use Somethingwithproof\Hpcsched\Snapshot;
use Somethingwithproof\Hpcsched\SocketTransport;
use Somethingwithproof\Hpcsched\SystemClock;
use Somethingwithproof\Hpcsched\Transports;
use Somethingwithproof\Hpcsched\Unchanged;

$failed = 0;
$check  = function (string $label, bool $pass, mixed $got = null) use (&$failed): void {
	$failed += $pass ? 0 : 1;
	printf("%s %s%s\n", $pass ? 'ok  ' : 'FAIL', $label, $pass ? '' : ' got ' . json_encode($got));
};

$load  = static fn (string $file): array => SlurmJson::decode((string) file_get_contents("$dir/$file"));
$nodes = static function (?array $records): array {
	$out = [];

	foreach ($records ?? [] as $node) {
		if ($node instanceof NodeRecord) {
			$flags = $node->flags;
			sort($flags);
			$out[$node->name] = [$node->state->value, $flags, $node->reason, $node->partitions];
		}
	}

	ksort($out);

	return $out;
};
// Slurm 25.11 adds the maintenance reservation's flags to a node some
// seconds after the reservation starts (assert.sh tolerates the same), so
// the snapshot, taken after the capture, may carry those and only those.
$sameNodes = static function (array $got, array $captured): bool {
	if (array_keys($got) !== array_keys($captured)) {
		return false;
	}

	foreach ($got as $name => [$state, $flags, $reason, $partitions]) {
		[$cState, $cFlags, $cReason, $cPartitions] = $captured[$name];
		$late = array_diff($flags, $cFlags);

		if ($reason !== $cReason || $partitions !== $cPartitions || array_diff($cFlags, $flags) !== []
			|| array_diff($late, ['MAINTENANCE', 'PLANNED', 'RESERVED']) !== []
			|| ($state !== $cState && ($late === [] || $state !== 'maintenance'))) {
			return false;
		}
	}

	return true;
};
$names = static function (?array $records): array {
	$out = [];

	foreach ($records ?? [] as $record) {
		$out[] = $record->name . (property_exists($record, 'totalNodes') ? ':' . $record->totalNodes : '');
	}

	sort($out);

	return $out;
};

$rest    = array_filter(array_map('trim', (array) @file("$dir/rest-parsers.txt")));
$parsers = array_values(array_filter(array_map('trim', (array) file("$dir/parsers.txt")), [DataParser::class, 'supported']));
$bin     = $root . '/tests/slurm-live/bin';

$check('the release serves a data parser the plugin supports', $parsers !== [], $parsers);

$cluster = static fn (string $transport): ?Cluster => Cluster::fromRow([
	'id' => 1, 'name' => 'live', 'poller_id' => 1, 'admin_state' => 'enabled', 'transport' => $transport, 'collect_interval' => 60,
	'rest_url' => 'http://127.0.0.1:6820', 'rest_user' => 'root', 'token_file' => $tokenFile, 'collect_reservations' => 1, 'collect_shares' => 1,
]);

// Build the real transport with the same settings and parser cache as the reader.
$transportFor = static function (string $transport, string $parser, ?ClusterRun $previous = null) use ($bin, $tokenFile, $cluster): SlurmTransport {
	$settings = Settings::fromRow([
		'hpcsched_squeue'      => "$bin/squeue",
		'hpcsched_scontrol'    => "$bin/scontrol",
		'hpcsched_sshare'      => "$bin/sshare",
		// prlimit would limit docker, not the client in the container.
		'hpcsched_prlimit'     => '',
		'hpcsched_data_parser' => $parser,
		'hpcsched_token_dirs'  => dirname($tokenFile),
	]);
	$record = $cluster($transport);
	$token  = null;

	if ($record === null) {
		throw new RuntimeException('cluster record');
	}

	return (new Transports($settings, new CommandRunner(), new SocketTransport($settings->maxOutputBytes())))->for($record, new Redactor(), $token, $previous);
};

/** @param array<string, SectionRecord> $sections */
$collect = static function (string $transport, string $parser, ?ClusterRun $previous = null, array $sections = [], string $release = '') use ($transportFor, $cluster): Snapshot {
	$for = $transportFor($transport, $parser, $previous);

	return (new SlurmBackend($for, new SystemClock(), $sections, $release))->collect($cluster($transport), microtime(true) + 120);
};

$compare = static function (string $label, Snapshot $snapshot, string $parser) use ($check, $load, $nodes, $names, $sameNodes): void {
	foreach (Section::cases() as $section) {
		$outcome = $snapshot->outcome($section);
		$check("$label $section->value completed", $outcome->state === SectionState::Completed, $outcome->error?->value);
	}

	$check("$label data parser", $snapshot->dataParser === $parser, $snapshot->dataParser);
	$check("$label jobs match the capture", $snapshot->jobs?->rows() === SlurmParser::jobs($load("squeue.$parser.json"))->rows(), $snapshot->jobs?->rows());
	$check("$label nodes match the capture", $sameNodes($nodes($snapshot->nodes), $nodes(SlurmParser::nodes($load("scontrol-node.$parser.json")))), $nodes($snapshot->nodes));
	$check("$label partitions match the capture", $names($snapshot->partitions) === $names(SlurmParser::partitions($load("scontrol-partition.$parser.json"))), $names($snapshot->partitions));
	$check("$label reservations match the capture", $names($snapshot->reservations) === $names(SlurmParser::reservations($load("scontrol-reservation.$parser.json"))), $names($snapshot->reservations));
	$check("$label fair-share rows", count($snapshot->shares ?? []) >= 3, count($snapshot->shares ?? []));
};

// Every served parser through each transport. A slurmrestd run is given
// the parser as the one its last completed run used (with no release, so
// the cache is kept), so every served parser is read, not just the newest.
foreach ($parsers as $parser) {
	$compare("$parser commands", $collect('commands', $parser), $parser);

	if (in_array($parser, $rest, true)) {
		$compare("$parser rest", $collect('rest', $parser, new ClusterRun(1, outcome: RunOutcome::Completed, transport: 'rest', dataParser: $parser)), $parser);
	}
}

// Discovery (REQ-REST-002): with no previous run, the newest supported
// parser the daemon serves.
$newest = null;

foreach (DataParser::newestFirst() as $version) {
	if (in_array($version, $rest, true)) {
		$newest = $version;

		break;
	}
}

$check('slurmrestd serves a data parser the plugin supports', $newest !== null, $rest);

if ($newest !== null) {
	$found = $collect('rest', DataParser::DEFAULT_VERSION);

	$compare("discovered $newest rest", $found, $newest);
	$check('the discovered parser is the newest one the release ships', $found->dataParser === DataParser::newestShippedBy($found->release), [$found->release, $found->dataParser]);

	// The normal conditional read keeps the production skew allowance.
	// Controller updates inside that window legitimately return 200, even
	// when the node states visible here have not changed.
	$conditional = DataParser::atLeast($found->release, RestTransport::CONDITIONAL_NODES_FROM);
	$previous    = new ClusterRun(1, outcome: RunOutcome::Completed, release: $found->release, transport: 'rest', dataParser: $found->dataParser);
	$whole       = $collect('rest', DataParser::DEFAULT_VERSION, $previous);
	$sections    = ['nodes' => new SectionRecord(1, Section::Nodes, SectionState::Completed, null, $whole->startedAt, count($whole->nodes ?? []), $whole->startedAt, time(), 60)];
	$again       = $collect('rest', DataParser::DEFAULT_VERSION, $previous, $sections, $found->release);
	$outcome     = $again->outcome(Section::Nodes);
	$unchanged   = $outcome->unchanged;
	$kept        = $again->nodes === null && $outcome->records === count($whole->nodes ?? []);
	$refreshed   = $sameNodes($nodes($again->nodes), $nodes($whole->nodes));

	if ($conditional) {
		$check("$found->release conditional nodes read retains or refreshes the rows", $outcome->state === SectionState::Completed && ($unchanged ? $kept : $refreshed), [$outcome->state->value, $unchanged, $outcome->error?->value]);

		// Exercise the real 304 response separately with a cutoff ahead of
		// the controller clock. This protocol probe is never persisted as a
		// snapshot. ReaderTest covers preserving stored rows on that response;
		// the normal collection above never advances its cutoff this way.
		$transport = $transportFor('rest', DataParser::DEFAULT_VERSION, $previous);
		$notModified = false;

		try {
			$transport->fetch(Section::Nodes, $found->dataParser, microtime(true) + 30, time() + FreshnessEvaluator::SKEW_ALLOWANCE);
		} catch (Unchanged) {
			$notModified = true;
		}

		$check("$found->release maps a live HTTP 304 to Unchanged", $notModified);
	} else {
		$check("$found->release reads nodes whole, with no update_time", !$unchanged && $outcome->state === SectionState::Completed && $sameNodes($nodes($again->nodes), $nodes($whole->nodes)), [$outcome->state->value, $outcome->unchanged]);
	}
}

exit($failed === 0 ? 0 : 1);
