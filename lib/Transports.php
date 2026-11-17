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
 * Builds the transport a cluster is configured for. The only place a token
 * file is read, at use time, once per run (REQ-CRED-002).
 */
final class Transports {
	public function __construct(
		private readonly Settings $settings,
		private readonly CommandRunner $runner,
		private readonly HttpTransport $http,
	) {
	}

	/**
	 * @throws TransportError for a REST cluster whose URL is not allowed
	 * @throws CredentialError for a REST cluster whose token file is refused
	 */
	public function for(Cluster $cluster, Redactor $redactor, ?Token &$token = null, ?ClusterRun $previous = null): SlurmTransport {
		if ($cluster->transport === Transport::Commands) {
			return new CommandTransport($this->runner, $this->settings, $cluster->slurmConf);
		}

		$endpoint = Endpoint::parse($cluster->restUrl);

		if (!$endpoint->permittedBy($this->settings->restHosts)) {
			throw new TransportError(ErrorClass::Config, 'host not on the slurmrestd host list in Settings');
		}

		$token = TokenFile::read($cluster->tokenFile, $this->settings->tokenDirs);

		$redactor->add($token->secret);

		return new RestTransport($this->http, $endpoint, $cluster->restUser, $token, (float) $this->settings->callTimeout, self::cachedParser($previous));
	}

	/**
	 * The data parser discovered on the cluster's last run, while it can
	 * still be trusted: that run read slurmrestd and completed, and the
	 * release it reported ships no newer supported parser. Otherwise ''
	 * (discover again), so a 404 or an upgraded daemon leads to a fresh
	 * discovery on the next run (REQ-REST-002).
	 */
	public static function cachedParser(?ClusterRun $previous): string {
		if ($previous === null || $previous->transport !== Transport::Rest->value || $previous->outcome !== RunOutcome::Completed || !DataParser::supported($previous->dataParser)) {
			return '';
		}

		$newest = DataParser::newestShippedBy($previous->release);

		return $newest === null || $newest === $previous->dataParser ? $previous->dataParser : '';
	}
}
