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

// Test code: refuse to run from a web request.
PHP_SAPI === 'cli' || exit(1);

/**
 * Scan all production PHP files with PHP's built-in token_get_all() and
 * verify that every class, interface, trait, enum, constant, property,
 * method, and named function carries a PHPDoc block.
 *
 * @return list<string> list of missing-DocBlock diagnostics
 */
function hpcsched_find_missing_phpdoc(): array {
	$root     = dirname(__DIR__, 2);
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	$files    = [];

	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen($root) + 1);

		if ($file->getExtension() === 'php' && !str_starts_with($relative, 'tests/') && !str_starts_with($relative, '.git/')) {
			$files[] = $relative;
		}
	}

	sort($files);
	$missing = [];

	foreach ($files as $relative) {
		$tokens     = token_get_all((string) file_get_contents($root . '/' . $relative));
		$count      = count($tokens);
		$braceDepth = 0;
		$classStack = [];
		$pendingDoc = false;
		$parenDepth = 0;

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			$id    = is_array($token) ? $token[0] : null;
			$text  = is_array($token) ? $token[1] : $token;
			$line  = is_array($token) ? $token[2] : 0;

			if ($id === T_DOC_COMMENT) {
				$pendingDoc = true;

				continue;
			}

			if ($id === T_WHITESPACE || $id === T_COMMENT) {
				continue;
			}

			if ($text === '(') {
				$parenDepth++;

				continue;
			}

			if ($text === ')') {
				$parenDepth = max(0, $parenDepth - 1);

				continue;
			}

			if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
				$braceDepth++;
				$pendingDoc = false;

				continue;
			}

			if ($text === '}') {
				$braceDepth = max(0, $braceDepth - 1);

				if ($classStack !== [] && end($classStack) === $braceDepth) {
					array_pop($classStack);
				}

				$pendingDoc = false;

				continue;
			}

			if ($text === ';') {
				$pendingDoc = false;

				continue;
			}

			if ($id === T_ATTRIBUTE) {
				$attrDepth = 1;

				for ($i++; $i < $count && $attrDepth > 0; $i++) {
					$at = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

					if ($at === '[') {
						$attrDepth++;
					} elseif ($at === ']') {
						$attrDepth--;
					}
				}

				$i--;

				continue;
			}

			if (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY, T_VAR], true)) {
				continue;
			}

			if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
				$prevIdx = $i - 1;

				while ($prevIdx >= 0 && is_array($tokens[$prevIdx]) && in_array($tokens[$prevIdx][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
					$prevIdx--;
				}

				$prevId = ($prevIdx >= 0 && is_array($tokens[$prevIdx])) ? $tokens[$prevIdx][0] : null;

				if ($prevId === T_DOUBLE_COLON || $prevId === T_NEW) {
					$pendingDoc = false;

					continue;
				}

				$nameIdx = $i + 1;

				while ($nameIdx < $count && is_array($tokens[$nameIdx]) && $tokens[$nameIdx][0] === T_WHITESPACE) {
					$nameIdx++;
				}

				$name = ($nameIdx < $count && is_array($tokens[$nameIdx])) ? $tokens[$nameIdx][1] : '<anonymous>';

				if (!$pendingDoc) {
					$missing[] = sprintf('%s:%d missing PHPDoc for %s %s', $relative, $line, $text, $name);
				}

				$classStack[] = $braceDepth;
				$pendingDoc   = false;

				continue;
			}

			if ($id === T_FUNCTION) {
				$j = $i + 1;

				while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
					$j++;
				}

				if ($j < $count && $tokens[$j] === '&') {
					$j++;

					while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
						$j++;
					}
				}

				if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
					if (!$pendingDoc) {
						$missing[] = sprintf('%s:%d missing PHPDoc for function %s()', $relative, $line, $tokens[$j][1]);
					}
				}

				$pendingDoc = false;

				continue;
			}

			if ($id === T_CONST && $parenDepth === 0) {
				$j = $i + 1;

				while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
					$j++;
				}

				$name = ($j < $count && is_array($tokens[$j])) ? $tokens[$j][1] : '<const>';

				if (!$pendingDoc) {
					$missing[] = sprintf('%s:%d missing PHPDoc for const %s', $relative, $line, $name);
				}

				$pendingDoc = false;

				continue;
			}

			$inClassBody = ($classStack !== [] && end($classStack) + 1 === $braceDepth);

			if ($inClassBody && $parenDepth === 0 && $id === T_VARIABLE) {
				if (!$pendingDoc) {
					$missing[] = sprintf('%s:%d missing PHPDoc for property %s', $relative, $line, $text);
				}

				$pendingDoc = false;

				continue;
			}
		}
	}

	return $missing;
}

it('enforces 100% PHPDoc coverage across every production PHP symbol', function () {
	$missing = hpcsched_find_missing_phpdoc();

	expect($missing)->toBe([], "Missing PHPDoc blocks:\n" . implode("\n", array_slice($missing, 0, 25)));
});
