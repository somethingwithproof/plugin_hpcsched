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
 *
 * These settings live on Cacti's own Settings page, which needs Cacti's
 * Settings permission, not the plugin's Admin realm. The token directories
 * and the slurmrestd host allowlist bound what a cluster definition, which
 * the Admin realm edits, can point the reader at.
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
		'hpcsched_token_dirs',
		'hpcsched_rest_hosts',
		'hpcsched_reader_memory',
	];

	public const DEFAULT_SQUEUE        = '/usr/bin/squeue';
	public const DEFAULT_SCONTROL      = '/usr/bin/scontrol';
	public const DEFAULT_SSHARE        = '/usr/bin/sshare';
	public const DEFAULT_PRLIMIT       = '/usr/bin/prlimit';
	public const DEFAULT_CHILD_MEMORY  = 2048;
	public const DEFAULT_CALL_TIMEOUT  = 30;
	public const DEFAULT_MAX_OUTPUT    = 64;
	public const DEFAULT_READER_MEMORY = 1024;

	/**
	 * Memory one MiB of scheduler answer needs in the reader: the text
	 * itself plus its decoded arrays, which measured about 10 times the text
	 * for job records shaped like Slurm's, with headroom.
	 */
	public const DECODE_RATIO = 12;

	/** MiB the reader keeps for everything other than one decoded answer. */
	public const MEMORY_RESERVE = 64;
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
		/** @var list<string> */
		public array $tokenDirs = [],
		/** @var list<string> */
		public array $restHosts = [],
		public int $readerMemoryMib = self::DEFAULT_READER_MEMORY,
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
			tokenDirs: self::directories(Row::string($row, 'hpcsched_token_dirs')),
			restHosts: self::hosts(Row::string($row, 'hpcsched_rest_hosts')),
			readerMemoryMib: min(Row::int($row, 'hpcsched_reader_memory', self::DEFAULT_READER_MEMORY, 256), 65536),
		);
	}

	/**
	 * The answer size the reader actually allows: the configured maximum,
	 * lowered so one decoded answer fits in the reader memory with the
	 * reserve left over. A run that cannot fit an answer fails that section
	 * as too large instead of running out of memory.
	 */
	public function fittedOutputMib(): int {
		return max(1, min($this->maxOutputMib, intdiv($this->readerMemoryMib - self::MEMORY_RESERVE, self::DECODE_RATIO)));
	}

	/**
	 * A copy for a reader whose memory limit, in bytes (-1 for none), is
	 * lower than the configured reader memory, as when the limit could not
	 * be raised.
	 */
	public function withMemoryLimit(int $bytes): self {
		if ($bytes < 0 || $bytes >= $this->readerMemoryMib * 1048576) {
			return $this;
		}

		return new self($this->squeue, $this->scontrol, $this->sshare, $this->prlimit, $this->childMemoryMib, $this->callTimeout, $this->maxOutputMib, $this->tokenWarning, $this->keepTablesOnUninstall, $this->dataParser, $this->tokenDirs, $this->restHosts, intdiv($bytes, 1048576));
	}

	/**
	 * Absolute directories, separated by commas or white space. An entry
	 * that is not a plain absolute path is dropped, never widened, and the
	 * root directory is not accepted.
	 *
	 * @return list<string>
	 */
	public static function directories(string $value): array {
		$out = [];

		foreach (preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
			$entry = rtrim($entry, '/');

			if ($entry !== '' && self::pathAcceptable($entry)) {
				$out[] = $entry;
			}
		}

		return array_values(array_unique($out));
	}

	/**
	 * slurmrestd hosts, as host or host:port, separated by commas or white
	 * space, lower case. An entry that is not a plain host name, IPv4
	 * address, or bracketed IPv6 address with an optional port is dropped.
	 *
	 * @return list<string>
	 */
	public static function hosts(string $value): array {
		$out = [];

		foreach (preg_split('/[\s,]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
			if (preg_match('/^([a-z0-9.-]{1,253}|\[[0-9a-f:.]{2,45}\])(:[0-9]{1,5})?$/', $entry) === 1) {
				$out[] = $entry;
			}
		}

		return array_values(array_unique($out));
	}

	/**
	 * Whether a token file path lies inside one of the token directories, by
	 * its text. The reader checks the resolved path again when it opens the
	 * file; this check is for the cluster form, which may run on another
	 * host than the poller.
	 */
	public function tokenPathAllowed(string $path): bool {
		foreach ($this->tokenDirs as $dir) {
			if (str_starts_with($path, $dir . '/') && !str_contains(substr($path, strlen($dir)), '/../')) {
				return true;
			}
		}

		return false;
	}

	public function maxOutputBytes(): int {
		return $this->fittedOutputMib() * 1048576;
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
				'description'   => __('Slurm data parser for the client commands. Choose one that the release of every cluster read through client commands ships: v0.0.43 is in 25.05, 25.11, and 26.05 and is removed in 27.05; v0.0.44 needs 25.11 or later; v0.0.45 needs 26.05 or later. The health page warns when a cluster reports a release without it. Clusters read through slurmrestd ignore this setting: they use the newest of these the daemon serves, and the health page shows which.', 'hpcsched'),
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
			'hpcsched_max_output'    => $number(__('Maximum Answer Size', 'hpcsched'), __('MiB one scheduler call may return. A larger answer is discarded and its section recorded as failed. Decoding needs about 12 times the answer size, so the reader uses at most (Collection Memory - 64) / 12 MiB and the health page warns when this value is larger.', 'hpcsched'), self::DEFAULT_MAX_OUTPUT, 1, 1024),
			'hpcsched_reader_memory' => $number(__('Collection Memory', 'hpcsched'), __('MiB of PHP memory each collection run raises its limit to (it never lowers one). The default of 1024 fits answers up to 80 MiB.', 'hpcsched'), self::DEFAULT_READER_MEMORY, 256, 65536),
			'hpcsched_prlimit'       => $path(__('prlimit', 'hpcsched'), __('Absolute path to prlimit (util-linux), used to cap the address space of each client command. Leave empty to run commands without a memory limit; the health page then warns.', 'hpcsched'), self::DEFAULT_PRLIMIT),
			'hpcsched_child_memory'  => $number(__('Command Memory Limit', 'hpcsched'), __('MiB of address space each client command may use, through prlimit.', 'hpcsched'), self::DEFAULT_CHILD_MEMORY, 64, 65536),
			'hpcsched_header_credentials' => [
				'friendly_name' => __('slurmrestd Credentials', 'hpcsched'),
				'method'        => 'spacer',
			],
			'hpcsched_token_dirs' => [
				'friendly_name' => __('Token File Directories', 'hpcsched'),
				'description'   => __('Absolute directories on the pollers that may hold slurmrestd token files, separated by commas or spaces. A cluster can name only a token file inside one of them, owned by the poller user and reached without a symbolic link. Keep each directory writable only by root and the poller user. Empty means no cluster can use slurmrestd.', 'hpcsched'),
				'method'        => 'textbox',
				'default'       => '',
				'max_length'    => '1024',
				'size'          => '60',
			],
			'hpcsched_rest_hosts' => [
				'friendly_name' => __('slurmrestd Hosts', 'hpcsched'),
				'description'   => __('Optional. The slurmrestd hosts a cluster may name, as host or host:port, separated by commas or spaces. An entry without a port allows any port on that host. Empty allows any host.', 'hpcsched'),
				'method'        => 'textbox',
				'default'       => '',
				'max_length'    => '1024',
				'size'          => '60',
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
