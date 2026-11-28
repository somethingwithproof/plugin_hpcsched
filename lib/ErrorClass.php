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
 * Every failure a transport or parser reports is reduced to one of these
 * classes. Scheduler text never travels further than the transport, so a
 * class is all that reaches the log, the database, and the health page.
 */
enum ErrorClass: string {
	case Config      = 'config';
	case Unreachable = 'unreachable';
	case Timeout     = 'timeout';
	case TooLarge    = 'too_large';
	case Malformed   = 'malformed';
	case Credential  = 'credential';
	case Access      = 'access';
	case RateLimited = 'rate_limited';
	case NotModified = 'not_modified';
	case Refused     = 'refused';
	case Failed      = 'failed';
	case Memory      = 'memory';

	/**
	 * Message.
	 *
	 * @return string
	 */
	public function message(): string {
		return match ($this) {
			self::Config      => __('configuration: a path, URL, or setting is missing or not allowed', 'hpcsched'),
			self::Unreachable => __('the scheduler could not be reached', 'hpcsched'),
			self::Timeout     => __('the call took longer than allowed and was stopped', 'hpcsched'),
			self::TooLarge    => __('the answer was larger than allowed and was discarded', 'hpcsched'),
			self::Malformed   => __('the answer could not be parsed with the pinned data parser', 'hpcsched'),
			self::Credential  => __('the scheduler refused the credential', 'hpcsched'),
			self::Access      => __('the scheduler account may not see this data (PrivateData or permissions)', 'hpcsched'),
			self::RateLimited => __('the controller asked clients to back off (rate limit)', 'hpcsched'),
			self::NotModified => __('the server answered 304 to a request that was not conditional; previous data kept', 'hpcsched'),
			self::Refused     => __('the plugin refused a call outside its allowlist', 'hpcsched'),
			self::Failed      => __('the call failed', 'hpcsched'),
			self::Memory      => __('the reader ran out of memory; lower Maximum Answer Size or raise Collection Memory in Settings', 'hpcsched'),
		};
	}

	/**
	 * Classes that mean the controller itself, not this one call, needs a
	 * rest. The reader skips cycles after them.
	 */
	public function backsOff(): bool {
		return $this === self::RateLimited;
	}
}
