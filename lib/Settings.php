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
 * Global plugin settings, read once per request from Cacti's settings table
 * (REQ-UI-003). Paths name files on the poller that runs a cluster's reader.
 * No setting holds a credential: a slurmrestd token is read from the file a
 * cluster names, at use time (REQ-CRED-002).
 */
final readonly class Settings {
	public const NAMES = [
		'hpcsched_squeue',
		'hpcsched_scontrol',
		'hpcsched_sshare',
		'hpcsched_prlimit',
		'hpcsched_child_memory',
		'hpcsched_call_timeout',
		'hpcsched_max_output',
		'hpcsched_token_warning',
		'hpcsched_uninstall_keep_tables',
		'hpcsched_data_parser',
	];

	public const DEFAULT_SQUEUE        = '/usr/bin/squeue';
	public const DEFAULT_SCONTROL      = '/usr/bin/scontrol';
	public const DEFAULT_SSHARE        = '/usr/bin/sshare';
	public const DEFAULT_PRLIMIT       = '/usr/bin/prlimit';
	public const DEFAULT_CHILD_MEMORY  = 2048;
	public const DEFAULT_CALL_TIMEOUT  = 30;
	public const DEFAULT_MAX_OUTPUT    = 128;
	public const DEFAULT_TOKEN_WARNING = 3600;

	public function __construct(
		public string $squeue,
		public string $scontrol,
		public string $sshare,
		public string $prlimit,
		public int $childMemoryMib,
		public int $callTimeout,
		public int $maxOutputMib,
		public int $tokenWarning,
		public bool $keepTablesOnUninstall,
		public string $dataParser = DataParser::DEFAULT_VERSION,
	) {
	}

	/**
	 * A setting that was never saved reads as its default, and an out of
	 * range number as its default or its bound. An empty prlimit path turns
	 * the child memory limit off, which the health page reports. Keeping
	 * tables is the default, so an uninstall drops the plugin's records only
	 * after an administrator cleared the box (REQ-LIFE-004).
	 *
	 * @param array<string, mixed> $row setting name => stored value, absent when never saved
	 */
	public static function fromRow(array $row): self {
		return new self(
			squeue: self::path($row, 'hpcsched_squeue', self::DEFAULT_SQUEUE),
			scontrol: self::path($row, 'hpcsched_scontrol', self::DEFAULT_SCONTROL),
			sshare: self::path($row, 'hpcsched_sshare', self::DEFAULT_SSHARE),
			prlimit: array_key_exists('hpcsched_prlimit', $row) && Row::string($row, 'hpcsched_prlimit') === '' ? '' : self::path($row, 'hpcsched_prlimit', self::DEFAULT_PRLIMIT),
			childMemoryMib: min(Row::int($row, 'hpcsched_child_memory', self::DEFAULT_CHILD_MEMORY, 64), 65536),
			callTimeout: min(Row::int($row, 'hpcsched_call_timeout', self::DEFAULT_CALL_TIMEOUT, 2), 300),
			maxOutputMib: min(Row::int($row, 'hpcsched_max_output', self::DEFAULT_MAX_OUTPUT, 1), 1024),
			tokenWarning: min(Row::int($row, 'hpcsched_token_warning', self::DEFAULT_TOKEN_WARNING, 0), 604800),
			keepTablesOnUninstall: array_key_exists('hpcsched_uninstall_keep_tables', $row) ? Row::bool($row, 'hpcsched_uninstall_keep_tables') : true,
			dataParser: DataParser::supported(Row::string($row, 'hpcsched_data_parser')) ? Row::string($row, 'hpcsched_data_parser') : DataParser::DEFAULT_VERSION,
		);
	}

	public function maxOutputBytes(): int {
		return $this->maxOutputMib * 1048576;
	}

	/**
	 * An absolute path without traversal, spaces, or control characters, or
	 * the default.
	 */
	public static function pathAcceptable(string $value): bool {
		return preg_match('#^/[A-Za-z0-9._/+-]{1,254}$#', $value) === 1 && !str_contains($value, '/../') && !str_ends_with($value, '/..') && !str_contains($value, '//');
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function path(array $row, string $name, string $default): string {
		$value = Row::string($row, $name);

		return self::pathAcceptable($value) ? $value : $default;
	}

	/**
	 * Settings tab for the config_settings hook.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(): array {
		$number = static fn (string $label, string $description, int $default, int $min, int $max): array => [
			'friendly_name' => $label,
			'description'   => $description,
			'method'        => 'textbox',
			'default'       => (string) $default,
			'filter'        => FILTER_VALIDATE_INT,
			'options'       => ['options' => ['min_range' => $min, 'max_range' => $max]],
			'max_length'    => '6',
			'size'          => '6',
		];

		$path = static fn (string $label, string $description, string $default): array => [
			'friendly_name' => $label,
			'description'   => $description,
			'method'        => 'textbox',
			'default'       => $default,
			'max_length'    => '255',
			'size'          => '60',
		];

		return [
			'hpcsched_header_commands' => [
				'friendly_name' => __('Slurm Client Commands', 'hpcsched'),
				'method'        => 'spacer',
			],
			'hpcsched_data_parser' => [
				'friendly_name' => __('Data Parser', 'hpcsched'),
				'description'   => __('Slurm data parser for every call, through client commands and slurmrestd alike. Choose one that every cluster\'s release ships: v0.0.43 is in 25.05, 25.11, and 26.05 and is removed in 27.05; v0.0.44 needs 25.11 or later; v0.0.45 needs 26.05 or later. The health page warns when a cluster reports a release without it.', 'hpcsched'),
				'method'        => 'drop_array',
				'array'         => array_combine(DataParser::VERSIONS, DataParser::VERSIONS),
				'default'       => DataParser::DEFAULT_VERSION,
			],
			'hpcsched_squeue'   => $path(__('squeue', 'hpcsched'), __('Absolute path to squeue on each poller that reads a cluster through client commands. The plugin runs it only as squeue --all --json with the data parser above.', 'hpcsched'), self::DEFAULT_SQUEUE),
			'hpcsched_scontrol' => $path(__('scontrol', 'hpcsched'), __('Absolute path to scontrol. The plugin runs only scontrol --json show node, show partition, and show reservation; never update, reconfigure, or token.', 'hpcsched'), self::DEFAULT_SCONTROL),
			'hpcsched_sshare'   => $path(__('sshare', 'hpcsched'), __('Absolute path to sshare, used only for clusters that collect fair-share.', 'hpcsched'), self::DEFAULT_SSHARE),
			'hpcsched_header_limits' => [
				'friendly_name' => __('Limits', 'hpcsched'),
				'method'        => 'spacer',
			],
			'hpcsched_call_timeout'  => $number(__('Call Timeout', 'hpcsched'), __('Seconds one scheduler call may take before it is stopped and its section recorded as failed. A call never runs past the reader time budget either.', 'hpcsched'), self::DEFAULT_CALL_TIMEOUT, 2, 300),
			'hpcsched_max_output'    => $number(__('Maximum Answer Size', 'hpcsched'), __('MiB one scheduler call may return. A larger answer is discarded and its section recorded as failed.', 'hpcsched'), self::DEFAULT_MAX_OUTPUT, 1, 1024),
			'hpcsched_prlimit'       => $path(__('prlimit', 'hpcsched'), __('Absolute path to prlimit (util-linux), used to cap the address space of each client command. Leave empty to run commands without a memory limit; the health page then warns.', 'hpcsched'), self::DEFAULT_PRLIMIT),
			'hpcsched_child_memory'  => $number(__('Command Memory Limit', 'hpcsched'), __('MiB of address space each client command may use, through prlimit.', 'hpcsched'), self::DEFAULT_CHILD_MEMORY, 64, 65536),
			'hpcsched_header_credentials' => [
				'friendly_name' => __('slurmrestd Credentials', 'hpcsched'),
				'method'        => 'spacer',
			],
			'hpcsched_token_warning' => $number(__('Token Expiry Warning', 'hpcsched'), __('Seconds before a token expires when the health page starts to warn, if the token carries an expiry time.', 'hpcsched'), self::DEFAULT_TOKEN_WARNING, 0, 604800),
			'hpcsched_header_uninstall' => [
				'friendly_name' => __('Uninstall', 'hpcsched'),
				'method'        => 'spacer',
			],
			'hpcsched_uninstall_keep_tables' => [
				'friendly_name' => __('Keep Plugin Tables on Uninstall', 'hpcsched'),
				'description'   => __('When checked, uninstalling the plugin keeps its clusters and snapshots. Clear it to have the uninstall drop them; export the clusters first from HPC Clusters. The plugin creates no Cacti devices or Maint schedules in this release, and the scheduler is never contacted either way.', 'hpcsched'),
				'method'        => 'checkbox',
				'default'       => 'on',
			],
		];
	}
}
