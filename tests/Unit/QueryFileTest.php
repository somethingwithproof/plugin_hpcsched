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

/*
 * The data query file the install copies into Cacti's resource tree:
 * ownership is recorded only for a file the plugin wrote, and a symbolic
 * link at the destination is refused, dangling or not.
 */

use Somethingwithproof\Hpcsched\CactiObjectRepository;
use Somethingwithproof\Hpcsched\TemplateInstaller;
use Somethingwithproof\Hpcsched\Tests\Scratch;

require_once dirname(__DIR__) . '/Support/Scratch.php';

beforeEach(function () {
	$GLOBALS['__hpcsched_options'] = [];
	$this->scratch = new Scratch();
	$this->plugin  = $this->scratch->dir . '/plugin';
	$this->cacti   = $this->scratch->dir . '/cacti';
	$this->dest    = $this->cacti . '/resource/script_server';
	$this->target  = $this->dest . '/' . TemplateInstaller::QUERY_FILE;
	$this->scratch->file('plugin/resource/script_server/' . TemplateInstaller::QUERY_FILE, "<query>v1</query>\n", 0644);

	mkdir($this->dest, 0700, true);

	$this->installer = new TemplateInstaller($this->plugin, $this->cacti, new CactiObjectRepository(false));
	$this->copy      = fn (): ?string => (new ReflectionMethod(TemplateInstaller::class, 'installQueryFile'))->invoke($this->installer);
});

afterEach(function () {
	$this->scratch->remove();
	$GLOBALS['__hpcsched_options'] = [];
});

it('copies the file, records what it wrote, and removes it on uninstall', function () {
	expect(($this->copy)())->toBeNull()
		->and(file_get_contents($this->target))->toBe("<query>v1</query>\n")
		->and($GLOBALS['__hpcsched_options'][TemplateInstaller::FILES_SETTING])->toBe(hash('sha256', "<query>v1</query>\n"))
		->and(glob($this->dest . '/.hpcsched-*'))->toBe([]);

	$this->installer->uninstall();

	expect(file_exists($this->target))->toBeFalse();
});

it('uses an identical file an administrator copied without taking it over, so uninstall keeps it', function () {
	copy($this->plugin . '/resource/script_server/' . TemplateInstaller::QUERY_FILE, $this->target);

	expect(($this->copy)())->toBeNull()
		->and($GLOBALS['__hpcsched_options'][TemplateInstaller::FILES_SETTING] ?? '')->toBe('');

	$this->installer->uninstall();

	expect(file_exists($this->target))->toBeTrue();
});

it('leaves a different file it did not write alone', function () {
	file_put_contents($this->target, "<query>local</query>\n");

	expect(($this->copy)())->toContain('was not written by this plugin')
		->and(file_get_contents($this->target))->toBe("<query>local</query>\n");
});

it('refuses a symbolic link at the destination, dangling or not, and writes nothing through it', function () {
	$elsewhere = $this->scratch->dir . '/elsewhere.xml';
	symlink($elsewhere, $this->target);

	expect(($this->copy)())->toContain('is a symbolic link')
		->and(file_exists($elsewhere))->toBeFalse()
		->and(is_link($this->target))->toBeTrue();

	$existing = $this->scratch->file('existing.xml', "<query>v1</query>\n", 0644);
	unlink($this->target);
	symlink($existing, $this->target);

	expect(($this->copy)())->toContain('is a symbolic link');

	// Even with a recorded hash that matches what the link points to.
	$GLOBALS['__hpcsched_options'][TemplateInstaller::FILES_SETTING] = hash('sha256', "<query>v1</query>\n");
	$this->installer->uninstall();

	expect(is_file($existing))->toBeTrue()->and(is_link($this->target))->toBeTrue();
});

it('replaces a file it wrote when the shipped version changes', function () {
	expect(($this->copy)())->toBeNull();

	file_put_contents($this->plugin . '/resource/script_server/' . TemplateInstaller::QUERY_FILE, "<query>v2</query>\n");

	expect(($this->copy)())->toBeNull()
		->and(file_get_contents($this->target))->toBe("<query>v2</query>\n")
		->and($GLOBALS['__hpcsched_options'][TemplateInstaller::FILES_SETTING])->toBe(hash('sha256', "<query>v2</query>\n"))
		->and(substr(sprintf('%o', fileperms($this->target)), -4))->toBe('0644');
});
