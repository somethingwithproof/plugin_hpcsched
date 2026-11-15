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

namespace Somethingwithproof\Hpcsched;

/**
 * Validates the cluster form. Values arrive as the raw request strings the
 * page script read through Cacti's request helpers. Nothing is stored that
 * did not pass, and no value becomes part of a command line: paths are
 * checked as absolute paths, the URL against the Endpoint rules, and names
 * against fixed patterns.
 */
final readonly class ClusterInput {
	/**
	 * @param array<string, string> $errors field => message
	 */
	private function __construct(
		public int $id,
		public string $name,
		public int $pollerId,
		public AdminState $adminState,
		public Transport $transport,
		public int $interval,
		public string $slurmConf,
		public string $restUrl,
		public string $restUser,
		public string $tokenFile,
		public bool $reservations,
		public bool $shares,
		public int $updatedAt,
		public array $errors,
	) {
	}

	public function valid(): bool {
		return $this->errors === [];
	}

	/**
	 * @param array<string, string> $input
	 * @param array<int, string>    $pollers poller id => name
	 */
	public static function parse(array $input, ?Cluster $existing, array $pollers, Settings $settings): self {
		$errors    = [];
		$name      = trim($input['name'] ?? '');
		$poller    = Row::int($input, 'poller_id', 0, 1);
		$interval  = Row::int($input, 'collect_interval', 0, 0);
		$transport = Transport::tryFrom(trim($input['transport'] ?? ''));
		$conf      = trim($input['slurm_conf'] ?? '');
		$url       = trim($input['rest_url'] ?? '');
		$user      = trim($input['rest_user'] ?? '');
		$token     = trim($input['token_file'] ?? '');

		if (preg_match(Cluster::NAME_PATTERN, $name) !== 1) {
			$errors['name'] = __('Name: use 1 to 64 letters, digits, dots, dashes, or underscores, starting with a letter or digit.', 'hpcsched');
		}

		// A cluster whose poller was deleted keeps that poller until an
		// administrator picks another, so saving another field does not move it.
		if (!isset($pollers[$poller]) && $poller !== ($existing->pollerId ?? -1)) {
			$errors['poller_id'] = __('Poller: choose one of the listed pollers.', 'hpcsched');
		}

		if ($interval < Cluster::MIN_INTERVAL || $interval > Cluster::MAX_INTERVAL) {
			$errors['collect_interval'] = __('Collection Interval: enter whole seconds from %d to %d.', Cluster::MIN_INTERVAL, Cluster::MAX_INTERVAL, 'hpcsched');
		}

		if ($transport === null) {
			$errors['transport'] = __('Transport: choose one of the listed transports.', 'hpcsched');
		}

		if ($conf !== '' && !Settings::pathAcceptable($conf)) {
			$errors['slurm_conf'] = __('slurm.conf: enter an absolute path, or leave it empty to use the poller default.', 'hpcsched');
		}

		if ($transport === Transport::Rest) {
			try {
				if (!Endpoint::parse($url)->permittedBy($settings->restHosts)) {
					$errors['rest_url'] = __('slurmrestd URL: this host and port are not on the slurmrestd host list in Settings.', 'hpcsched');
				}
			} catch (TransportError) {
				$errors['rest_url'] = __('slurmrestd URL: enter an https:// URL without user name, query, or fragment; plain http:// is accepted only for a loopback address.', 'hpcsched');
			}

			if ($user !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $user) !== 1) {
				$errors['rest_user'] = __('slurmrestd User: use at most 64 letters, digits, dots, dashes, or underscores, or leave it empty for a user token.', 'hpcsched');
			}

			if (!TokenFile::pathAcceptable($token)) {
				$errors['token_file'] = __('Token File: enter the absolute path of the token file on the poller. The token itself is never entered here.', 'hpcsched');
			} elseif ($settings->tokenDirs === []) {
				$errors['token_file'] = __('Token File: no token file directories are set yet. Ask a Cacti administrator to set them under Settings, HPC Scheduler.', 'hpcsched');
			} elseif (!$settings->tokenPathAllowed($token)) {
				$errors['token_file'] = __('Token File: the file must be inside one of the token file directories set in Settings (%s).', implode(', ', $settings->tokenDirs), 'hpcsched');
			}
		} else {
			$url   = '';
			$user  = '';
			$token = '';
		}

		return new self(
			id: $existing->id ?? 0,
			name: $name,
			pollerId: $poller,
			adminState: Row::bool($input, 'enabled') ? AdminState::Enabled : AdminState::Disabled,
			transport: $transport ?? Transport::Commands,
			interval: $interval,
			slurmConf: $conf,
			restUrl: $url,
			restUser: $user,
			tokenFile: $token,
			reservations: Row::bool($input, 'collect_reservations'),
			shares: Row::bool($input, 'collect_shares'),
			updatedAt: Row::int($input, 'updated_at', 0, 0),
			errors: $errors,
		);
	}
}
