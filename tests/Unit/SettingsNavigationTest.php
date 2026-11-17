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

use Somethingwithproof\Hpcsched\Tests\Scratch;

it('refreshes settings after login restoration only for administrators on the settings page', function () {
	$cgi = dirname(PHP_BINARY) . '/php-cgi';

	if (!is_executable($cgi)) {
		test()->markTestSkipped('PHP CGI is needed to exercise the web-only settings permission filter.');
	}
	$scratch = new Scratch();
	$setup = var_export(dirname(__DIR__, 2) . '/setup.php', true);
	$script = <<<'PHP'
<?php
function __($text, ...$args) { return $text; }
function get_current_page($basename = true) { return $GLOBALS['page']; }
function api_plugin_user_realm_auth($filename = '') {
	return !empty($_SESSION['sess_user_id']) && $GLOBALS['admin'] && $filename === 'hpcsched_clusters.php';
}
require SETUP_PATH;
$results = [];
foreach ([['settings.php', true, 'on'], ['settings.php', false, 'on'], ['hpcsched.php', true, 'on'], ['settings.php', true, ''], ['settings.php', true, null]] as [$page, $admin, $enabled]) {
	$tabs = $settings = [];
	$_SESSION = [];
	hpcsched_config_settings();
	$before = isset($tabs['hpcsched']);
	// Core restores either login mechanism after config_settings has run.
	$_SESSION = ['sess_user_id' => 2];
	$current_user = $enabled === null ? [] : ['id' => 2, 'enabled' => $enabled];
	$nav = hpcsched_draw_navigation_text(['preserved' => ['title' => 'Existing']]);
	$results[] = [$before, isset($tabs['hpcsched']), isset($settings['hpcsched']['hpcsched_squeue']), $nav['preserved']['title']];
}
print json_encode($results);
PHP;
	$fixture = $scratch->file('settings.php', str_replace('SETUP_PATH', $setup, $script));

	try {
		$process = proc_open([$cgi, '-q', $fixture], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['REDIRECT_STATUS' => '200', 'SCRIPT_FILENAME' => $fixture, 'REQUEST_METHOD' => 'GET']);
		expect(is_resource($process))->toBeTrue();
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		$body = explode("\r\n\r\n", $output, 2);

		expect($error)->toBe('')
			->and($status)->toBe(0)
			->and(json_decode($body[count($body) - 1], true))->toBe([
				[false, true, true, 'Existing'],
				[false, false, false, 'Existing'],
				[false, false, false, 'Existing'],
				[false, false, false, 'Existing'],
				[false, false, false, 'Existing'],
			]);
	} finally {
		$scratch->remove();
	}
});
