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

use Somethingwithproof\Hpcsched\ErrorClass;
use Somethingwithproof\Hpcsched\Section;
use Somethingwithproof\Hpcsched\SlurmTransport;
use Somethingwithproof\Hpcsched\Transport;
use Somethingwithproof\Hpcsched\TransportError;

/**
 * A SlurmTransport that answers from strings or error classes, for backend
 * and reader tests.
 */
final class StubTransport implements SlurmTransport {
	/** @var list<string> */
	public array $fetched = [];

	/**
	 * @param array<string, string|ErrorClass> $answers section value => JSON or error
	 */
	public function __construct(private readonly array $answers, private readonly float $delay = 0.0) {
	}

	public function transport(): Transport {
		return Transport::Commands;
	}

	public function dataParser(float $deadline): string {
		return 'v0.0.43';
	}

	public function fetch(Section $section, string $dataParser, float $deadline): string {
		$this->fetched[] = $section->value;

		if ($this->delay > 0) {
			usleep((int) ($this->delay * 1e6));
		}

		$answer = $this->answers[$section->value] ?? ErrorClass::Failed;

		if ($answer instanceof ErrorClass) {
			throw new TransportError($answer);
		}

		return $answer;
	}

	public function calls(): int {
		return count($this->fetched);
	}

	public function memoryLimited(): bool {
		return true;
	}
}
