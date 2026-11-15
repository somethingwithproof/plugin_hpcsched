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
 * Warnings for one cluster on the health page (REQ-HEALTH-001 to
 * REQ-HEALTH-003, REQ-CRED-003). Each is fixed text built from stored
 * states and numbers; none quotes scheduler output.
 */
final class HealthWarnings {
	/**
	 * Warnings that concern every cluster, from the settings alone.
	 *
	 * @return list<string>
	 */
	public static function global(Settings $settings): array {
		$out = [];

		if ($settings->fittedOutputMib() < $settings->maxOutputMib) {
			$out[] = __('Maximum Answer Size is %d MiB, but a decoded answer of that size does not fit in the Collection Memory of %d MiB; the reader allows %d MiB. Raise Collection Memory or lower Maximum Answer Size in Settings.', $settings->maxOutputMib, $settings->readerMemoryMib, $settings->fittedOutputMib(), 'hpcsched');
		}

		return $out;
	}

	/**
	 * @return list<string>
	 */
	public static function for(Cluster $cluster, ?ClusterRun $run, Freshness $freshness, Settings $settings, int $pollerInterval, int $now): array {
		$out = [];

		if ($cluster->pollerId !== 1) {
			$out[] = __('Assigned to poller %d, a remote data collector. This release collects only on the main poller, so the cluster is not collected; remote collection is planned for phase 3. Assign it to the main poller.', $cluster->pollerId, 'hpcsched');
		}

		if ($cluster->interval < Cluster::MIN_INTERVAL) {
			$out[] = __('The collection interval is below the %d second floor.', Cluster::MIN_INTERVAL, 'hpcsched');
		}

		if ($cluster->interval < $pollerInterval) {
			$out[] = __('The collection interval of %d seconds is shorter than the poller interval of %d seconds; data is collected every %d seconds.', $cluster->interval, $pollerInterval, $pollerInterval, 'hpcsched');
		}

		if ($cluster->transport === Transport::Rest && Endpoint::loopback(self::host($cluster->restUrl)) && str_starts_with(strtolower($cluster->restUrl), 'http:')) {
			$out[] = __('slurmrestd is reached over plain HTTP on the loopback address; the token crosses the local network stack unencrypted.', 'hpcsched');
		}

		if ($cluster->enabled() && ($run === null || $run->outcome === RunOutcome::Never)) {
			$out[] = __('No reader run has finished for this cluster yet.', 'hpcsched');
		}

		if ($run === null) {
			return $out;
		}

		if ($cluster->transport === Transport::Rest) {
			if ($run->tokenState !== TokenState::None && $run->tokenState !== TokenState::Ok) {
				$out[] = __('Token file: %s.', $run->tokenState->message(), 'hpcsched');
			}

			if ($run->tokenExpires > 0 && $run->tokenExpires <= $now) {
				$out[] = __('The token expired %s.', Format::age($run->tokenExpires, $now), 'hpcsched');
			} elseif ($run->tokenExpires > 0 && $run->tokenExpires - $now <= $settings->tokenWarning) {
				$out[] = __('The token expires in %d minutes.', intdiv($run->tokenExpires - $now, 60), 'hpcsched');
			}
		}

		if ($run->overran) {
			$out[] = __('The last run used its whole time budget of %d seconds; some sections were not collected.', $cluster->budget($pollerInterval), 'hpcsched');
		}

		if ($run->release !== '' && !DataParser::releaseSupported($run->release)) {
			$out[] = __('The scheduler reports Slurm %s, which is not a supported release (%s).', $run->release, implode(', ', DataParser::SUPPORTED_RELEASES), 'hpcsched');
		} elseif ($cluster->transport === Transport::Commands && $run->release !== '' && !DataParser::shippedBy($run->release, $settings->dataParser)) {
			$out[] = __('The scheduler reports Slurm %s, which does not ship the data parser %s chosen in Settings.', $run->release, $settings->dataParser, 'hpcsched');
		}

		if ($cluster->transport === Transport::Commands && $run->outcome !== RunOutcome::Never && !$run->memoryLimited) {
			$out[] = __('Client commands ran without a memory limit; set the prlimit path in Settings.', 'hpcsched');
		}

		if ($run->error !== null) {
			$out[] = __('Last error: %s.', $run->error->message(), 'hpcsched');
		}

		if ($run->backoffUntil > $now) {
			$out[] = __('Collection is paused for %d seconds after a rate limit.', $run->backoffUntil - $now, 'hpcsched');
		}

		if ($freshness === Freshness::Stale) {
			$out[] = __('Data is stale; graphs and alerts read it as unknown.', 'hpcsched');
		}

		return $out;
	}

	private static function host(string $url): string {
		$host = parse_url($url, PHP_URL_HOST);

		return is_string($host) ? trim(strtolower($host), '[]') : '';
	}
}
