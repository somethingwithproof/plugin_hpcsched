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
 * Cacti device permissions applied to the plugin's own records (REQ-SEC-001,
 * REQ-SEC-002). A cluster mapped to a device belongs to that device: a user,
 * administrators of the plugin included, may see or change it only while
 * Cacti allows them the device. An unmapped cluster belongs to no device and
 * is left to the realm that opened the page.
 *
 * Every admin action checks the record it loads, on the request that
 * changes it, before any write, mapping, reindex, or provisioning; mapping
 * also checks the device it maps to. Lists, the export, and the health page
 * show only clusters that pass.
 *
 * A cluster still mapped to a device that no longer exists (deleted while
 * the plugin's device_remove hook could not run) has no device permission
 * left to check. It is left to users with the Admin realm, who can see it
 * and give it a device again; the View realm does not see it.
 */
final class DeviceAccess {
	/**
	 * @param \Closure(int): bool      $deviceAllowed Cacti's is_device_allowed() for the current user
	 * @param \Closure(int): bool|null $deviceExists  whether the device is still in Cacti
	 * @param bool                     $admin         whether the user has the Admin realm
	 */
	public function __construct(
		private readonly \Closure $deviceAllowed,
		private readonly ?\Closure $deviceExists = null,
		private readonly bool $admin = false,
	) {
	}

	public function device(int $hostId): bool {
		return $hostId > 0 && ($this->deviceAllowed)($hostId) === true;
	}

	public function cluster(?Cluster $cluster): bool {
		if ($cluster === null) {
			return false;
		}

		if ($cluster->hostId === 0 || $this->device($cluster->hostId)) {
			return true;
		}

		return $this->admin && $this->orphaned($cluster);
	}

	/**
	 * Whether the cluster's device was deleted while the plugin's
	 * device_remove hook could not run (the plugin disabled).
	 */
	public function orphaned(Cluster $cluster): bool {
		return $cluster->hostId > 0 && $this->deviceExists !== null && ($this->deviceExists)($cluster->hostId) === false;
	}

	/**
	 * Whether a device may be created for the cluster or mapped to it: it
	 * has none, or (for an administrator) the one it had is gone.
	 */
	public function unmapped(Cluster $cluster): bool {
		return $cluster->hostId === 0 || ($this->admin && $this->orphaned($cluster));
	}

	/**
	 * @param list<Cluster> $clusters
	 *
	 * @return list<Cluster>
	 */
	public function clusters(array $clusters): array {
		return array_values(array_filter($clusters, fn (Cluster $c): bool => $this->cluster($c)));
	}
}
