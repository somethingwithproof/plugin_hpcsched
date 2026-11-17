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
 * One GET request to slurmrestd. There is no method field: the plugin only
 * reads (REQ-BACKEND-005), so a request cannot be anything but a GET.
 */
final readonly class HttpRequest {
	/**
	 * @param array<string, string> $headers
	 * @param array<string, int>    $query   name => non-negative integer, the only query values the plugin sends
	 */
	public function __construct(
		public Endpoint $endpoint,
		public string $path,
		public array $headers = [],
		public array $query = [],
	) {
	}
}
