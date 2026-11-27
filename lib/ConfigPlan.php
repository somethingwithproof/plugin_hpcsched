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
 * Deterministic CasC plan with a SHA-256 confirmation hash bound to the exact
 * settings and cluster mutations proposed.
 */
final readonly class ConfigPlan {
	/**
	 * @param array<string, string>                             $settingsChanges
	 * @param list<array{action: string, input: ClusterInput}>  $clusterChanges
	 * @param list<string>                                      $errors
	 * @param list<string>                                      $notes
	 */
	public function __construct(
		public array $settingsChanges,
		public array $clusterChanges,
		public array $errors,
		public array $notes = [],
	) {
	}

	public function hasErrors(): bool {
		return $this->errors !== [];
	}

	public function isEmpty(): bool {
		return $this->settingsChanges === [] && $this->clusterChanges === [];
	}

	public function id(): string {
		$canonical = [
			'settings' => $this->settingsChanges,
			'clusters' => array_map(static fn (array $c): array => [
				'action'               => $c['action'],
				'name'                 => $c['input']->name,
				'poller_id'            => $c['input']->pollerId,
				'admin_state'          => $c['input']->adminState->value,
				'transport'            => $c['input']->transport->value,
				'collect_interval'     => $c['input']->interval,
				'slurm_conf'           => $c['input']->slurmConf,
				'rest_url'             => $c['input']->restUrl,
				'rest_user'            => $c['input']->restUser,
				'collect_reservations' => $c['input']->reservations,
				'collect_shares'       => $c['input']->shares,
			], $this->clusterChanges),
			'errors' => $this->errors,
		];

		return substr(hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)), 0, 16);
	}

	/**
	 * @return list<string>
	 */
	public function lines(): array {
		$lines = [];

		foreach ($this->errors as $error) {
			$lines[] = 'ERROR: ' . $error;
		}

		foreach ($this->notes as $note) {
			$lines[] = 'NOTE: ' . $note;
		}

		foreach ($this->settingsChanges as $name => $value) {
			$lines[] = sprintf('~ setting %s = %s', $name, $value);
		}

		foreach ($this->clusterChanges as $change) {
			$prefix  = $change['action'] === 'create' ? '+' : '~';
			$input   = $change['input'];
			$lines[] = sprintf('%s cluster %s (transport=%s poller=%d interval=%d state=%s)', $prefix, $input->name, $input->transport->value, $input->pollerId, $input->interval, $input->adminState->value);
		}

		return $lines;
	}
}
