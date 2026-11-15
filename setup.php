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
 * Cacti includes this file on every request, on whatever PHP version the site
 * runs. It must parse on PHP 8.0 (CI lints it there), and nothing under lib/
 * is loaded until hpcsched_boot() has confirmed PHP 8.2 or later (ADR 0006).
 * Every hook and lifecycle function therefore starts with that check, so an
 * unsupported runtime gets a refusal instead of a parse error that would take
 * down the whole Cacti UI (REQ-COMPAT-001 to REQ-COMPAT-003).
 */

function hpcsched_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

function hpcsched_boot(): bool {
	if (!hpcsched_php_supported()) {
		return false;
	}

	require_once(__DIR__ . '/include/autoload.php');

	return true;
}

/**
 * Refuse with a message naming the required version.
 *
 * Used by the lifecycle functions only. Hook shims return silently instead,
 * because they run on every page and a refused plugin must not flood the log.
 */
function hpcsched_refuse_unsupported(): bool {
	if (hpcsched_php_supported()) {
		return false;
	}

	$message = __('The HPC Scheduler plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.', '8.2.0', PHP_VERSION, 'hpcsched');

	cacti_log('ERROR: ' . $message, false, 'HPCSCHED');

	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('hpcsched_php', $message, MESSAGE_LEVEL_ERROR);
	}

	return true;
}

/**
 * @return array<string, string>
 */
function plugin_hpcsched_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	$version = array();

	foreach ($info['info'] as $key => $value) {
		$version[(string) $key] = is_scalar($value) ? (string) $value : '';
	}

	return $version;
}

function plugin_hpcsched_install(): void {
	if (hpcsched_refuse_unsupported()) {
		return;
	}

	plugin_hpcsched_upgrade(true);
}

function plugin_hpcsched_check_config(): bool {
	if (hpcsched_refuse_unsupported()) {
		return false;
	}

	return plugin_hpcsched_upgrade(true);
}

/**
 * Common start of every page script, after include/auth.php has authorized
 * the realm. Refuses on an unsupported runtime or a disabled plugin, because
 * Cacti keeps plugin realms registered while a plugin is disabled.
 */
function hpcsched_page_start(): bool {
	if (hpcsched_boot() && api_plugin_is_enabled('hpcsched')) {
		return true;
	}

	top_header();

	html_start_box(__('HPC Scheduler', 'hpcsched'), '100%', '', '3', 'center', '');

	print '<tr><td>' . html_escape(hpcsched_php_supported() ? __('The HPC Scheduler plugin is not enabled.', 'hpcsched') : __('The HPC Scheduler plugin requires PHP %s or later.', '8.2.0', 'hpcsched')) . '</td></tr>';

	html_end_box();

	bottom_footer();

	return false;
}

/**
 * State-changing actions are accepted only as POST. A GET that names one is
 * sent back to the page without effect; csrf-magic checks the token of every
 * POST.
 *
 * @param list<string> $actions
 */
function hpcsched_require_post(string $page, array $actions): void {
	$action = (string) get_nfilter_request_var('action');

	if (in_array($action, $actions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Location: ' . $page);
		exit;
	}
}

function hpcsched_settings(): \Somethingwithproof\Hpcsched\Settings {
	return (new \Somethingwithproof\Hpcsched\SettingsRepository(false))->load();
}

/**
 * Bring the schema, hooks, and realms up to the code's version.
 *
 * Cacti 1.2.31 never calls this itself; check_config and the config_arrays
 * hook do. Fresh installs take the same path as upgrades. The version stored
 * in plugin_config changes only after every migration has succeeded, so an
 * interrupted run is finished by the next one (REQ-LIFE-002, REQ-LIFE-003).
 *
 * $grant gives newly registered realms to the current user. Only install and
 * check_config pass it: both run from the Plugins page, which needs plugin
 * management. A repair triggered from config_arrays can run for anyone who
 * opens a plugin page, so it must never grant anything.
 */
function plugin_hpcsched_upgrade(bool $grant = false): bool {
	if (!hpcsched_boot()) {
		return false;
	}

	$info   = plugin_hpcsched_version();
	$schema = new \Somethingwithproof\Hpcsched\Schema(false);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === $info['version'] && $schema->hooksRegistered()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	$schema->trackTables();

	hpcsched_setup_hooks($grant);

	$schema->recordPluginVersion($info['version'], $info['author'], $info['homepage']);

	return true;
}

/**
 * Register hooks and realms (REQ-LIFE-001).
 *
 * Kept procedural and named *_setup_* because api_plugin_register_hook() and
 * api_plugin_register_realm() refuse callers whose function name does not
 * contain install, upgrade, or setup.
 */
function hpcsched_setup_hooks(bool $grant = false): void {
	global $config;

	// Registering a hook replicates plugin config, which needs core lib/poller.php.
	if (!function_exists('replicate_out')) {
		include_once($config['library_path'] . '/poller.php');
	}

	// A hook repaired while the plugin is enabled must be active, or core
	// never calls it; on install and while disabled, core decides.
	$enabled = (new \Somethingwithproof\Hpcsched\Schema(false))->pluginEnabled();

	foreach (\Somethingwithproof\Hpcsched\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('hpcsched', $hook, $function, 'setup.php', $enabled);
	}

	foreach (\Somethingwithproof\Hpcsched\Pages::realms() as $realm) {
		api_plugin_register_realm('hpcsched', implode(',', $realm['files']), $realm['name'], $grant);
	}
}

/**
 * Abort an uninstall that was told to keep the tables but could not untrack
 * them. Core drops every tracked table once plugin_hpcsched_uninstall()
 * returns and ignores its result, so only stopping the request keeps the
 * tables; the plugin then stays installed and the uninstall can be retried.
 * PHP 8.0 syntax, because the unbooted path uses it too.
 */
function hpcsched_uninstall_stop(): void {
	cacti_log('ERROR: Uninstall stopped: the plugin tables could not be untracked, so Cacti would have dropped the tables it was told to keep', false, 'HPCSCHED');

	throw new RuntimeException('The HPC Scheduler plugin uninstall was stopped to keep its tables. See the Cacti log.');
}

/**
 * Uninstall never contacts a scheduler (REQ-LIFE-004). This release creates
 * no Cacti device, data source, graph, or Maint schedule, so there is
 * nothing of core's to remove. Core drops the tracked plugin tables
 * afterwards unless the administrator left "Keep Plugin Tables on
 * Uninstall" checked, which is the default.
 */
function plugin_hpcsched_uninstall(): void {
	if (!hpcsched_boot()) {
		include_once(__DIR__ . '/include/unbooted-uninstall.php');
		hpcsched_uninstall_unbooted();

		return;
	}

	$schema = new \Somethingwithproof\Hpcsched\Schema(false);

	if (hpcsched_settings()->keepTablesOnUninstall) {
		if (!$schema->untrackTables()) {
			hpcsched_uninstall_stop();
		}

		\Somethingwithproof\Hpcsched\Log::info('Uninstall kept the plugin tables as configured');
	} else {
		$schema->forgetVersion();
	}
}

function hpcsched_config_arrays(): void {
	global $menu;

	if (!hpcsched_boot()) {
		return;
	}

	foreach (\Somethingwithproof\Hpcsched\Pages::menu() as $file => $title) {
		$menu[__('Management')]['plugins/hpcsched/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Somethingwithproof\Hpcsched\Pages::upgradeTriggers(), true)) {
		plugin_hpcsched_upgrade();
	}
}

function hpcsched_config_settings(): void {
	global $tabs, $settings;

	if (!hpcsched_boot()) {
		return;
	}

	$tabs['hpcsched']     = __('HPC Scheduler', 'hpcsched');
	$settings['hpcsched'] = \Somethingwithproof\Hpcsched\Settings::form();
}

/**
 * @param array<string, mixed> $nav
 * @return array<string, mixed>
 */
function hpcsched_draw_navigation_text($nav) {
	if (!hpcsched_boot()) {
		return $nav;
	}

	return array_merge($nav, \Somethingwithproof\Hpcsched\Pages::navigation());
}

/**
 * poller_bottom hook: start one reader in the background for each enabled
 * cluster of this poller that is due (REQ-READ-001, REQ-READ-002). Cacti
 * does not call the hook for a disabled plugin, so disabling the plugin
 * stops collection. The hook never contacts a scheduler itself.
 */
function hpcsched_poller_bottom(): void {
	global $config;

	if (!hpcsched_boot()) {
		return;
	}

	// The repositories use the default database connection, which on a
	// remote data collector is its local database, not the main one. Remote
	// collection is phase 3; until then nothing is started there, and the
	// health page lists every cluster assigned to a remote poller.
	if (!hpcsched_on_main_poller()) {
		return;
	}

	if (!function_exists('exec_background')) {
		include_once($config['library_path'] . '/poller.php');
	}

	if (!(new \Somethingwithproof\Hpcsched\Schema(false))->isCurrent()) {
		return;
	}

	// exec_background() silently does nothing for a binary that does not
	// exist, so a bare 'php' fallback would never start the reader.
	$php = trim((string) read_config_option('path_php_binary'));

	if ($php === '' || !is_file($php) || !is_executable($php)) {
		cacti_log('ERROR: The HPC scheduler reader was not started because the PHP binary path setting is empty or not an executable file', false, 'HPCSCHED');

		return;
	}

	try {
		$due = hpcsched_due_clusters((int) $config['poller_id']);
	} catch (\Somethingwithproof\Hpcsched\DatabaseError $e) {
		cacti_log('ERROR: The HPC scheduler readers were not started because the cluster list could not be read', false, 'HPCSCHED');

		return;
	}

	foreach ($due as $cluster_id) {
		// One executable path and an argument array: release/1.2.31 does not
		// quote the path and 1.2.x does, and both escape each array element,
		// although core's docblock declares a string.
		// @phpstan-ignore argument.type
		exec_background($php, array('-q', __DIR__ . '/poller_hpcsched.php', '--cluster=' . (int) $cluster_id));
	}
}

/**
 * @return list<int> ids of this poller's clusters whose reader is due
 */
function hpcsched_due_clusters(int $poller_id): array {
	$clock = new \Somethingwithproof\Hpcsched\SystemClock();

	return (new \Somethingwithproof\Hpcsched\Scheduler(
		new \Somethingwithproof\Hpcsched\ClusterRepository(false, $clock),
		new \Somethingwithproof\Hpcsched\RunRepository(false, $clock),
		$clock
	))->due($poller_id, hpcsched_poller_interval());
}

/**
 * The answerer behind scripts/ss_hpcsched_cluster.php and
 * scripts/ss_hpcsched_partitions.php.
 */
function hpcsched_cluster_series(): \Somethingwithproof\Hpcsched\ClusterSeries {
	$clock = new \Somethingwithproof\Hpcsched\SystemClock();

	return new \Somethingwithproof\Hpcsched\ClusterSeries(
		new \Somethingwithproof\Hpcsched\ClusterRepository(false, $clock),
		new \Somethingwithproof\Hpcsched\SeriesRepository(false),
		new \Somethingwithproof\Hpcsched\FreshnessEvaluator($clock),
		$clock,
		hpcsched_poller_interval()
	);
}

function hpcsched_poller_interval(): int {
	$interval = (int) read_config_option('poller_interval');

	return $interval > 0 ? $interval : 300;
}

/**
 * The production reader for one cluster. Scheduler access goes through the
 * two transports only; nothing else in the plugin builds one.
 */
/**
 * Whether this process runs on the main poller, the only one whose default
 * database connection is the main Cacti database.
 */
function hpcsched_on_main_poller(): bool {
	global $config;

	return (int) ($config['poller_id'] ?? 1) === 1;
}

/**
 * The PHP memory limit in bytes, or -1 for none.
 */
function hpcsched_memory_limit(): int {
	$limit = (string) ini_get('memory_limit');

	return $limit === '' || $limit === '-1' ? -1 : (int) ini_parse_quantity($limit);
}

/**
 * Records a reader run that ran out of memory, called from the reader's
 * shutdown function, where finally blocks have not run. It records the
 * failure while the lease is still held, then frees the lease and Cacti's
 * process slot, so the health page shows the cause instead of a run that
 * never ended.
 */
function hpcsched_record_memory_failure(int $cluster_id, string $holder, \Somethingwithproof\Hpcsched\Clock $clock): void {
	$runs = new \Somethingwithproof\Hpcsched\RunRepository(false, $clock);
	$now  = $clock->now();

	$runs->finish(new \Somethingwithproof\Hpcsched\ClusterRun($cluster_id, finishedAt: $now, outcome: \Somethingwithproof\Hpcsched\RunOutcome::Failed, error: \Somethingwithproof\Hpcsched\ErrorClass::Memory, lastFailure: $now), $holder);
	$runs->release($cluster_id, $holder);
	unregister_process('hpcsched', 'reader', $cluster_id);

	cacti_log('ERROR: The HPC scheduler reader for cluster ' . $cluster_id . ' ran out of memory; lower Maximum Answer Size or raise Collection Memory in Settings', false, 'HPCSCHED');
}

function hpcsched_reader(\Somethingwithproof\Hpcsched\Settings $settings, string $holder = '', ?\Somethingwithproof\Hpcsched\Clock $clock = null, ?string $ca_file = null): \Somethingwithproof\Hpcsched\Reader {
	$clock = $clock ?? new \Somethingwithproof\Hpcsched\SystemClock();

	return new \Somethingwithproof\Hpcsched\Reader(
		new \Somethingwithproof\Hpcsched\RunRepository(false, $clock),
		new \Somethingwithproof\Hpcsched\SnapshotRepository(false),
		new \Somethingwithproof\Hpcsched\Transports($settings, new \Somethingwithproof\Hpcsched\CommandRunner(), new \Somethingwithproof\Hpcsched\SocketTransport($settings->maxOutputBytes(), 5.0, $ca_file)),
		$clock,
		$holder !== '' ? $holder : 'reader-' . getmypid() . '-' . bin2hex(random_bytes(4))
	);
}
