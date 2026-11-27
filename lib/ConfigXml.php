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
 * XXE-safe XML reader and writer for HPC Scheduler CasC documents.
 */
final class ConfigXml {
	/** Maximum bytes. */
	public const MAX_BYTES = 4194304;
	/** Maximum depth. */
	public const MAX_DEPTH = 8;

	/** Root constant for ConfigXml. */
	private const ROOT = 'hpcsched-config';

	/**
	 * Parse.
	 *
	 * @param string $text
	 * @param int $maxBytes
	 * @param int $maxDepth
	 * @return ConfigTree
	 * @throws ConfigException
	 */
	public static function parse(string $text, int $maxBytes = self::MAX_BYTES, int $maxDepth = self::MAX_DEPTH): ConfigTree {
		if (strlen($text) > $maxBytes) {
			throw new ConfigException(sprintf('The file is larger than the %d byte limit', $maxBytes));
		}

		if (preg_match('//u', $text) !== 1) {
			throw new ConfigException('The file is not valid UTF-8');
		}

		if (preg_match('/<!(DOCTYPE|ENTITY)/i', $text) === 1) {
			throw new ConfigException('A DOCTYPE or entity declaration is not allowed');
		}

		$previous = libxml_use_internal_errors(true);

		try {
			$dom = new \DOMDocument();

			if (!$dom->loadXML($text, LIBXML_NONET | LIBXML_NOBLANKS)) {
				$err = libxml_get_last_error();

				throw new ConfigException(trim($err === false ? 'XML is not well formed' : $err->message), $err === false || $err->line < 1 ? null : $err->line);
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($dom->doctype !== null) {
			throw new ConfigException('A DOCTYPE is not allowed');
		}

		$root = $dom->documentElement;

		if ($root === null || $root->nodeName !== self::ROOT) {
			throw new ConfigException(sprintf('Root element must be <%s>', self::ROOT), $root?->getLineNo());
		}

		self::checkDepth($root, 1, $maxDepth);

		$lines = [];
		$data  = ['schema_version' => self::parseInt($root->getAttribute('schema_version'), 'schema_version', $root->getLineNo())];

		foreach (self::elements($root) as $section) {
			$name = $section->nodeName;

			if ($name === 'settings') {
				$settings = [];

				foreach (self::elements($section) as $el) {
					if ($el->nodeName !== 'setting') {
						throw new ConfigException('Expected <setting>', $el->getLineNo());
					}

					$key                       = $el->getAttribute('name');
					$settings[$key]            = self::coerceScalar($el->textContent, $el->getAttribute('type'));
					$lines['settings/' . $key] = $el->getLineNo();
				}

				$data['settings'] = $settings;
			} elseif ($name === 'clusters') {
				$clusters = [];

				foreach (self::elements($section) as $el) {
					if ($el->nodeName !== 'cluster') {
						throw new ConfigException('Expected <cluster>', $el->getLineNo());
					}

					$entry = [];

					foreach ($el->attributes as $attr) {
						$attrName = $attr->nodeName;
						$val      = $attr->nodeValue ?? '';

						if (in_array($attrName, ['poller_id', 'collect_interval'], true)) {
							$entry[$attrName] = self::parseInt($val, $attrName, $el->getLineNo());
						} elseif (in_array($attrName, ['collect_reservations', 'collect_shares'], true)) {
							$entry[$attrName] = $val === 'true';
						} else {
							$entry[$attrName] = $val;
						}
					}

					$lines['clusters/' . count($clusters)] = $el->getLineNo();
					$clusters[]                            = $entry;
				}

				$data['clusters'] = $clusters;
			} else {
				throw new ConfigException(sprintf('Unknown section <%s>', $name), $section->getLineNo());
			}
		}

		return new ConfigTree($data, $lines);
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	public static function write(array $data): string {
		$dom               = new \DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;

		$root = $dom->createElement(self::ROOT);
		$dom->appendChild($root);
		$root->setAttribute('schema_version', self::scalar($data['schema_version'] ?? 1));

		$settings = $data['settings'] ?? [];

		if (is_array($settings) && $settings !== []) {
			$parent = $root->appendChild($dom->createElement('settings'));

			foreach ($settings as $name => $value) {
				$el = $dom->createElement('setting');
				$el->setAttribute('name', (string) $name);
				$el->setAttribute('type', is_bool($value) ? 'bool' : (is_int($value) ? 'int' : 'string'));
				$el->appendChild($dom->createTextNode(self::scalar($value)));
				$parent?->appendChild($el);
			}
		}

		$clusters = $data['clusters'] ?? [];

		if (is_array($clusters) && $clusters !== []) {
			$parent = $root->appendChild($dom->createElement('clusters'));

			foreach ($clusters as $cluster) {
				if (!is_array($cluster)) {
					continue;
				}

				$el = $dom->createElement('cluster');

				foreach ($cluster as $k => $v) {
					$el->setAttribute((string) $k, self::scalar($v));
				}

				$parent?->appendChild($el);
			}
		}

		$xml = $dom->saveXML();

		if ($xml === false) {
			throw new ConfigException('Unable to serialize XML');
		}

		return $xml;
	}

	/**
	 * Coerce scalar.
	 *
	 * @param string $raw
	 * @param string $type
	 * @return bool|int|string
	 * @throws ConfigException
	 */
	private static function coerceScalar(string $raw, string $type): bool|int|string {
		return match ($type) {
			'bool'  => $raw === 'true',
			'int'   => preg_match('/^-?[0-9]{1,18}$/', $raw) === 1 ? (int) $raw : throw new ConfigException('Expected integer value'),
			default => $raw,
		};
	}

	/**
	 * Parse int.
	 *
	 * @param string $raw
	 * @param string $field
	 * @param int $line
	 * @return int
	 * @throws ConfigException
	 */
	private static function parseInt(string $raw, string $field, int $line): int {
		if (preg_match('/^-?[0-9]{1,18}$/', $raw) !== 1) {
			throw new ConfigException(sprintf('Attribute "%s" must be an integer', $field), $line);
		}

		return (int) $raw;
	}

	/**
	 * Scalar.
	 *
	 * @param mixed $value
	 * @return string
	 * @throws ConfigException
	 */
	private static function scalar(mixed $value): string {
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (!is_int($value) && !is_string($value)) {
			throw new ConfigException('Only strings, integers, and booleans can be written');
		}

		return (string) $value;
	}

	/**
	 * Check depth.
	 *
	 * @param \DOMNode $node
	 * @param int $depth
	 * @param int $max
	 * @return void
	 * @throws ConfigException
	 */
	private static function checkDepth(\DOMNode $node, int $depth, int $max): void {
		if ($depth > $max) {
			throw new ConfigException(sprintf('Nesting exceeds %d levels', $max), $node->getLineNo());
		}

		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				self::checkDepth($child, $depth + 1, $max);
			}
		}
	}

	/**
	 * @return list<\DOMElement>
	 */
	private static function elements(\DOMElement $parent): array {
		$out = [];

		foreach ($parent->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				$out[] = $child;
			}
		}

		return $out;
	}
}
