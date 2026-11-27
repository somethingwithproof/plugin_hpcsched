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
 * Dry-run preview of Cacti Maint window synchronizations for Slurm nodes.
 */
final readonly class MaintSyncPlan {
	/**
	 * @param list<MaintAction> $actions
	 * @param list<string>      $warnings
	 */
	public function __construct(
		public bool $maintAvailable,
		public array $actions,
		public array $warnings = [],
	) {
	}

	/**
	 * Check whether empty.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return $this->actions === [];
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		$canonical = array_map(static fn (MaintAction $a): array => $a->toArray(), $this->actions);

		return substr(hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)), 0, 16);
	}

	/**
	 * @return list<string>
	 */
	public function lines(): array {
		$lines = [];

		foreach ($this->warnings as $warning) {
			$lines[] = 'WARNING: ' . $warning;
		}

		foreach ($this->actions as $a) {
			$prefix  = match ($a->operation) {
				'create' => '+',
				'update' => '~',
				'remove' => '-',
				default  => ' ',
			};
			$lines[] = sprintf(
				'%s maint cluster=%d node=%s host=%d kind=%s ref=%s window=%d..%d%s',
				$prefix,
				$a->clusterId,
				$a->nodeName,
				$a->hostId,
				$a->reasonKind,
				$a->sourceRef,
				$a->startTime,
				$a->endTime,
				$a->scheduleId > 0 ? ' schedule=' . $a->scheduleId : '',
			);
		}

		return $lines;
	}
}
