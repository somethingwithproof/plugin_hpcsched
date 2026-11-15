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
 * HPC Clusters (HPC Scheduler Admin realm, REQ-UI-003): the cluster list,
 * the edit form, and the export. Every action that changes state is a POST
 * form that csrf-magic signs. Every stored string is escaped where it is
 * printed. The form takes a token file path, never a token, and the export
 * holds no credential. Nothing here contacts a scheduler.
 */
final class ClustersPage {
	public const FILE = 'hpcsched_clusters.php';

	public function __construct(
		private readonly ClusterRepository $clusters,
		private readonly PollerRepository $pollers,
	) {
	}

	public function renderList(): void {
		$pollers  = $this->pollers->names();
		$clusters = $this->clusters->all();

		html_start_box(__('HPC Clusters', 'hpcsched'), '100%', '', '3', 'center', self::FILE . '?action=edit');
		print Format::header([__('Name', 'hpcsched'), __('Poller', 'hpcsched'), __('Transport', 'hpcsched'), __('Interval', 'hpcsched'), __('Sections', 'hpcsched'), __('State', 'hpcsched'), __('Actions', 'hpcsched')]);

		if ($clusters === []) {
			print '<tr><td colspan="7"><em>' . html_escape(__('No clusters configured yet.', 'hpcsched')) . '</em></td></tr>';
		}

		foreach ($clusters as $cluster) {
			$toggle = $cluster->enabled() ? ['disable', __('Disable', 'hpcsched')] : ['enable', __('Enable', 'hpcsched')];

			print '<tr class="tableRow">'
				. '<td><a class="linkEditMain" href="' . html_escape(self::FILE . '?action=edit&id=' . $cluster->id) . '">' . html_escape($cluster->name) . '</a></td>'
				. '<td>' . html_escape($pollers[$cluster->pollerId] ?? __('Deleted poller %d', $cluster->pollerId, 'hpcsched')) . '</td>'
				. '<td>' . html_escape($cluster->transport->label()) . '</td>'
				. '<td>' . html_escape(__('%d seconds', $cluster->interval, 'hpcsched')) . '</td>'
				. '<td>' . html_escape(implode(', ', array_map(static fn (Section $s): string => $s->label(), $cluster->sections()))) . '</td>'
				. '<td>' . html_escape($cluster->adminState->value) . '</td>'
				. '<td>' . $this->actionForm($toggle[0], $cluster->id, $toggle[1]) . ' ' . $this->actionForm('delete', $cluster->id, __('Delete', 'hpcsched')) . '</td>'
				. '</tr>';
		}

		html_end_box();

		html_start_box(__('Export', 'hpcsched'), '100%', '', '3', 'center', '');
		print '<tr><td>' . html_escape(__('Download the cluster configuration as JSON before an uninstall that drops the plugin tables. It holds token file paths, never a token.', 'hpcsched')) . '</td>'
			. '<td><a class="ui-button" href="' . html_escape(self::FILE . '?action=export') . '">' . html_escape(__('Export', 'hpcsched')) . '</a></td></tr>';
		html_end_box();
	}

	/**
	 * @param array<string, string> $input  rejected form values, shown again
	 * @param array<string, string> $errors field => message
	 */
	public function renderEdit(?Cluster $cluster, array $input = [], array $errors = []): void {
		$value   = static fn (string $field, string $current): string => array_key_exists($field, $input) ? $input[$field] : $current;
		$checked = static fn (string $field, bool $current): bool => $input !== [] ? Row::bool($input, $field) : $current;
		$pollers = $this->pollers->names();

		// A deleted poller stays selectable for the cluster that still names it.
		if ($cluster !== null && !isset($pollers[$cluster->pollerId])) {
			$pollers[$cluster->pollerId] = __('Deleted poller %d', $cluster->pollerId, 'hpcsched');
		}

		$transports = [];

		foreach (Transport::cases() as $transport) {
			$transports[$transport->value] = $transport->label();
		}

		print '<form method="post" action="' . html_escape(self::FILE) . '">';
		html_start_box($cluster === null ? __('New Cluster', 'hpcsched') : __('Cluster: %s', html_escape($cluster->name), 'hpcsched'), '100%', '', '3', 'center', '');

		foreach ($errors as $message) {
			print '<tr><td colspan="2" class="textError">' . html_escape($message) . '</td></tr>';
		}

		$this->text('name', __('Name', 'hpcsched'), $value('name', $cluster->name ?? ''), 64);
		$this->select('poller_id', __('Poller', 'hpcsched'), $pollers, $value('poller_id', (string) ($cluster->pollerId ?? 1)));
		$this->text('collect_interval', __('Collection Interval (seconds)', 'hpcsched'), $value('collect_interval', (string) ($cluster->interval ?? Cluster::DEFAULT_INTERVAL)), 5);
		$this->checkbox('enabled', __('Enabled', 'hpcsched'), $checked('enabled', $cluster?->enabled() ?? true));
		$this->checkbox('collect_reservations', __('Collect Reservations', 'hpcsched'), $checked('collect_reservations', $cluster->reservations ?? false));
		$this->checkbox('collect_shares', __('Collect Fair-share (accounts only)', 'hpcsched'), $checked('collect_shares', $cluster->shares ?? false));
		$this->select('transport', __('Transport', 'hpcsched'), $transports, $value('transport', $cluster->transport->value ?? Transport::Commands->value));
		$this->text('slurm_conf', __('slurm.conf Path (optional)', 'hpcsched'), $value('slurm_conf', $cluster->slurmConf ?? ''), 255);
		$this->text('rest_url', __('slurmrestd URL', 'hpcsched'), $value('rest_url', $cluster->restUrl ?? ''), 255);
		$this->text('rest_user', __('slurmrestd User (optional)', 'hpcsched'), $value('rest_user', $cluster->restUser ?? ''), 64);
		$this->text('token_file', __('Token File on the Poller', 'hpcsched'), $value('token_file', $cluster->tokenFile ?? ''), 255);

		print '<tr><td colspan="2"><small>' . html_escape(__('Client commands use the binaries set under Settings > HPC Scheduler and the poller user\'s own Slurm authentication; the optional slurm.conf path selects a cluster. slurmrestd fields are used only with that transport. The token file must be readable by the poller user only; the plugin reads it at each run and never stores the token.', 'hpcsched')) . '</small></td></tr>';

		html_end_box();

		print '<input type="hidden" name="action" value="save">'
			. '<input type="hidden" name="id" value="' . ($cluster->id ?? 0) . '">'
			. '<input type="hidden" name="updated_at" value="' . html_escape($value('updated_at', (string) ($cluster->updatedAt ?? 0))) . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape(__('Save', 'hpcsched')) . '">'
			. '</form>';
	}

	/**
	 * The cluster configuration as data for the export download. Token file
	 * paths only; no token is ever in the plugin's tables to export.
	 *
	 * @return array<string, mixed>
	 */
	public function export(string $version, int $now): array {
		return [
			'plugin'      => 'hpcsched',
			'version'     => $version,
			'exported_at' => $now,
			'clusters'    => array_map(static fn (Cluster $c): array => [
				'name'                 => $c->name,
				'poller_id'            => $c->pollerId,
				'admin_state'          => $c->adminState->value,
				'transport'            => $c->transport->value,
				'collect_interval'     => $c->interval,
				'slurm_conf'           => $c->slurmConf,
				'rest_url'             => $c->restUrl,
				'rest_user'            => $c->restUser,
				'token_file'           => $c->tokenFile,
				'collect_reservations' => $c->reservations,
				'collect_shares'       => $c->shares,
			], $this->clusters->all()),
		];
	}

	private function actionForm(string $action, int $id, string $label): string {
		return '<form method="post" action="' . html_escape(self::FILE) . '" style="display:inline">'
			. '<input type="hidden" name="action" value="' . html_escape($action) . '">'
			. '<input type="hidden" name="id" value="' . $id . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape($label) . '">'
			. '</form>';
	}

	private function text(string $name, string $label, string $value, int $length): void {
		print '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label></td>'
			. '<td><input type="text" id="' . html_escape($name) . '" name="' . html_escape($name) . '" value="' . html_escape($value) . '" maxlength="' . $length . '" size="' . min(60, $length) . '"></td></tr>';
	}

	private function checkbox(string $name, string $label, bool $checked): void {
		print '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label></td><td><input type="checkbox" id="' . html_escape($name) . '" name="' . html_escape($name) . '" value="on"' . ($checked ? ' checked' : '') . '></td></tr>';
	}

	/**
	 * @param array<array-key, string> $options value => label
	 */
	private function select(string $name, string $label, array $options, string $current): void {
		print '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label></td><td><select id="' . html_escape($name) . '" name="' . html_escape($name) . '">';

		foreach ($options as $value => $text) {
			print '<option value="' . html_escape((string) $value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . html_escape($text) . '</option>';
		}

		print '</select></td></tr>';
	}
}
