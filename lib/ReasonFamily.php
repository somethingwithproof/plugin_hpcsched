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
 * Pending reason families (ADR 0010, ADR 0011). The rules map every reason
 * string in Slurm source from 24.11 to the 26.11 candidate
 * (tests/fixtures/slurm/reasons.json); anything else is Other, with the raw
 * string kept. Values are short codes because Cacti data source names are
 * limited to 19 characters.
 */
enum ReasonFamily: string {
	case Priority    = 'priority';
	case Resources   = 'resources';
	case Dependency  = 'dependency';
	case Held        = 'held';
	case BeginTime   = 'begin_time';
	case Reservation = 'reservation';
	case Assoc       = 'assoc';
	case Qos         = 'qos';
	case Partition   = 'partition';
	case Node        = 'node';
	case Other       = 'other';

	/**
	 * First matching rule wins, in the order of the research table.
	 */
	public static function of(string $reason): self {
		$reason = Reasons::name($reason);

		return match (true) {
			$reason === 'Priority'                                                => self::Priority,
			in_array($reason, ['Resources', 'Licenses', 'NvidiaImexChannels'], true) => self::Resources,
			in_array($reason, ['Dependency', 'DependencyNeverSatisfied'], true)      => self::Dependency,
			in_array($reason, ['JobHeldAdmin', 'JobHeldUser', 'JobHoldMaxRequeue'], true) => self::Held,
			$reason === 'BeginTime'                                               => self::BeginTime,
			str_starts_with($reason, 'Reservation')                               => self::Reservation,
			str_starts_with($reason, 'Assoc')                                     => self::Assoc,
			str_starts_with($reason, 'QOS') || preg_match('/^Max\w*Per(Account|User)$/', $reason) === 1 => self::Qos,
			str_starts_with($reason, 'Partition')                                 => self::Partition,
			in_array($reason, ['ReqNodeNotAvail', 'NodeDown', 'MaxPoweredUpNodes'], true) => self::Node,
			default                                                               => self::Other,
		};
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ($this) {
			self::Priority    => __('Priority', 'hpcsched'),
			self::Resources   => __('Resources', 'hpcsched'),
			self::Dependency  => __('Dependency', 'hpcsched'),
			self::Held        => __('Held', 'hpcsched'),
			self::BeginTime   => __('Begin time', 'hpcsched'),
			self::Reservation => __('Reservation', 'hpcsched'),
			self::Assoc       => __('Association limits', 'hpcsched'),
			self::Qos         => __('QOS limits', 'hpcsched'),
			self::Partition   => __('Partition limits', 'hpcsched'),
			self::Node        => __('Node availability', 'hpcsched'),
			self::Other       => __('Other', 'hpcsched'),
		};
	}
}
