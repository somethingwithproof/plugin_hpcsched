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

namespace Somethingwithproof\Hpcsched\Tests;

use Somethingwithproof\Hpcsched\HttpRequest;
use Somethingwithproof\Hpcsched\HttpResponse;
use Somethingwithproof\Hpcsched\HttpTransport;

/**
 * An HttpTransport that answers from memory and records each request, for
 * reader and credential tests that need no socket.
 */
final class StubHttp implements HttpTransport {
	/** @var list<HttpRequest> */
	public array $requests = [];

	/**
	 * @param array<string, HttpResponse> $responses path suffix => response; others get 404
	 */
	public function __construct(private readonly array $responses) {
	}

	public function get(HttpRequest $request, float $deadline): HttpResponse {
		$this->requests[] = $request;

		foreach ($this->responses as $suffix => $response) {
			if (str_ends_with($request->path, $suffix)) {
				return $response;
			}
		}

		return new HttpResponse(404, [], '');
	}
}
