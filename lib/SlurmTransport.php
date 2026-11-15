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
 * How the Slurm backend fetches raw JSON, separated from parsing so both
 * transports share one parser per section (ADR 0004). Both return the same
 * response types: squeue --json and GET /jobs/ dump the job-info response,
 * and scontrol --json show node, partition, and reservation dump the same
 * types as the REST endpoints (docs/research/slurm-client-output.md).
 * Neither has a call that changes the scheduler.
 */
interface SlurmTransport {
	public function transport(): Transport;

	/**
	 * The data parser to request.
	 *
	 * @throws TransportError
	 */
	public function dataParser(float $deadline): string;

	/**
	 * The raw JSON document for one section.
	 *
	 * @throws TransportError
	 */
	public function fetch(Section $section, string $dataParser, float $deadline): string;

	/**
	 * Scheduler calls made so far, for the run record.
	 */
	public function calls(): int;

	/**
	 * Whether every client command ran under the memory limit; true for a
	 * transport that starts no process.
	 */
	public function memoryLimited(): bool;
}
