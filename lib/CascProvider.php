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
 * Configuration-as-Code provider descriptor registered via the
 * casc_register_providers hook.
 */
final class CascProvider {
	/**
	 * Construct a new CascProvider instance.
	 *
	 * @param ConfigService $service
	 */
	public function __construct(private readonly ConfigService $service) {
	}

	/**
	 * Build the canonical CasC provider descriptor for casc_register_providers.
	 *
	 * @return array{
	 *   slug: string,
	 *   schemaVersion: string,
	 *   redacted_fields: list<string>,
	 *   export: \Closure(array<string, mixed>=): array<string, mixed>,
	 *   validate: \Closure(array<string, mixed>): list<string>,
	 *   plan: \Closure(array<string, mixed>, array<string, mixed>=): list<array{operation: string, key: string, message: string}>,
	 *   apply: \Closure(array<string, mixed>, string=, array<string, mixed>=): array{ok: bool, errors: list<string>}
	 * }
	 */
	public function descriptor(): array {
		return [
			'slug'            => 'hpcsched',
			'schemaVersion'   => '1.0',
			'redacted_fields' => ['token_file', 'jwt', 'secret', 'password'],
			'export'          => fn (array $options = []): array => [
				'config'    => $this->service->exportData(),
				'reference' => [],
			],
			'validate'        => fn (array $desired): array => $this->service->validate(new ConfigTree(self::unwrapConfig($desired))),
			'plan'            => function (array $desired, array $options = []): array {
				$plan = $this->service->plan(new ConfigTree(self::unwrapConfig($desired)));

				if ($plan->hasErrors()) {
					return array_map(
						static fn (string $err): array => ['operation' => 'error', 'key' => 'hpcsched', 'message' => $err],
						$plan->errors,
					);
				}

				$items = [];

				foreach ($plan->settingsChanges as $name => $value) {
					$items[] = [
						'operation' => 'update',
						'key'       => 'settings.' . $name,
						'message'   => sprintf('Update setting %s', $name),
					];
				}

				foreach ($plan->clusterChanges as $change) {
					$items[] = [
						'operation' => $change['action'] === 'create' ? 'create' : 'update',
						'key'       => 'clusters.' . $change['input']->name,
						'message'   => sprintf('%s cluster %s', ucfirst($change['action']), $change['input']->name),
					];
				}

				return $items;
			},
			'apply'           => function (array $desired, string $confirm = '', array $options = []): array {
				$refusals = $this->service->apply(new ConfigTree(self::unwrapConfig($desired)), $confirm);

				return [
					'ok'     => $refusals === [],
					'errors' => $refusals,
				];
			},
		];
	}

	/**
	 * Unwrap an optional ['config' => ...] export envelope into a plain config map.
	 *
	 * @param array<array-key, mixed> $desired
	 * @return array<string, mixed>
	 */
	private static function unwrapConfig(array $desired): array {
		$raw = (isset($desired['config']) && is_array($desired['config']) && !isset($desired['schema_version']))
			? $desired['config']
			: $desired;

		$out = [];

		foreach ($raw as $key => $value) {
			$out[(string) $key] = $value;
		}

		return $out;
	}
}
