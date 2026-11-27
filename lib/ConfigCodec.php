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
 * Reads and writes HPC Scheduler CasC configuration documents in YAML or XML.
 */
final class ConfigCodec {
	/** Yaml constant for ConfigCodec. */
	public const YAML      = 'yaml';
	/** Xml constant for ConfigCodec. */
	public const XML       = 'xml';
	/** Current schema version number. */
	public const VERSION   = 1;
	/** Maximum bytes. */
	public const MAX_BYTES = 4194304;

	/**
	 * Check whether format.
	 *
	 * @param string $format
	 * @return bool
	 */
	public static function isFormat(string $format): bool {
		return $format === self::YAML || $format === self::XML;
	}

	/**
	 * Parse.
	 *
	 * @param string $format
	 * @param string $text
	 * @return ConfigTree
	 * @throws ConfigException
	 */
	public static function parse(string $format, string $text): ConfigTree {
		return match ($format) {
			self::YAML => YamlReader::parse($text, self::MAX_BYTES),
			self::XML  => ConfigXml::parse($text, self::MAX_BYTES),
			default    => throw new ConfigException(sprintf('Unknown format "%s"; use yaml or xml', $format)),
		};
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	public static function write(string $format, array $data): string {
		return match ($format) {
			self::YAML => YamlWriter::write($data),
			self::XML  => ConfigXml::write($data),
			default    => throw new ConfigException(sprintf('Unknown format "%s"; use yaml or xml', $format)),
		};
	}

	/**
	 * Read file.
	 *
	 * @param string $path
	 * @return string
	 * @throws ConfigException
	 */
	public static function readFile(string $path): string {
		if (!is_file($path) || !is_readable($path)) {
			throw new ConfigException(sprintf('Cannot read %s', $path));
		}

		$size = filesize($path);

		if ($size === false || $size > self::MAX_BYTES) {
			throw new ConfigException(sprintf('%s exceeds the %d byte limit', $path, self::MAX_BYTES));
		}

		$text = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

		if ($text === false || strlen($text) > self::MAX_BYTES) {
			throw new ConfigException(sprintf('Cannot read %s within the %d byte limit', $path, self::MAX_BYTES));
		}

		return $text;
	}

	/**
	 * @param resource $stream
	 */
	public static function readStream($stream): string {
		$text = stream_get_contents($stream, self::MAX_BYTES + 1);

		if ($text === false || strlen($text) > self::MAX_BYTES) {
			throw new ConfigException(sprintf('Input exceeds the %d byte limit', self::MAX_BYTES));
		}

		return $text;
	}
}
