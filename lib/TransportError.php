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
 * A scheduler call the plugin refused, could not complete, or could not
 * parse. The class is all that is stored; the detail is local diagnostic
 * text (never scheduler output or a response body) and still passes through
 * the Redactor before it is logged.
 */
final class TransportError extends \RuntimeException {
	/**
	 * Construct a new TransportError instance.
	 *
	 * @param ErrorClass $errorClass
	 * @param string $detail
	 */
	public function __construct(public readonly ErrorClass $errorClass, string $detail = '') {
		parent::__construct($errorClass->value . ($detail !== '' ? ': ' . $detail : ''));
	}
}
