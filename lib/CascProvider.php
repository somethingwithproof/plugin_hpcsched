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
	public function __construct(private readonly ConfigService $service) {
	}

	/**
	 * @return array{
	 *   plugin: string,
	 *   schema_version: int,
	 *   formats: list<string>,
	 *   redacted_fields: list<string>,
	 *   export: \Closure(string): string,
	 *   validate: \Closure(string, string): list<string>,
	 *   plan: \Closure(string, string): array{id: string, empty: bool, errors: list<string>, lines: list<string>},
	 *   apply: \Closure(string, string, string): list<string>
	 * }
	 */
	public function descriptor(): array {
		return [
			'plugin'          => 'hpcsched',
			'schema_version'  => ConfigCodec::VERSION,
			'formats'         => [ConfigCodec::YAML, ConfigCodec::XML],
			'redacted_fields' => ['token_file', 'jwt', 'secret', 'password'],
			'export'          => fn (string $format = ConfigCodec::YAML): string => $this->service->export($format),
			'validate'        => fn (string $format, string $text): array => $this->service->validate(ConfigCodec::parse($format, $text)),
			'plan'            => function (string $format, string $text): array {
				$plan = $this->service->plan(ConfigCodec::parse($format, $text));

				return [
					'id'     => $plan->id(),
					'empty'  => $plan->isEmpty(),
					'errors' => $plan->errors,
					'lines'  => $plan->lines(),
				];
			},
			'apply'           => fn (string $format, string $text, string $confirm): array => $this->service->apply(ConfigCodec::parse($format, $text), $confirm),
		];
	}
}
