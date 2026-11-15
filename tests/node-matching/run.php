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

/*
 * Offline prototype for issue #24. It runs the proposed node-to-device
 * matching tiers over fixtures.json and times 10,000 nodes against 10,000
 * devices. It uses no Cacti code, database, or network. Run: php run.php
 */

declare(strict_types=1);

const TIER_HOSTNAME    = 1; // node hostname == device hostname
const TIER_ADDRESS     = 2; // node address == device hostname
const TIER_DESCRIPTION = 3; // node name or hostname == device description
const TIER_SHORT       = 4; // first DNS label equal, neither side an IP

function norm(string $s): string {
	return strtolower(rtrim(trim($s), '.'));
}

function is_ip(string $s): bool {
	return filter_var($s, FILTER_VALIDATE_IP) !== false;
}

function short_label(string $s): ?string {
	$s = norm($s);
	if ($s === '' || is_ip($s) || strpos($s, '.') === false) {
		return null;
	}

	return explode('.', $s, 2)[0];
}

/**
 * Collapse repeated per-partition rows into one record per node name.
 *
 * @param array<int, array<string, string>> $rows
 * @return array<string, array<string, string>>
 */
function unique_nodes(array $rows): array {
	$out = [];
	foreach ($rows as $r) {
		$out[$r['name']] ??= $r;
	}

	return $out;
}

/**
 * @param array<string, array<string, string>> $nodes keyed by node name
 * @param array<int, array<string, mixed>> $devices
 * @return array<string, array{state: string, device: ?int, tier: ?int}>
 */
function match_nodes(array $nodes, array $devices): array {
	// Index devices once per key, so cost is linear in nodes plus devices.
	// Each bucket keeps at most two distinct ids: two already mean ambiguous,
	// so a heavily duplicated key cannot make the per-node work grow with it.
	$byHost = $byDesc = $byShort = [];
	$add = static function (array &$index, string $key, int $id): void {
		$bucket = $index[$key] ?? [];
		if (count($bucket) < 2 && !in_array($id, $bucket, true)) {
			$index[$key][] = $id;
		}
	};
	foreach ($devices as $d) {
		$h = norm((string) $d['hostname']);
		$add($byHost, $h, $d['id']);
		$add($byDesc, norm((string) $d['description']), $d['id']);
		$s = short_label($h);
		if ($s !== null) {
			$add($byShort, $s, $d['id']);
		}
	}

	$cand = [];
	foreach ($nodes as $name => $n) {
		// PHP turns a numeric-string array key such as "10" into an int.
		$name = (string) $name;
		$host = norm($n['hostname'] ?? $name);
		$addr = norm($n['address'] ?? $host);
		$tiers = [
			TIER_HOSTNAME    => $byHost[$host] ?? [],
			TIER_ADDRESS     => $byHost[$addr] ?? [],
			TIER_DESCRIPTION => array_merge($byDesc[norm($name)] ?? [], $byDesc[$host] ?? []),
			TIER_SHORT       => (!is_ip($host) && strpos($host, '.') === false) ? ($byShort[$host] ?? []) : [],
		];
		$cand[$name] = ['state' => 'unmatched', 'device' => null, 'tier' => null];
		foreach ($tiers as $t => $ids) {
			$ids = array_values(array_unique($ids));
			if (count($ids) === 1) {
				$cand[$name] = ['state' => 'proposed', 'device' => $ids[0], 'tier' => $t];
				break;
			}
			if (count($ids) > 1) {
				$cand[$name] = ['state' => 'ambiguous', 'device' => null, 'tier' => $t];
				break;
			}
		}
	}

	// A device claimed by several nodes makes every claimant ambiguous.
	$claims = [];
	foreach ($cand as $name => $c) {
		if ($c['state'] === 'proposed') {
			$claims[$c['device']][] = $name;
		}
	}
	foreach ($claims as $names) {
		if (count($names) > 1) {
			foreach ($names as $name) {
				$cand[$name]['state'] = 'ambiguous';
				$cand[$name]['device'] = null;
			}
		}
	}

	return $cand;
}

$fixtures = json_decode((string) file_get_contents(__DIR__ . '/fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$fail = 0;
foreach ($fixtures['cases'] as $case) {
	$got = match_nodes(unique_nodes($case['nodes']), $case['devices']);
	foreach ($case['expect'] as $node => $want) {
		$g = $got[$node] ?? null;
		$ok = $g !== null && $g['state'] === $want['state'] && $g['device'] === ($want['device'] ?? null) && $g['tier'] === ($want['tier'] ?? null);
		printf("%s  %-28s %s\n", $ok ? 'ok  ' : 'FAIL', $case['id'], $node);
		if (!$ok) {
			$fail++;
			fwrite(STDERR, '  got ' . json_encode($g) . ' want ' . json_encode($want) . "\n");
		}
	}
}

foreach ([1000, 10000] as $n) {
	$nodes = $devices = [];
	for ($i = 0; $i < $n; $i++) {
		$nodes[] = ['name' => sprintf('n%05d', $i), 'hostname' => sprintf('n%05d', $i), 'address' => sprintf('10.%d.%d.%d', $i >> 16, ($i >> 8) & 255, $i & 255)];
		$devices[] = ['id' => $i + 1, 'hostname' => sprintf('n%05d.cluster.example.org', $i), 'description' => sprintf('compute %d', $i)];
	}
	$t0 = hrtime(true);
	$res = match_nodes(unique_nodes($nodes), $devices);
	$ms = (hrtime(true) - $t0) / 1e6;
	$proposed = count(array_filter($res, static fn(array $c): bool => $c['state'] === 'proposed'));
	printf("timing: %d nodes x %d devices: %.1f ms, %d proposed (PHP %s)\n", $n, $n, $ms, $proposed, PHP_VERSION);
}

// Collision-heavy case: every node and every device shares one hostname.
// Work must stay linear in nodes plus devices, and every node is ambiguous.
$n = 10000;
$nodes = $devices = [];
for ($i = 0; $i < $n; $i++) {
	$nodes[] = ['name' => sprintf('c%05d', $i), 'hostname' => 'shared', 'address' => 'shared'];
	$devices[] = ['id' => $i + 1, 'hostname' => 'shared', 'description' => 'shared'];
}
$t0 = hrtime(true);
$res = match_nodes(unique_nodes($nodes), $devices);
$ms = (hrtime(true) - $t0) / 1e6;
$amb = count(array_filter($res, static fn(array $c): bool => $c['state'] === 'ambiguous'));
printf("timing: collision-heavy %d nodes x %d devices on one hostname: %.1f ms, %d ambiguous\n", $n, $n, $ms, $amb);
if ($amb !== $n) {
	$fail++;
}

exit($fail > 0 ? 1 : 0);
