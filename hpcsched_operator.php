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

use Somethingwithproof\Hpcsched\OperatorPage;

chdir('../../');
include('./include/auth.php');
include_once('./plugins/hpcsched/setup.php');

if (!hpcsched_page_start()) {
	exit;
}
require_once('./plugins/hpcsched/include/operator.php');

$user    = (int) ($_SESSION['sess_user_id'] ?? 0);
$allowed = $user > 0 && api_plugin_user_realm_auth('hpcsched_operator.php');

if (!$allowed) {
	http_response_code(403);
	exit;
}
$actions = [];
$action  = (string) get_nfilter_request_var('action');
$now     = time();
$session = $_SESSION['hpcsched_triage'] ?? [];
$session = is_array($session) ? $session : [];

try {
	// Re-read on POST too: stale forms never retain revoked device access.
	$rows = hpcsched_operator_rows();

	if ($action !== '' && $action !== 'default') {
		$method = $_SERVER['REQUEST_METHOD'] ?? '';
		$status = OperatorPage::actionStatus($allowed, $action, $method, $method === 'POST' && csrf_check(false), array_merge(['acknowledge'], array_keys($actions)));

		if ($status !== 200) {
			http_response_code($status);

			if ($status === 405) {
				header('Allow: POST');
			}
			exit;
		}
		$id  = (string) get_nfilter_request_var('id');
		$row = null;

		foreach ($rows as $candidate) {
			if ($candidate['id'] === $id) {
				$row = $candidate;

				break;
			}
		}

		if ($row === null) {
			http_response_code(403);
			exit;
		}
		$fingerprint = (string) get_nfilter_request_var('fingerprint');

		if (!hash_equals(OperatorPage::fingerprint($row), $fingerprint)) {
			http_response_code(409);
			exit;
		}

		if ($action === 'acknowledge') {
			if (!OperatorPage::acknowledge($session, $row, $fingerprint, $user, $now)) {
				http_response_code(409);
				exit;
			}
			$_SESSION['hpcsched_triage'] = $session;
		}
		cacti_log('Operator ' . $action . ' by user ' . $user . ' on object ' . hash('sha256', $id), false, 'HPCSCHED');
		header('Location: hpcsched_operator.php');
		exit;
	}
	top_header();
	print '<p><a href="hpcsched.php">' . html_escape(__('Monitoring view', 'hpcsched')) . '</a></p>';
	OperatorPage::render($rows, $session, $user, $now, $actions);
	bottom_footer();
} catch (Throwable $error) {
	cacti_log('Operator page failed (' . get_class($error) . ')', false, 'HPCSCHED');
	http_response_code(503);
	top_header();
	print '<p class="textError">' . html_escape(__('Observations could not be read. Ask an administrator to check the plugin log.', 'hpcsched')) . '</p>';
	bottom_footer();
}
