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
 * Strict, bounded YAML subset reader for CasC documents.
 */
final class YamlReader {
	/** Maximum bytes. */
	public const MAX_BYTES = 4194304;
	/** Maximum depth. */
	public const MAX_DEPTH = 8;
	/** Maximum line. */
	public const MAX_LINE  = 16384;

	/** @var array<int, string> */
	private array $raw = [];
	/** Pos state for YamlReader. */
	private int $pos   = 0;
	/** @var array<string, int> */
	private array $lineMap = [];

	/**
	 * Construct a new YamlReader instance.
	 *
	 * @param int $maxDepth
	 */
	private function __construct(private readonly int $maxDepth) {
	}

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

		if (str_starts_with($text, "\xEF\xBB\xBF")) {
			$text = substr($text, 3);
		}

		if (preg_match('//u', $text) !== 1) {
			throw new ConfigException('The file is not valid UTF-8');
		}

		return (new self($maxDepth))->run($text);
	}

	/**
	 * Run.
	 *
	 * @param string $text
	 * @return ConfigTree
	 * @throws ConfigException
	 */
	private function run(string $text): ConfigTree {
		$text = str_replace("\r\n", "\n", $text);

		if (str_contains($text, "\r")) {
			throw new ConfigException('A carriage return outside a line ending is not allowed');
		}

		$this->raw = explode("\n", $text);

		foreach ($this->raw as $i => $line) {
			if (strlen($line) > self::MAX_LINE) {
				throw new ConfigException(sprintf('The line is longer than %d bytes', self::MAX_LINE), $i + 1);
			}

			if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\x{85}|\x{2028}|\x{2029}|\x{FEFF}/u', $line) === 1) {
				throw new ConfigException('A control character is not allowed', $i + 1);
			}
		}

		$first = $this->peek(true);

		if ($first !== null && $first[2] === '---') {
			$this->pos++;
		}

		$cur = $this->peek();

		if ($cur === null) {
			return new ConfigTree([], []);
		}

		$data  = $this->block($cur[1], '', 1);
		$extra = $this->peek();

		if ($extra !== null) {
			throw new ConfigException('Unexpected content after the end of the document', $extra[0]);
		}

		if (!is_array($data)) {
			throw new ConfigException('The document must be a map or a list', $cur[0]);
		}

		return new ConfigTree($data, $this->lineMap);
	}

	/**
	 * @return array{0: int, 1: int, 2: string}|null
	 */
	private function peek(bool $allowMarker = false): ?array {
		while ($this->pos < count($this->raw)) {
			$line = rtrim($this->raw[$this->pos], ' ');
			$trim = ltrim($line, ' ');

			if (trim($trim, "\t") === '' || $trim[0] === '#') {
				$this->pos++;

				continue;
			}

			$indent = strlen($line) - strlen($trim);
			$no     = $this->pos + 1;

			if ($trim[0] === "\t") {
				throw new ConfigException('A tab cannot be used for indentation', $no);
			}

			if ($indent === 0 && preg_match('/^(---|\.\.\.)(\s|$)/', $trim) === 1 && (!$allowMarker || $trim !== '---')) {
				throw new ConfigException('Only one document is supported', $no);
			}

			if ($indent === 0 && $trim[0] === '%') {
				throw new ConfigException('Directives are not supported', $no);
			}

			return [$no, $indent, $trim];
		}

		return null;
	}

	/**
	 * Block.
	 *
	 * @param int $indent
	 * @param string $path
	 * @param int $depth
	 * @return mixed
	 */
	private function block(int $indent, string $path, int $depth): mixed {
		$cur = $this->peek();

		if ($cur === null) {
			return null;
		}

		return self::isItem($cur[2]) ? $this->sequence($indent, $path, $depth) : $this->map($indent, $path, $depth);
	}

	/**
	 * Guard depth.
	 *
	 * @param int $depth
	 * @return void
	 * @throws ConfigException
	 */
	private function guardDepth(int $depth): void {
		if ($depth > $this->maxDepth) {
			throw new ConfigException(sprintf('Nesting is deeper than %d levels', $this->maxDepth), $this->pos + 1);
		}
	}

	/**
	 * Check whether item.
	 *
	 * @param string $text
	 * @return bool
	 */
	private static function isItem(string $text): bool {
		return $text === '-' || str_starts_with($text, '- ');
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function map(int $indent, string $path, int $depth): array {
		$this->guardDepth($depth);

		$result = [];
		$seen   = [];

		while (($cur = $this->peek()) !== null) {
			[$no, $ind, $text] = $cur;

			if ($ind < $indent) {
				break;
			}

			if ($ind > $indent) {
				throw new ConfigException('Unexpected indentation', $no);
			}

			if (self::isItem($text)) {
				throw new ConfigException('A list item is not allowed where a key is expected', $no);
			}

			$split = $this->splitKey($text, $no);

			if ($split === null) {
				throw new ConfigException('Expected "key: value"', $no);
			}

			[$key, $rest] = $split;

			if (isset($seen[$key])) {
				throw new ConfigException(sprintf('Duplicate key "%s"', $key), $no);
			}

			$seen[$key]          = $no;
			$sub                 = $path === '' ? $key : $path . '/' . $key;
			$this->lineMap[$sub] = $no;
			$this->pos++;

			$result[$key] = $this->value($rest, $no, $indent, $sub, $depth, true);
		}

		return $result;
	}

	/**
	 * @return list<mixed>
	 */
	private function sequence(int $indent, string $path, int $depth): array {
		$this->guardDepth($depth);

		$result = [];

		while (($cur = $this->peek()) !== null) {
			[$no, $ind, $text] = $cur;

			if ($ind < $indent) {
				break;
			}

			if ($ind > $indent) {
				throw new ConfigException('Unexpected indentation', $no);
			}

			if (!self::isItem($text)) {
				break;
			}

			$after               = ltrim(substr($text, 1), ' ');
			$col                 = $indent + strlen($text) - strlen($after);
			$sub                 = $path . '/' . count($result);
			$this->lineMap[$sub] = $no;

			if ($after !== '' && $after[0] !== '#' && self::isItem($after)) {
				throw new ConfigException('Nested inline lists are not supported', $no);
			}

			if ($after !== '' && $after[0] !== '#' && $this->splitKey($after, $no) !== null) {
				$this->raw[$no - 1] = str_repeat(' ', $col) . $after;
				$result[]           = $this->map($col, $sub, $depth + 1);

				continue;
			}

			$this->pos++;
			$result[] = $this->value($after, $no, $indent, $sub, $depth, false);
		}

		return $result;
	}

	/**
	 * Value.
	 *
	 * @param string $rest
	 * @param int $no
	 * @param int $indent
	 * @param string $path
	 * @param int $depth
	 * @param bool $inMap
	 * @return mixed
	 * @throws ConfigException
	 */
	private function value(string $rest, int $no, int $indent, string $path, int $depth, bool $inMap): mixed {
		if ($rest === '' || $rest[0] === '#') {
			$next = $this->peek();

			if ($next === null) {
				return null;
			}

			if ($next[1] > $indent) {
				return $this->block($next[1], $path, $depth + 1);
			}

			if ($inMap && $next[1] === $indent && self::isItem($next[2])) {
				return $this->sequence($indent, $path, $depth + 1);
			}

			return null;
		}

		$first   = $rest[0];
		$refusal = match (true) {
			$first === '&' || $first === '*' || $first === '!' => 'Anchors, aliases, and tags are not supported',
			$first === '[' || $first === '{'                   => 'Flow collections are not supported; use block lists or maps',
			$first === '>' || $first === '|'                   => 'Block scalars are not supported',
			$first === '%' || $first === '@' || $first === '`' => 'Plain scalar cannot start with "' . $first . '"',
			default                                            => null,
		};

		if ($refusal !== null) {
			throw new ConfigException($refusal, $no);
		}

		return $this->scalar($rest, $no);
	}

	/**
	 * @return array{0: string, 1: string}|null
	 */
	private function splitKey(string $text, int $no): ?array {
		$first = $text[0];

		if ($first === '"' || $first === "'") {
			[$key, $end] = $this->quoted($text, 0, $no);
			$tail        = ltrim(substr($text, $end), ' ');

			if ($tail === '' || $tail[0] !== ':' || (strlen($tail) > 1 && $tail[1] !== ' ')) {
				return null;
			}

			return [$key, ltrim(substr($tail, 1), ' ')];
		}

		$head = $text;

		if (preg_match('/\s#/', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
			$head = substr($text, 0, (int) $m[0][1]);
		}

		if (preg_match('/:(\s|$)/', $head, $m, PREG_OFFSET_CAPTURE) !== 1) {
			return null;
		}

		$at  = (int) $m[0][1];
		$key = rtrim(substr($head, 0, $at));

		if ($key === '') {
			throw new ConfigException('An empty key is not allowed', $no);
		}

		if ($key === '<<' || str_contains('&*![{?%@`|>#', $key[0])) {
			throw new ConfigException(sprintf('Unsupported key "%s"', $key), $no);
		}

		return [$key, ltrim(substr($text, $at + 1), ' ')];
	}

	/**
	 * Scalar.
	 *
	 * @param string $text
	 * @param int $no
	 * @return null|bool|int|string
	 * @throws ConfigException
	 */
	private function scalar(string $text, int $no): null|bool|int|string {
		if ($text[0] === '"' || $text[0] === "'") {
			[$value, $end] = $this->quoted($text, 0, $no);
			$tail          = substr($text, $end);

			if (trim($tail, ' ') !== '' && preg_match('/^\s+#/', $tail) !== 1) {
				throw new ConfigException('Unexpected text after a quoted scalar', $no);
			}

			return $value;
		}

		if (preg_match('/\s#/', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
			$text = rtrim(substr($text, 0, (int) $m[0][1]));
		}

		if (preg_match('/:(\s|$)/', $text) === 1) {
			throw new ConfigException('Quote scalars containing ": "', $no);
		}

		[$type, $value] = YamlScalar::classify($text);

		if ($type === YamlScalar::AMBIGUOUS) {
			throw new ConfigException(sprintf('Ambiguous plain scalar "%s"; quote it', $text), $no);
		}

		return $value;
	}

	/**
	 * @return array{0: string, 1: int}
	 */
	private function quoted(string $text, int $start, int $no): array {
		$quote = $text[$start];
		$out   = '';
		$len   = strlen($text);

		for ($i = $start + 1; $i < $len; $i++) {
			$c = $text[$i];

			if ($quote === "'") {
				if ($c !== "'") {
					$out .= $c;

					continue;
				}

				if ($i + 1 < $len && $text[$i + 1] === "'") {
					$out .= "'";
					$i++;

					continue;
				}

				return [$out, $i + 1];
			}

			if ($c === '"') {
				return [$out, $i + 1];
			}

			if ($c !== '\\') {
				$out .= $c;

				continue;
			}

			$i++;

			if ($i >= $len) {
				break;
			}

			$e      = $text[$i];
			$simple = ['\\' => '\\', '"' => '"', '/' => '/', 'n' => "\n", 't' => "\t", 'r' => "\r"];

			if (isset($simple[$e])) {
				$out .= $simple[$e];

				continue;
			}

			throw new ConfigException(sprintf('Unsupported escape "\\%s"', $e), $no);
		}

		throw new ConfigException('Unterminated quoted scalar', $no);
	}
}
