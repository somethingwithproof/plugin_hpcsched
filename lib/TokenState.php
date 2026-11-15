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
 * What the reader found when it read a cluster's token file, kept for the
 * health page (REQ-CRED-003). The web server may not be the poller host, so
 * the page reports what the reader saw instead of reading the file itself.
 */
enum TokenState: string {
	case None          = '';
	case Ok            = 'ok';
	case GroupReadable = 'group_readable';
	case WorldReadable = 'world_readable';
	case Owner         = 'owner';
	case Missing       = 'missing';
	case Refused       = 'refused';
	case Empty         = 'empty';

	/**
	 * Whether the token was read and used.
	 */
	public function usable(): bool {
		return in_array($this, [self::Ok, self::GroupReadable, self::WorldReadable, self::Owner], true);
	}

	public function message(): string {
		return match ($this) {
			self::None          => '',
			self::Ok            => __('readable only by the poller user', 'hpcsched'),
			self::GroupReadable => __('readable by the file group; restrict it to the poller user (chmod 600)', 'hpcsched'),
			self::WorldReadable => __('readable by every user on the poller; restrict it to the poller user (chmod 600)', 'hpcsched'),
			self::Owner         => __('owned by a user other than the poller user', 'hpcsched'),
			self::Missing       => __('missing or not readable by the poller user', 'hpcsched'),
			self::Refused       => __('refused: not a regular file, a symbolic link, writable by others, or too large', 'hpcsched'),
			self::Empty         => __('empty', 'hpcsched'),
		};
	}
}
