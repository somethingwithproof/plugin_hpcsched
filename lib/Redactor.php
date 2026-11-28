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
 * Removes secrets from text before it is logged or stored (REQ-CRED-001).
 *
 * Known secrets are replaced wherever they appear, along with their URL and
 * JSON escaped forms. Token headers and JWT-shaped strings are removed by
 * pattern too, so a token the redactor was never told about is still
 * masked. Adapted from plugin_cloud.
 */
final class Redactor {
	/** Mask constant for Redactor. */
	private const MASK = '[redacted]';

	/**
	 * Each pattern masks its named group "v", or the whole match without one.
	 */
	private const PATTERNS = [
		'/\b(?:x-slurm-user-token|authorization|cookie|set-cookie)\s*:\s*(?<v>[^\r\n]*)/i',
		'/\b(?:bearer|basic)\s+(?<v>[A-Za-z0-9._~+\/=-]+)/i',
		'/\bSLURM_JWT=(?<v>\S*)/',
		'/\beyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]*/',
		'/"(?:token|jwt|access_token)"\s*:\s*"(?<v>(?:[^"\\\\]|\\\\.)*)"/i',
	];

	/** @var list<string> */
	private array $known = [];

	/**
	 * Add.
	 *
	 * @param Secret $secret
	 * @return void
	 */
	public function add(Secret $secret): void {
		$value = $secret->reveal();

		if ($value === '') {
			return;
		}

		foreach ([$value, rawurlencode($value), urlencode($value), substr((string) json_encode($value), 1, -1)] as $form) {
			if ($form !== '' && !in_array($form, $this->known, true)) {
				$this->known[] = $form;
			}
		}

		// Longest first, so a secret containing another is masked whole.
		usort($this->known, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
	}

	/**
	 * Clean.
	 *
	 * @param string $text
	 * @return string
	 */
	public function clean(string $text): string {
		// Every span is found in the original text and masked once at the
		// end, so one pass cannot hide material from the next.
		$spans = [];

		foreach (self::PATTERNS as $pattern) {
			if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
				return self::MASK;
			}

			foreach ($matches as $match) {
				// An unmatched optional group reports offset -1.
				$found   = isset($match['v']) && $match['v'][1] >= 0 ? $match['v'] : $match[0];
				$spans[] = [$found[1], $found[1] + strlen($found[0])];
			}
		}

		foreach ($this->known as $form) {
			for ($at = strpos($text, $form); $at !== false; $at = strpos($text, $form, $at + 1)) {
				$spans[] = [$at, $at + strlen($form)];
			}
		}

		if ($spans === []) {
			return $text;
		}

		sort($spans);

		/** @var list<array{int, int}> $merged */
		$merged = [];

		foreach ($spans as [$start, $stop]) {
			$last = count($merged) - 1;

			if ($last >= 0 && $start <= $merged[$last][1]) {
				$merged[$last] = [$merged[$last][0], max($merged[$last][1], $stop)];
			} else {
				$merged[] = [$start, $stop];
			}
		}

		$clean = '';
		$from  = 0;

		foreach ($merged as [$start, $stop]) {
			$clean .= substr($text, $from, $start - $from) . self::MASK;
			$from   = $stop;
		}

		return $clean . substr($text, $from);
	}
}
