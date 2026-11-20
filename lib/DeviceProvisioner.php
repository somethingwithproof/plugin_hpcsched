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
 * Creates or maps the Cacti device of a cluster and attaches the partition
 * data query and the cluster graphs to it (REQ-OBJ-002 to REQ-OBJ-004,
 * REQ-OBJ-006).
 *
 * Everything goes through Cacti's own functions: api_device_save() for the
 * device, api_device_dq_add() for the data query (which runs the first
 * index), create_complete_graph_from_template() for graphs, and
 * run_data_query() for a reindex. The plugin writes only its own mapping. A
 * mapped device that already existed keeps every setting; only the data
 * query and missing graphs are added.
 */
final class DeviceProvisioner {
	/** Script server calls one data source makes per poll: the cluster input answers in one call. */
	private const CLUSTER_CALLS = 1;

	public function __construct(
		private readonly ClusterRepository $clusters,
		private readonly SeriesRepository $series,
		private readonly CactiObjectRepository $objects,
		private readonly string $cactiPath,
	) {
	}

	/**
	 * Why the plugin cannot provision now, or null when it can.
	 */
	public function refusal(): ?string {
		if ($this->objects->deviceTemplateId() === 0 || $this->objects->queryId() === 0) {
			return __('The HPC Scheduler templates are not installed. Install them from the health page.', 'hpcsched');
		}

		return null;
	}

	/**
	 * What creating or mapping a device would add, for the confirmation page
	 * (REQ-OBJ-006): the device, the data query, each graph, and the data
	 * sources, RRD files, and script server calls per poll they bring.
	 *
	 * @return list<string>
	 */
	public function plan(Cluster $cluster, int $hostId, bool $partitionGraphs): array {
		$lines = [];

		if ($hostId === 0) {
			$lines[] = __('A device "%s" from the %s device template, polled by poller %d, with availability checks off.', $this->description($cluster), TemplateInstaller::DEVICE_TEMPLATE, $cluster->pollerId, 'hpcsched');
		}

		$partitions = $this->series->partitions($cluster->id);

		if ($hostId === 0 || !$this->objects->hasQuery($hostId, $this->objects->queryId())) {
			$lines[] = __('The data query "%s", indexing the %d partitions known now.', TemplateInstaller::QUERY, count($partitions), 'hpcsched');
		}

		$sources = 0;
		$calls   = 0;

		foreach (TemplateInstaller::overview() as $name) {
			if ($hostId === 0 || $this->objects->graphCount($hostId, $this->objects->graphTemplateId($name)) === 0) {
				$sources++;
				$calls += self::CLUSTER_CALLS;
			}
		}

		if ($sources > 0) {
			$lines[] = __('%d cluster graphs, each with its own "%s" data source.', $sources, TemplateInstaller::CLUSTER, 'hpcsched');
		}

		$perPartition = 0;

		if ($partitionGraphs) {
			foreach ($partitions as $partition) {
				foreach (Series::groups() as $group => $values) {
					if ($hostId === 0 || $this->objects->graphCount($hostId, $this->objects->graphTemplateId(TemplateInstaller::partitionGraphs()[$group]), $partition) === 0) {
						$perPartition++;
						$sources++;
						$calls += count($values);
					}
				}
			}

			$lines[] = __('%d partition graphs, %d for each partition, each with its own data source.', $perPartition, count(Series::groups()), 'hpcsched');
		}

		$lines[] = __('In all: %d data sources and %d RRD files, and %d script server calls per poll. Partitions added later get graphs from the New Graphs page.', $sources, $sources, $calls, 'hpcsched');

		return $lines;
	}

	/**
	 * Create a device for a cluster that has none, or whose device no longer
	 * exists, and attach the plugin's objects. Returns the new device id, or
	 * 0 with the reason logged. When another request mapped the cluster
	 * first, the device made here is removed again.
	 */
	public function create(Cluster $cluster, bool $partitionGraphs): int {
		$templateId = $this->objects->deviceTemplateId();

		if ($templateId === 0 || ($cluster->hostId > 0 && $this->objects->device($cluster->hostId) !== null)) {
			return 0;
		}

		$this->includeCore();

		$hostId = (int) api_device_save(0, $templateId, $this->description($cluster), $this->hostname($cluster), '', 0, '', '', 161, 500, '', AVAIL_NONE, 0, 23, 400, 1,
			__('Created by the HPC Scheduler plugin for cluster %s. Its values come from the plugin snapshot; the host name is a reserved name that is never contacted.', $cluster->name, 'hpcsched'),
			'MD5', '', 'DES', '', '', 5, 1, $cluster->pollerId, 0);

		if ($hostId <= 0) {
			Log::error(sprintf('Cacti refused the device for cluster %d', $cluster->id));

			return 0;
		}

		if (!$this->clusters->mapHost($cluster->id, $hostId, $cluster->hostId)) {
			api_device_remove($hostId);
			Log::error(sprintf('Cluster %d was mapped by another request; device %d created for it was removed again', $cluster->id, $hostId));

			return 0;
		}

		$this->attach($cluster->id, $hostId, $partitionGraphs);

		return $hostId;
	}

	/**
	 * Map an existing device to a cluster and attach the plugin's objects.
	 * The device's own settings, poller included, are left alone.
	 */
	public function map(Cluster $cluster, int $hostId, bool $partitionGraphs): bool {
		if ($this->objects->device($hostId) === null || !$this->clusters->mapHost($cluster->id, $hostId, $cluster->hostId)) {
			return false;
		}

		$this->attach($cluster->id, $hostId, $partitionGraphs);

		return true;
	}

	/**
	 * Add the data query and any missing graph. Safe to repeat: the query
	 * is indexed again, and a graph template that already has a graph on
	 * the device (for a partition, on that index) is skipped.
	 */
	public function attach(int $clusterId, int $hostId, bool $partitionGraphs): void {
		$this->includeCore();

		$queryId = $this->objects->queryId();

		if ($queryId > 0) {
			api_device_dq_add($hostId, $queryId, DATA_QUERY_AUTOINDEX_INDEX_NUM_CHANGE);
		}

		foreach (TemplateInstaller::overview() as $name) {
			$this->graph($clusterId, $hostId, $name);
		}

		if ($partitionGraphs && $queryId > 0) {
			foreach ($this->objects->indexes($hostId, $queryId) as $partition) {
				foreach (TemplateInstaller::partitionGraphs() as $name) {
					$this->graph($clusterId, $hostId, $name, $queryId, $partition);
				}
			}
		}

		// Fills the cluster data sources' host_id input and the poller cache.
		push_out_host($hostId, 0);
	}

	/**
	 * Run Cacti's reindex when the device's partition indexes differ from
	 * the snapshot's, which Cacti's own index count check misses for a
	 * renamed partition (ADR 0011). Returns whether a reindex ran.
	 */
	public function reindexIfChanged(Cluster $cluster): bool {
		$queryId = $this->objects->queryId();

		if ($cluster->hostId === 0 || $queryId === 0 || !$this->objects->hasQuery($cluster->hostId, $queryId)) {
			return false;
		}

		if ($this->objects->indexes($cluster->hostId, $queryId) === $this->series->partitions($cluster->id)) {
			return false;
		}

		$this->reindex($cluster->hostId);

		return true;
	}

	/**
	 * Returns whether a reindex ran: it does not for a cluster without a
	 * device or before the plugin's data query exists.
	 */
	public function reindex(int $hostId): bool {
		$queryId = $this->objects->queryId();

		if ($hostId <= 0 || $queryId <= 0) {
			return false;
		}

		$this->includeCore();
		run_data_query($hostId, $queryId);

		return true;
	}

	public function description(Cluster $cluster): string {
		return 'HPC cluster ' . $cluster->name;
	}

	/**
	 * A name in the reserved .invalid domain (RFC 6761), so no lookup or
	 * availability check of the device can reach a real host. Cluster names
	 * are validated against Cluster::NAME_PATTERN before they are stored.
	 */
	public function hostname(Cluster $cluster): string {
		return strtolower($cluster->name) . '.hpcsched.invalid';
	}

	private function graph(int $clusterId, int $hostId, string $name, int $queryId = 0, string $partition = ''): void {
		$templateId = $this->objects->graphTemplateId($name);

		if ($templateId === 0 || $this->objects->graphCount($hostId, $templateId, $partition) > 0) {
			return;
		}

		$query = [];

		if ($partition !== '') {
			$query = ['snmp_query_id' => $queryId, 'snmp_index_on' => 'hpcPartition', 'snmp_query_graph_id' => $this->objects->queryGraphId($queryId, $templateId), 'snmp_index' => $partition];
		}

		$suggested = [];
		$created   = create_complete_graph_from_template($templateId, $hostId, $query, $suggested);

		if (!is_array($created) || !is_numeric($created['local_graph_id'] ?? null)) {
			Log::error(sprintf('Cacti did not create the %s graph for cluster %d', $name, $clusterId));
		}
	}

	private function includeCore(): void {
		include_once($this->cactiPath . '/lib/api_automation_tools.php');
		include_once($this->cactiPath . '/lib/api_data_source.php');
		include_once($this->cactiPath . '/lib/api_graph.php');
		include_once($this->cactiPath . '/lib/api_tree.php');
		include_once($this->cactiPath . '/lib/api_device.php');
		include_once($this->cactiPath . '/lib/data_query.php');
		include_once($this->cactiPath . '/lib/template.php');
		include_once($this->cactiPath . '/lib/utility.php');
		include_once($this->cactiPath . '/lib/poller.php');
	}
}
