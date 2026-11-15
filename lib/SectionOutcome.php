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

final readonly class SectionOutcome {
	/**
	 * @param bool $unchanged a completed conditional read that found nothing
	 *                        changed: the stored rows stay and count as read now
	 */
	public function __construct(
		public SectionState $state,
		public ?ErrorClass $error = null,
		public int $records = 0,
		public bool $unchanged = false,
	) {
	}

	public static function completed(int $records): self {
		return new self(SectionState::Completed, null, $records);
	}

	public static function unchanged(int $records): self {
		return new self(SectionState::Completed, null, $records, true);
	}

	public static function failed(ErrorClass $error): self {
		return new self(SectionState::Failed, $error);
	}

	public static function notAttempted(): self {
		return new self(SectionState::NotAttempted);
	}
}
