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
 * Configuration-as-Code export, validation, dry-run diff planning, and
 * confirmation-gated apply for plugin_hpcsched.
 *
 * Token file references are redacted on export and never accepted inline as
 * raw secrets.
 */
final class ConfigService {
	/** Redacted constant for ConfigService. */
	public const REDACTED = '[REDACTED]';

	/**
	 * Construct a new ConfigService instance.
	 *
	 * @param SettingsRepository $settings
	 * @param ClusterRepository $clusters
	 * @param ConfigRepository $configRepo
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly ClusterRepository $clusters,
		private readonly ConfigRepository $configRepo,
	) {
	}

	/**
	 * Build the canonical export array with token file references redacted.
	 *
	 * @return array<string, mixed>
	 */
	public function exportData(): array {
		$current = $this->settings->load();
		$data    = [
			'schema_version' => ConfigCodec::VERSION,
			'settings'       => [
				'hpcsched_data_parser'           => $current->dataParser,
				'hpcsched_squeue'                => $current->squeue,
				'hpcsched_scontrol'              => $current->scontrol,
				'hpcsched_sshare'                => $current->sshare,
				'hpcsched_prlimit'               => $current->prlimit,
				'hpcsched_child_memory'          => $current->childMemoryMib,
				'hpcsched_call_timeout'          => $current->callTimeout,
				'hpcsched_max_output'            => $current->maxOutputMib,
				'hpcsched_reader_memory'         => $current->readerMemoryMib,
				'hpcsched_token_warning'         => $current->tokenWarning,
				'hpcsched_token_dirs'            => implode(',', $current->tokenDirs),
				'hpcsched_rest_hosts'            => implode(',', $current->restHosts),
				'hpcsched_uninstall_keep_tables' => $current->keepTablesOnUninstall,
			],
		];

		$exportedClusters = [];

		foreach ($this->clusters->all() as $cluster) {
			$entry = [
				'name'                 => $cluster->name,
				'poller_id'            => $cluster->pollerId,
				'admin_state'          => $cluster->adminState->value,
				'transport'            => $cluster->transport->value,
				'collect_interval'     => $cluster->interval,
				'collect_reservations' => $cluster->reservations,
				'collect_shares'       => $cluster->shares,
			];

			if ($cluster->slurmConf !== '') {
				$entry['slurm_conf'] = $cluster->slurmConf;
			}

			if ($cluster->restUrl !== '') {
				$entry['rest_url'] = $cluster->restUrl;
			}

			if ($cluster->restUser !== '') {
				$entry['rest_user'] = $cluster->restUser;
			}

			if ($cluster->tokenFile !== '') {
				$entry['token_file'] = self::REDACTED;
			}

			$exportedClusters[] = $entry;
		}

		if ($exportedClusters !== []) {
			$data['clusters'] = $exportedClusters;
		}

		return $data;
	}

	/**
	 * Export.
	 *
	 * @param string $format
	 * @return string
	 */
	public function export(string $format): string {
		return ConfigCodec::write($format, $this->exportData());
	}

	/**
	 * @return list<string>
	 */
	public function validate(ConfigTree $tree): array {
		return $this->plan($tree)->errors;
	}

	/**
	 * Plan.
	 *
	 * @param ConfigTree $tree
	 * @return ConfigPlan
	 */
	public function plan(ConfigTree $tree): ConfigPlan {
		$errors          = [];
		$notes           = [];
		$settingsChanges = [];
		$clusterChanges  = [];
		$data            = $tree->data;

		if (($data['schema_version'] ?? null) !== ConfigCodec::VERSION) {
			$errors[] = sprintf('Unsupported schema_version; expected %d', ConfigCodec::VERSION);
		}

		foreach (array_keys($data) as $topKey) {
			if (!in_array((string) $topKey, ['schema_version', 'settings', 'clusters'], true)) {
				$errors[] = sprintf('Unknown top-level key "%s"', (string) $topKey);
			}
		}

		$current = $this->settings->load();
		$curMap  = [
			'hpcsched_data_parser'           => $current->dataParser,
			'hpcsched_squeue'                => $current->squeue,
			'hpcsched_scontrol'              => $current->scontrol,
			'hpcsched_sshare'                => $current->sshare,
			'hpcsched_prlimit'               => $current->prlimit,
			'hpcsched_child_memory'          => (string) $current->childMemoryMib,
			'hpcsched_call_timeout'          => (string) $current->callTimeout,
			'hpcsched_max_output'            => (string) $current->maxOutputMib,
			'hpcsched_reader_memory'         => (string) $current->readerMemoryMib,
			'hpcsched_token_warning'         => (string) $current->tokenWarning,
			'hpcsched_token_dirs'            => implode(',', $current->tokenDirs),
			'hpcsched_rest_hosts'            => implode(',', $current->restHosts),
			'hpcsched_uninstall_keep_tables' => $current->keepTablesOnUninstall ? 'on' : '',
		];

		if (isset($data['settings'])) {
			if (!is_array($data['settings']) || array_is_list($data['settings'])) {
				$errors[] = 'settings must be a map';
			} else {
				foreach ($data['settings'] as $name => $rawVal) {
					$key = (string) $name;

					if (!in_array($key, Settings::NAMES, true)) {
						$errors[] = sprintf('Unknown setting "%s"', $key);

						continue;
					}

					$encoded = $this->validateSetting($key, $rawVal, $errors);

					if ($encoded !== null && $curMap[$key] !== $encoded) {
						$settingsChanges[$key] = $encoded;
					}
				}
			}
		}

		$existingByName = [];

		foreach ($this->clusters->all() as $existing) {
			$existingByName[$existing->name] = $existing;
		}

		if (isset($data['clusters'])) {
			if (!is_array($data['clusters']) || !array_is_list($data['clusters'])) {
				$errors[] = 'clusters must be a list';
			} else {
				$seen = [];

				foreach ($data['clusters'] as $i => $item) {
					if (!is_array($item)) {
						$errors[] = sprintf('clusters[%d] must be a map', $i);

						continue;
					}

					if (isset($item['jwt']) || isset($item['secret']) || isset($item['password'])) {
						$errors[] = sprintf('clusters[%d]: inline credentials are forbidden', $i);

						continue;
					}

					$name = is_string($item['name'] ?? null) ? trim($item['name']) : '';

					if ($name === '' || preg_match(Cluster::NAME_PATTERN, $name) !== 1) {
						$errors[] = sprintf('clusters[%d]: invalid cluster name', $i);

						continue;
					}

					if (isset($seen[$name])) {
						$errors[] = sprintf('Duplicate cluster name "%s"', $name);

						continue;
					}

					$seen[$name] = true;
					$transport   = is_string($item['transport'] ?? null) ? Transport::tryFrom($item['transport']) : null;
					$state       = is_string($item['admin_state'] ?? null) ? AdminState::tryFrom($item['admin_state']) : AdminState::Enabled;
					$pollerId    = is_int($item['poller_id'] ?? null) ? $item['poller_id'] : 1;
					$interval    = is_int($item['collect_interval'] ?? null) ? $item['collect_interval'] : Cluster::DEFAULT_INTERVAL;

					if ($transport === null) {
						$errors[] = sprintf('clusters[%d] (%s): invalid transport', $i, $name);

						continue;
					}

					if ($state === null) {
						$errors[] = sprintf('clusters[%d] (%s): invalid admin_state', $i, $name);

						continue;
					}

					if ($pollerId < 1) {
						$errors[] = sprintf('clusters[%d] (%s): poller_id must be >= 1', $i, $name);

						continue;
					}

					if ($interval < Cluster::MIN_INTERVAL || $interval > Cluster::MAX_INTERVAL) {
						$errors[] = sprintf('clusters[%d] (%s): collect_interval out of range (%d..%d)', $i, $name, Cluster::MIN_INTERVAL, Cluster::MAX_INTERVAL);

						continue;
					}

					$existing  = $existingByName[$name] ?? null;
					$slurmConf = is_string($item['slurm_conf'] ?? null) ? trim($item['slurm_conf']) : '';
					$restUrl   = is_string($item['rest_url'] ?? null) ? trim($item['rest_url']) : '';
					$restUser  = is_string($item['rest_user'] ?? null) ? trim($item['rest_user']) : '';
					$tokenFile = is_string($item['token_file'] ?? null) ? trim($item['token_file']) : '';

					if ($tokenFile === self::REDACTED) {
						$tokenFile = $existing !== null ? $existing->tokenFile : '';
						$notes[]   = sprintf('Cluster %s: preserved existing local token_file for redacted entry', $name);
					} elseif ($tokenFile !== '' && !Settings::pathAcceptable($tokenFile)) {
						$errors[] = sprintf('clusters[%d] (%s): invalid token_file path', $i, $name);

						continue;
					}

					if ($slurmConf !== '' && !Settings::pathAcceptable($slurmConf)) {
						$errors[] = sprintf('clusters[%d] (%s): invalid slurm_conf path', $i, $name);

						continue;
					}

					if ($transport === Transport::Rest && $restUrl === '') {
						$errors[] = sprintf('clusters[%d] (%s): rest transport requires rest_url', $i, $name);

						continue;
					}

					$reservations = is_bool($item['collect_reservations'] ?? null) ? $item['collect_reservations'] : false;
					$shares       = is_bool($item['collect_shares'] ?? null) ? $item['collect_shares'] : false;

					$input = ClusterInput::fromValidated(
						id: $existing !== null ? $existing->id : 0,
						name: $name,
						pollerId: $pollerId,
						adminState: $state,
						transport: $transport,
						interval: $interval,
						slurmConf: $slurmConf,
						restUrl: $restUrl,
						restUser: $restUser,
						tokenFile: $tokenFile,
						reservations: $reservations,
						shares: $shares,
						updatedAt: $existing !== null ? $existing->updatedAt : 0,
					);

					if ($existing === null) {
						$clusterChanges[] = ['action' => 'create', 'input' => $input];
					} elseif ($this->clusterDiffers($existing, $input)) {
						$clusterChanges[] = ['action' => 'update', 'input' => $input];
					}
				}
			}
		}

		ksort($settingsChanges);

		return new ConfigPlan($settingsChanges, $clusterChanges, $errors, $notes);
	}

	/**
	 * @return list<string> refusals (empty on success)
	 */
	public function apply(ConfigTree $tree, string $confirmHash): array {
		$plan = $this->plan($tree);

		if ($plan->hasErrors()) {
			return $plan->errors;
		}

		if ($confirmHash === '' || !hash_equals($plan->id(), $confirmHash)) {
			return [sprintf('Confirmation hash mismatch (expected %s)', $plan->id())];
		}

		$refusals = [];

		foreach ($plan->settingsChanges as $name => $value) {
			if (!$this->configRepo->saveSetting($name, $value)) {
				$refusals[] = sprintf('Failed to save setting %s', $name);
			}
		}

		foreach ($plan->clusterChanges as $change) {
			if ($this->clusters->save($change['input']) < 1) {
				$refusals[] = sprintf('Failed to %s cluster %s', $change['action'], $change['input']->name);
			}
		}

		return $refusals;
	}

	/**
	 * @param list<string> $errors
	 */
	private function validateSetting(string $key, mixed $value, array &$errors): ?string {
		$ranges = [
			'hpcsched_child_memory'  => [64, 65536],
			'hpcsched_call_timeout'  => [2, 300],
			'hpcsched_max_output'    => [1, 1024],
			'hpcsched_reader_memory' => [256, 65536],
			'hpcsched_token_warning' => [0, 604800],
		];

		if (isset($ranges[$key])) {
			if (!is_int($value) || $value < $ranges[$key][0] || $value > $ranges[$key][1]) {
				$errors[] = sprintf('Setting %s must be an integer in %d..%d', $key, $ranges[$key][0], $ranges[$key][1]);

				return null;
			}

			return (string) $value;
		}

		if ($key === 'hpcsched_uninstall_keep_tables') {
			if (!is_bool($value)) {
				$errors[] = 'Setting hpcsched_uninstall_keep_tables must be a boolean';

				return null;
			}

			return $value ? 'on' : '';
		}

		if (!is_string($value)) {
			$errors[] = sprintf('Setting %s must be a string', $key);

			return null;
		}

		if ($key === 'hpcsched_data_parser') {
			if (!DataParser::supported($value)) {
				$errors[] = sprintf('Unsupported data parser "%s"', $value);

				return null;
			}

			return $value;
		}

		if (in_array($key, ['hpcsched_squeue', 'hpcsched_scontrol', 'hpcsched_sshare'], true) && !Settings::pathAcceptable($value)) {
			$errors[] = sprintf('Setting %s must be a valid absolute path', $key);

			return null;
		}

		if ($key === 'hpcsched_prlimit' && $value !== '' && !Settings::pathAcceptable($value)) {
			$errors[] = 'Setting hpcsched_prlimit must be empty or a valid absolute path';

			return null;
		}

		return $value;
	}

	/**
	 * Cluster differs.
	 *
	 * @param Cluster $existing
	 * @param ClusterInput $desired
	 * @return bool
	 */
	private function clusterDiffers(Cluster $existing, ClusterInput $desired): bool {
		return $existing->pollerId !== $desired->pollerId
			|| $existing->adminState !== $desired->adminState
			|| $existing->transport !== $desired->transport
			|| $existing->interval !== $desired->interval
			|| $existing->slurmConf !== $desired->slurmConf
			|| $existing->restUrl !== $desired->restUrl
			|| $existing->restUser !== $desired->restUser
			|| $existing->tokenFile !== $desired->tokenFile
			|| $existing->reservations !== $desired->reservations
			|| $existing->shares !== $desired->shares;
	}
}
