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
 * A slurmrestd base URL, checked once. HTTPS to any host; plain HTTP only to
 * a loopback address, for a slurmrestd on the poller itself, which the
 * health page still flags. No user information, query, or fragment: the
 * token travels only in its header.
 */
final readonly class Endpoint {
	/**
	 * Construct a new Endpoint instance.
	 *
	 * @param bool $tls
	 * @param string $host
	 * @param int $port
	 * @param string $basePath
	 */
	private function __construct(
		public bool $tls,
		public string $host,
		public int $port,
		public string $basePath,
	) {
	}

	/**
	 * @throws TransportError
	 */
	public static function parse(string $url): self {
		if (strlen($url) > 255 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
			throw new TransportError(ErrorClass::Config, 'url');
		}

		$parts  = parse_url($url);
		$scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
		$host   = is_array($parts) ? strtolower($parts['host'] ?? '') : '';

		if (!is_array($parts) || !in_array($scheme, ['https', 'http'], true) || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			throw new TransportError(ErrorClass::Config, 'url');
		}

		$host = trim($host, '[]');

		if (preg_match('/^[a-z0-9.-]{1,253}$/', $host) !== 1 && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
			throw new TransportError(ErrorClass::Config, 'host');
		}

		$tls = $scheme === 'https';

		if (!$tls && !self::loopback($host)) {
			throw new TransportError(ErrorClass::Config, 'plain http is only allowed to a loopback address');
		}

		$path = rtrim($parts['path'] ?? '', '/');

		if ($path !== '' && preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $path) !== 1) {
			throw new TransportError(ErrorClass::Config, 'path');
		}

		return new self($tls, $host, $parts['port'] ?? ($tls ? 443 : 80), $path);
	}

	/**
	 * Whether the host and port are on an administrator's allowlist of
	 * host or host:port entries (Settings::hosts). An empty list allows any.
	 *
	 * @param list<string> $hosts
	 */
	public function permittedBy(array $hosts): bool {
		if ($hosts === []) {
			return true;
		}

		$host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;

		return in_array($host, $hosts, true) || in_array($host . ':' . $this->port, $hosts, true);
	}

	/**
	 * Loopback.
	 *
	 * @param string $host
	 * @return bool
	 */
	public static function loopback(string $host): bool {
		return $host === 'localhost' || $host === '::1' || preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host) === 1;
	}

	/**
	 * Socket address.
	 *
	 * @return string
	 */
	public function socketAddress(): string {
		$host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;

		return ($this->tls ? 'tls' : 'tcp') . "://$host:" . $this->port;
	}

	/**
	 * Host header.
	 *
	 * @return string
	 */
	public function hostHeader(): string {
		$host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;

		return $host . ($this->port === ($this->tls ? 443 : 80) ? '' : ':' . $this->port);
	}
}
