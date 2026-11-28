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
 * Four-tier linear-time matcher from Slurm compute nodes to Cacti host
 * records (issue #9):
 *   1. Exact hostname match (node hostname == host.hostname)
 *   2. Exact address match (node address == host.hostname)
 *   3. Exact description/alias match (node name or hostname == host.description)
 *   4. First DNS label match when neither side is an IP address
 *
 * Ambiguous multi-matches and devices claimed by multiple nodes are marked
 * ambiguous rather than guessed at.
 */
final class NodeMatcher {
	/** Tier hostname constant for NodeMatcher. */
	public const TIER_HOSTNAME    = 1;
	/** Tier address constant for NodeMatcher. */
	public const TIER_ADDRESS     = 2;
	/** Tier description constant for NodeMatcher. */
	public const TIER_DESCRIPTION = 3;
	/** Tier short constant for NodeMatcher. */
	public const TIER_SHORT       = 4;

	/**
	 * @param list<array{name: string, hostname?: string, address?: string}> $nodes
	 * @param list<array{id: int, hostname: string, description: string}>    $devices
	 *
	 * @return array<string, NodeMatch> keyed by Slurm node name
	 */
	public function match(array $nodes, array $devices): array {
		$unique = [];

		foreach ($nodes as $row) {
			$name = $row['name'];

			if ($name !== '' && !isset($unique[$name])) {
				$unique[$name] = $row;
			}
		}

		/** @var array<string, list<int>> $byHost */
		$byHost = [];
		/** @var array<string, list<int>> $byDesc */
		$byDesc = [];
		/** @var array<string, list<int>> $byShort */
		$byShort = [];

		$add = static function (array &$index, string $key, int $id): void {
			if ($key === '') {
				return;
			}

			$bucket = $index[$key] ?? [];

			if (count($bucket) < 2 && !in_array($id, $bucket, true)) {
				$index[$key][] = $id;
			}
		};

		foreach ($devices as $device) {
			$h = self::norm($device['hostname']);
			$add($byHost, $h, $device['id']);
			$add($byDesc, self::norm($device['description']), $device['id']);
			$short = self::shortLabel($h);

			if ($short !== null) {
				$add($byShort, $short, $device['id']);
			}
		}

		/** @var array<string, array{state: NodeMatchState, device: ?int, tier: ?int}> $candidates */
		$candidates = [];

		foreach ($unique as $name => $node) {
			$nameKey  = (string) $name;
			$rawHost  = trim($node['hostname'] ?? '');
			$rawAddr  = trim($node['address'] ?? '');
			$host     = self::norm($rawHost !== '' ? $rawHost : $nameKey);
			$resolved = ['state' => NodeMatchState::Unmatched, 'device' => null, 'tier' => null];

			// Evaluate tiers lazily in precedence order so a Tier 1 hostname hit
			// avoids address normalization, description merging, and IP validation.
			$tier1 = $byHost[$host] ?? [];

			if ($tier1 !== []) {
				$resolved = self::resolveTier(self::TIER_HOSTNAME, $tier1);
			} else {
				$addr  = $rawAddr !== '' ? self::norm($rawAddr) : $host;
				$tier2 = $byHost[$addr] ?? [];

				if ($tier2 !== []) {
					$resolved = self::resolveTier(self::TIER_ADDRESS, $tier2);
				} else {
					$normName = self::norm($nameKey);
					$descA    = $byDesc[$normName] ?? [];
					$descB    = $normName !== $host ? ($byDesc[$host] ?? []) : [];
					$tier3    = $descA === [] ? $descB : ($descB === [] ? $descA : array_values(array_unique(array_merge($descA, $descB))));

					if ($tier3 !== []) {
						$resolved = self::resolveTier(self::TIER_DESCRIPTION, $tier3);
					} elseif (!str_contains($host, '.') && !self::isIp($host)) {
						$tier4 = $byShort[$host] ?? [];

						if ($tier4 !== []) {
							$resolved = self::resolveTier(self::TIER_SHORT, $tier4);
						}
					}
				}
			}

			$candidates[$nameKey] = $resolved;
		}

		/** @var array<int, list<string>> $claims */
		$claims = [];

		foreach ($candidates as $name => $cand) {
			if ($cand['state'] === NodeMatchState::Proposed && $cand['device'] !== null) {
				$claims[$cand['device']][] = (string) $name;
			}
		}

		foreach ($claims as $claimants) {
			if (count($claimants) > 1) {
				foreach ($claimants as $name) {
					$tier              = $candidates[$name]['tier'];
					$candidates[$name] = ['state' => NodeMatchState::Ambiguous, 'device' => null, 'tier' => $tier];
				}
			}
		}

		$result = [];

		foreach ($candidates as $name => $cand) {
			$nameKey          = (string) $name;
			$result[$nameKey] = new NodeMatch($nameKey, $cand['state'], $cand['device'], $cand['tier']);
		}

		return $result;
	}

	/**
	 * Convert a non-empty deduplicated candidate ID list for one matching tier
	 * into a Proposed or Ambiguous candidate record.
	 *
	 * @param int            $tier matched tier constant
	 * @param non-empty-list<int> $ids matched Cacti device IDs
	 *
	 * @return array{state: NodeMatchState, device: ?int, tier: int}
	 */
	private static function resolveTier(int $tier, array $ids): array {
		if (count($ids) === 1) {
			return ['state' => NodeMatchState::Proposed, 'device' => $ids[0], 'tier' => $tier];
		}

		return ['state' => NodeMatchState::Ambiguous, 'device' => null, 'tier' => $tier];
	}

	/**
	 * Norm.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function norm(string $value): string {
		return strtolower(rtrim(trim($value), '.'));
	}

	/**
	 * Check whether ip.
	 *
	 * @param string $value
	 * @return bool
	 */
	private static function isIp(string $value): bool {
		return filter_var($value, FILTER_VALIDATE_IP) !== false;
	}

	/**
	 * Short label from an already-normalized hostname.
	 *
	 * @param string $value normalized lowercase hostname
	 * @return ?string
	 */
	private static function shortLabel(string $value): ?string {
		if ($value === '' || !str_contains($value, '.') || self::isIp($value)) {
			return null;
		}

		return explode('.', $value, 2)[0];
	}
}
