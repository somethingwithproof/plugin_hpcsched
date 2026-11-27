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

/*
 * Operator and Configuration-as-Code CLI for HPC Scheduler:
 *
 *   cli/hpcsched.php export [--format=yaml|xml] [--file=PATH]
 *   cli/hpcsched.php validate --file=PATH [--format=yaml|xml]
 *   cli/hpcsched.php plan --file=PATH [--format=yaml|xml]
 *   cli/hpcsched.php apply --file=PATH --confirm=<PLAN_HASH> [--format=yaml|xml]
 *   cli/hpcsched.php health [--format=text|json]
 *   cli/hpcsched.php sync-maint [--dry-run | --apply] [--cluster=ID] [--format=text|json]
 *
 * Must parse on PHP 8.0 and refuses runtimes below PHP 8.2.
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$dir = __DIR__;
chdir($dir . '/../../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/hpcsched/setup.php');

if (hpcsched_refuse_unsupported()) {
	exit(1);
}

hpcsched_boot();

$hpcsched_cli_usage = 'usage: cli/hpcsched.php export [--format=yaml|xml] [--file=PATH]' . PHP_EOL
	. '       cli/hpcsched.php validate --file=PATH [--format=yaml|xml]' . PHP_EOL
	. '       cli/hpcsched.php plan --file=PATH [--format=yaml|xml]' . PHP_EOL
	. '       cli/hpcsched.php apply --file=PATH --confirm=PLAN_HASH [--format=yaml|xml]' . PHP_EOL
	. '       cli/hpcsched.php health [--format=text|json]' . PHP_EOL
	. '       cli/hpcsched.php sync-maint [--dry-run | --apply] [--cluster=ID] [--format=text|json]' . PHP_EOL;

$hpcsched_args = array_slice($_SERVER['argv'], 1);

if ($hpcsched_args === array()) {
	fwrite(STDERR, $hpcsched_cli_usage);
	exit(1);
}

$hpcsched_cmd  = array_shift($hpcsched_args);
$hpcsched_opts = array();

foreach ($hpcsched_args as $arg) {
	if (strpos($arg, '--') !== 0) {
		fwrite(STDERR, 'ERROR: Unexpected positional argument' . PHP_EOL . $hpcsched_cli_usage);
		exit(1);
	}

	$eq = strpos($arg, '=');

	if ($eq === false) {
		$hpcsched_opts[substr($arg, 2)] = '1';
	} else {
		$hpcsched_opts[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
	}
}

if ($hpcsched_cmd === 'health') {
	$fmt = isset($hpcsched_opts['format']) ? $hpcsched_opts['format'] : 'text';

	if ($fmt !== 'text' && $fmt !== 'json') {
		fwrite(STDERR, 'ERROR: --format must be text or json' . PHP_EOL . $hpcsched_cli_usage);
		exit(2);
	}

	$report = hpcsched_health_evaluator()->evaluate();

	if ($fmt === 'json') {
		$encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		fwrite(STDOUT, (string) $encoded . PHP_EOL);
	} else {
		$summary = $report['summary'];
		fwrite(STDOUT, sprintf('Status: %s (schema v%d, clusters %d/%d enabled, remote %d, stale %d, failed %d, maint links %d)' . PHP_EOL, $report['status'], $report['schema_version'], $summary['enabled_clusters'], $summary['clusters'], $summary['remote_clusters'], $summary['stale_clusters'], $summary['failed_clusters'], $report['maint_links']));

		foreach ($report['clusters'] as $row) {
			fwrite(STDOUT, sprintf('  - %s [%s poller=%d]: %s (freshness=%s outcome=%s)' . PHP_EOL, $row['name'], $row['transport'], $row['poller_id'], $row['status'], $row['freshness'], $row['outcome']));
		}

		foreach ($report['warnings'] as $warn) {
			fwrite(STDOUT, 'WARNING: ' . $warn . PHP_EOL);
		}
	}

	exit($report['status'] === 'fail' ? 1 : 0);
}

if ($hpcsched_cmd === 'sync-maint') {
	$doApply = isset($hpcsched_opts['apply']);
	$dryRun  = isset($hpcsched_opts['dry-run']) || !$doApply;

	if ($doApply && isset($hpcsched_opts['dry-run'])) {
		fwrite(STDERR, 'ERROR: specify either --dry-run or --apply, not both' . PHP_EOL . $hpcsched_cli_usage);
		exit(2);
	}

	$clusterId = null;

	if (isset($hpcsched_opts['cluster'])) {
		if (!ctype_digit($hpcsched_opts['cluster']) || (int) $hpcsched_opts['cluster'] < 1) {
			fwrite(STDERR, 'ERROR: --cluster must be a positive integer' . PHP_EOL);
			exit(2);
		}

		$clusterId = (int) $hpcsched_opts['cluster'];
	}

	$fmt = isset($hpcsched_opts['format']) ? $hpcsched_opts['format'] : 'text';

	if ($fmt !== 'text' && $fmt !== 'json') {
		fwrite(STDERR, 'ERROR: --format must be text or json' . PHP_EOL . $hpcsched_cli_usage);
		exit(2);
	}

	$sync = hpcsched_maint_synchronizer();

	if ($dryRun) {
		$plan = $sync->plan($clusterId);

		if ($fmt === 'json') {
			$encoded = json_encode(array(
				'mode'            => 'dry-run',
				'plan_id'         => $plan->id(),
				'maint_available' => $plan->maintAvailable,
				'warnings'        => $plan->warnings,
				'actions'         => array_map(static function (\Somethingwithproof\Hpcsched\MaintAction $a): array {
					return $a->toArray();
				}, $plan->actions),
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			fwrite(STDOUT, (string) $encoded . PHP_EOL);
		} else {
			foreach ($plan->lines() as $line) {
				fwrite(STDOUT, $line . PHP_EOL);
			}

			fwrite(STDOUT, 'Plan ' . $plan->id() . ($plan->isEmpty() ? ': no maintenance changes needed' : ': dry run; pass --apply to synchronize') . PHP_EOL);
		}

		exit(0);
	}

	$outcome = $sync->apply($clusterId);

	if ($fmt === 'json') {
		$encoded = json_encode(array(
			'mode'    => 'apply',
			'plan_id' => $outcome['plan']->id(),
			'applied' => $outcome['applied'],
			'errors'  => $outcome['errors'],
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		fwrite(STDOUT, (string) $encoded . PHP_EOL);
	} else {
		foreach ($outcome['plan']->lines() as $line) {
			fwrite(STDOUT, $line . PHP_EOL);
		}

		foreach ($outcome['errors'] as $err) {
			fwrite(STDERR, 'ERROR: ' . $err . PHP_EOL);
		}

		fwrite(STDOUT, sprintf('Applied %d maintenance changes (plan %s)' . PHP_EOL, $outcome['applied'], $outcome['plan']->id()));
	}

	exit($outcome['errors'] === array() ? 0 : 1);
}

if (!in_array($hpcsched_cmd, array('export', 'validate', 'plan', 'apply'), true)) {
	fwrite(STDERR, 'ERROR: Unknown command' . PHP_EOL . $hpcsched_cli_usage);
	exit(1);
}

$format  = isset($hpcsched_opts['format']) ? $hpcsched_opts['format'] : \Somethingwithproof\Hpcsched\ConfigCodec::YAML;
$file    = isset($hpcsched_opts['file']) ? $hpcsched_opts['file'] : '';
$confirm = isset($hpcsched_opts['confirm']) ? $hpcsched_opts['confirm'] : '';

if (!\Somethingwithproof\Hpcsched\ConfigCodec::isFormat($format)) {
	fwrite(STDERR, 'ERROR: --format must be yaml or xml' . PHP_EOL . $hpcsched_cli_usage);
	exit(1);
}

$service = hpcsched_config_service();

try {
	if ($hpcsched_cmd === 'export') {
		$rendered = $service->export($format);

		if ($file === '' || $file === '-') {
			fwrite(STDOUT, $rendered);
			exit(0);
		}

		$handle = @fopen($file, 'x');

		if ($handle === false || fwrite($handle, $rendered) === false || !fclose($handle) || !chmod($file, 0600)) {
			fwrite(STDERR, 'ERROR: Cannot create ' . $file . ' (must not already exist)' . PHP_EOL);
			exit(1);
		}

		fwrite(STDOUT, 'Exported to ' . $file . PHP_EOL);
		exit(0);
	}

	if ($file === '') {
		fwrite(STDERR, 'ERROR: --file=PATH is required' . PHP_EOL . $hpcsched_cli_usage);
		exit(1);
	}

	$raw  = $file === '-' ? \Somethingwithproof\Hpcsched\ConfigCodec::readStream(STDIN) : \Somethingwithproof\Hpcsched\ConfigCodec::readFile($file);
	$tree = \Somethingwithproof\Hpcsched\ConfigCodec::parse($format, $raw);

	if ($hpcsched_cmd === 'validate') {
		$errs = $service->validate($tree);

		foreach ($errs as $err) {
			fwrite(STDERR, 'ERROR: ' . $err . PHP_EOL);
		}

		if ($errs === array()) {
			fwrite(STDOUT, 'Valid' . PHP_EOL);
			exit(0);
		}

		exit(2);
	}

	if ($hpcsched_cmd === 'plan') {
		$plan = $service->plan($tree);

		foreach ($plan->lines() as $line) {
			fwrite(STDOUT, $line . PHP_EOL);
		}

		$statusLine = $plan->hasErrors()
			? 'not applicable'
			: ($plan->isEmpty() ? 'no changes' : 'dry run; apply with --confirm=' . $plan->id());
		fwrite(STDOUT, 'Plan ' . $plan->id() . ': ' . $statusLine . PHP_EOL);
		exit($plan->hasErrors() ? 2 : 0);
	}

	if ($confirm === '') {
		fwrite(STDERR, 'ERROR: apply requires --confirm=PLAN_HASH' . PHP_EOL);
		exit(1);
	}

	$refusals = $service->apply($tree, $confirm);

	foreach ($refusals as $refusal) {
		fwrite(STDERR, 'ERROR: ' . $refusal . PHP_EOL);
	}

	if ($refusals === array()) {
		fwrite(STDOUT, 'Applied plan ' . $confirm . PHP_EOL);
		exit(0);
	}

	exit(1);
} catch (\Somethingwithproof\Hpcsched\ConfigException $e) {
	fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
	exit(2);
}
