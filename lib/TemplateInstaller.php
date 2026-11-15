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
 * Imports the shipped device, data, and graph templates and the partition
 * data query with Cacti's own import_xml_data() (REQ-OBJ-001), and installs
 * the data query XML under Cacti's resource/script_server/.
 *
 * templates/hpcsched-templates.xml is Cacti's own export of templates that
 * tests/bin/build-templates.php builds in a disposable Cacti; it is never
 * edited by hand. Every object carries a hash derived from its name, so a
 * second import updates the objects of the first instead of adding copies,
 * and the plugin finds its objects by hash even after an operator renames
 * them.
 *
 * Cacti 1.2.31 and 1.2.x read a data query XML only from inside
 * <path_cacti>/resource (get_data_query_array() rejects any other path after
 * resolving it), so the file is copied there. A file the plugin did not
 * write, or that was changed after it was written, is never replaced or
 * removed: the plugin records the SHA-256 of each file it wrote.
 */
final class TemplateInstaller {
	/** Bump when templates/hpcsched-templates.xml or the query XML changes. */
	public const VERSION = 1;

	public const SETTING         = 'hpcsched_templates_version';
	public const FAILURE_SETTING = 'hpcsched_templates_failure';
	public const FILES_SETTING   = 'hpcsched_query_files';

	public const DEVICE_TEMPLATE = 'HPC Scheduler Cluster';
	public const CLUSTER         = 'HPC Scheduler Cluster Values';
	public const QUERY           = 'HPC Scheduler Partitions';
	public const QUERY_FILE      = 'hpcsched_partitions.xml';
	public const AGE_GRAPH       = 'HPC Scheduler Snapshot Age';

	public function __construct(
		private readonly string $pluginPath,
		private readonly string $cactiPath,
		private readonly CactiObjectRepository $objects,
	) {
	}

	/**
	 * The hash the template builder gives an object, 32 hex characters as
	 * Cacti stores it.
	 */
	public static function hash(string $kind, string $name): string {
		return md5('plugin_hpcsched:' . $kind . ':' . $name);
	}

	/**
	 * Graph templates created with every cluster device, each drawing one
	 * group of the cluster values, plus the age graph that keeps age stored.
	 *
	 * @return array<string, string> Series group (or age) => graph template name
	 */
	public static function overview(): array {
		$out = [];

		foreach (array_keys(Series::groups()) as $group) {
			$out[$group] = 'HPC Scheduler ' . $group;
		}

		return $out + [Series::AGE => self::AGE_GRAPH];
	}

	/**
	 * Per-partition graph templates, one per Series group; each has a data
	 * template of the same name holding exactly the values it draws.
	 *
	 * @return array<string, string> Series group => graph and data template name
	 */
	public static function partitionGraphs(): array {
		$out = [];

		foreach (array_keys(Series::groups()) as $group) {
			$out[$group] = 'HPC Scheduler Partition ' . $group;
		}

		return $out;
	}

	public function isCurrent(): bool {
		return (int) read_config_option(self::SETTING) === self::VERSION && $this->present() && $this->queryFileProblem() === null;
	}

	/**
	 * The reason the last install failed, or '' when it succeeded.
	 */
	public function failure(): string {
		return (string) read_config_option(self::FAILURE_SETTING);
	}

	/**
	 * Install the query file and import the templates. A failure is logged
	 * and kept for the health page; success clears it.
	 */
	public function install(): bool {
		$error = $this->installQueryFile() ?? $this->import();

		if ($error !== null) {
			Log::error($error);
			set_config_option(self::FAILURE_SETTING, $error);

			return false;
		}

		set_config_option(self::SETTING, (string) self::VERSION);
		set_config_option(self::FAILURE_SETTING, '');

		return true;
	}

	/**
	 * Remove the query file the plugin wrote if nobody changed it since. The
	 * templates and every Cacti object made from them stay: they belong to
	 * the site once imported.
	 */
	public function uninstall(): void {
		$written = $this->written();
		$target  = $this->queryTarget();

		if ($written !== null && $target !== null && !is_link($target) && is_file($target) && hash_file('sha256', $target) === $written) {
			@unlink($target);
		}

		set_config_option(self::FILES_SETTING, '');
		set_config_option(self::SETTING, '');
	}

	/**
	 * Every shipped object exists, found by hash.
	 */
	public function present(): bool {
		$found = [
			$this->objects->deviceTemplateId(),
			$this->objects->idByHash('data_input', self::hash('data_input', self::CLUSTER)),
			$this->objects->idByHash('data_template', self::hash('data_template', self::CLUSTER)),
			$this->objects->queryId(),
		];

		foreach (self::overview() as $name) {
			$found[] = $this->objects->graphTemplateId($name);
		}

		foreach (self::partitionGraphs() as $name) {
			$found[] = $this->objects->graphTemplateId($name);
			$found[] = $this->objects->idByHash('data_template', self::hash('data_template', $name));
		}

		return !in_array(0, $found, true);
	}

	/**
	 * Why the installed query file is missing or differs from the shipped
	 * one, or null when it is in place.
	 */
	public function queryFileProblem(): ?string {
		$source = $this->querySource();
		$target = $this->queryTarget();

		if ($target === null || !is_file($target)) {
			return __('The data query file %s is not installed in Cacti\'s resource/script_server directory.', self::QUERY_FILE, 'hpcsched');
		}

		if (!is_file($source) || hash_file('sha256', $target) !== hash_file('sha256', $source)) {
			return __('The data query file %s in Cacti\'s resource/script_server directory differs from the one the plugin ships.', self::QUERY_FILE, 'hpcsched');
		}

		return null;
	}

	/**
	 * Copy the data query file into Cacti's resource tree.
	 *
	 * Ownership is recorded only for a file this method wrote: a file that is
	 * already there with the right content, for example one an administrator
	 * copied by hand, is used but not recorded, so uninstall never removes
	 * it. A file is replaced only when the plugin wrote it and nobody changed
	 * it since. The destination must not be a symbolic link, dangling or not;
	 * the file is written to a temporary file in the same directory and
	 * renamed into place, and rename() replaces a path without following it.
	 *
	 * @return string|null the reason, or null when the file is in place
	 */
	private function installQueryFile(): ?string {
		$source  = $this->querySource();
		$target  = $this->queryTarget();
		$written = $this->written();

		if ($target === null || !is_file($source)) {
			return sprintf('The data query file %s cannot be installed: the source or the Cacti resource directory is missing', self::QUERY_FILE);
		}

		clearstatcache(true, $target);

		if (is_link($target)) {
			return sprintf('%s is a symbolic link; it was left as it is. Replace it with a copy of plugins/hpcsched/resource/script_server/%s', $target, self::QUERY_FILE);
		}

		$want = (string) hash_file('sha256', $source);

		if (file_exists($target)) {
			$have = is_file($target) ? (string) hash_file('sha256', $target) : '';

			if ($have === $want) {
				return null;
			}

			if ($written !== $have) {
				return sprintf('%s exists and was not written by this plugin, or was changed after; it was left as it is. Compare it with plugins/hpcsched/resource/script_server/%s', $target, self::QUERY_FILE);
			}
		}

		if (!$this->writeQueryFile($source, $target, $want)) {
			return sprintf('Could not write %s. Copy plugins/hpcsched/resource/script_server/%s there as the web server user, then install the templates again from the health page', $target, self::QUERY_FILE);
		}

		set_config_option(self::FILES_SETTING, $want);

		return null;
	}

	/**
	 * Write $source to $target through a temporary file in the target's
	 * directory, checking the copy's hash before the rename.
	 */
	private function writeQueryFile(string $source, string $target, string $want): bool {
		$dir = dirname($target);

		if (!is_dir($dir) || !is_writable($dir)) {
			return false;
		}

		// tempnam() falls back to the system temporary directory when it
		// cannot create the file here, and a rename from there is not atomic.
		$temp = @tempnam($dir, '.hpcsched-');

		if ($temp === false || realpath(dirname($temp)) !== realpath($dir)) {
			if (is_string($temp)) {
				@unlink($temp);
			}

			return false;
		}

		$ok = @copy($source, $temp) && hash_file('sha256', $temp) === $want && @chmod($temp, 0644) && @rename($temp, $target);

		if (!$ok) {
			@unlink($temp);
		}

		return $ok;
	}

	/**
	 * @return string|null the reason, or null when every object imported
	 */
	private function import(): ?string {
		$file = $this->pluginPath . '/templates/hpcsched-templates.xml';
		$xml  = is_readable($file) ? file_get_contents($file) : false;

		if (!is_string($xml) || $xml === '') {
			return 'The template file ' . $file . ' is missing or empty';
		}

		include_once($this->cactiPath . '/lib/utility.php');
		include_once($this->cactiPath . '/lib/import.php');

		$result = import_xml_data($xml, false, $this->objects->defaultProfileId());

		if (!is_array($result) || $result === []) {
			return 'Importing the HPC Scheduler templates returned nothing';
		}

		$failed = 0;

		foreach ($result as $type => $entries) {
			if ($type === 'files' || !is_array($entries)) {
				continue;
			}

			foreach ($entries as $entry) {
				if (is_array($entry) && ($entry['result'] ?? '') === 'fail') {
					$failed++;
				}
			}
		}

		if ($failed > 0) {
			return sprintf('%d HPC Scheduler template objects failed to import', $failed);
		}

		return $this->present() ? null : 'The HPC Scheduler templates are missing after import';
	}

	/**
	 * The SHA-256 of the query file as the plugin last wrote it, or null.
	 */
	private function written(): ?string {
		$hash = (string) read_config_option(self::FILES_SETTING);

		return preg_match('/^[0-9a-f]{64}$/', $hash) === 1 ? $hash : null;
	}

	private function querySource(): string {
		return $this->pluginPath . '/resource/script_server/' . self::QUERY_FILE;
	}

	private function queryTarget(): ?string {
		$dir = $this->cactiPath . '/resource/script_server';

		return is_dir($dir) ? $dir . '/' . self::QUERY_FILE : null;
	}
}
