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

// Test code: refuse to run from a web request.
PHP_SAPI === 'cli' || exit(1);

/*
 * Source rules from docs/PLAN.md "Code structure" and ADR 0004 that a parser
 * can check. The PHP 8.0 parse of the entry points themselves is proven by the
 * CI lint job on a real PHP 8.0; these checks catch the same mistakes earlier
 * and cover the rules a lint cannot.
 */

function hpcsched_root(): string {
	return dirname(__DIR__, 2);
}

/**
 * @return list<string> plugin PHP files outside tests/, relative to the root
 */
function hpcsched_sources(): array {
	$files = [];
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(hpcsched_root(), FilesystemIterator::SKIP_DOTS));

	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen(hpcsched_root()) + 1);

		if ($file->getExtension() === 'php' && !str_starts_with($relative, 'tests/') && !str_starts_with($relative, '.git/')) {
			$files[] = $relative;
		}
	}

	sort($files);

	return $files;
}

/**
 * @return list<string> files Cacti may include before the PHP version check
 */
function hpcsched_entry_points(): array {
	return array_values(array_filter(hpcsched_sources(), fn (string $f) => !str_starts_with($f, 'lib/')));
}

it('keeps every PHP source file within the gettext scan depth', function () {
	foreach (hpcsched_sources() as $file) {
		expect(substr_count($file, '/'))->toBeLessThanOrEqual(1, $file);
	}
});

it('keeps entry points free of PHP 8.1 and later syntax', function () {
	$banned = [T_ENUM => 'enum', T_READONLY => 'readonly'];

	foreach (hpcsched_entry_points() as $file) {
		$tokens = PhpToken::tokenize((string) file_get_contents(hpcsched_root() . '/' . $file));

		foreach ($tokens as $i => $token) {
			expect(isset($banned[$token->id]))->toBeFalse("$file:{$token->line} uses {$token->text}");

			// "never" as a return type and "new" in an initializer are 8.1.
			if ($token->is(T_STRING) && strtolower($token->text) === 'never') {
				$previous = $tokens[$i - 1]->is(T_WHITESPACE) ? $tokens[$i - 2] : $tokens[$i - 1];
				expect($previous->text)->not->toBe(':', "$file:{$token->line} uses never");
			}
		}
	}
});

it('uses no PHP 8.3 or later features anywhere', function () {
	foreach (hpcsched_sources() as $file) {
		$source = (string) file_get_contents(hpcsched_root() . '/' . $file);
		$tokens = PhpToken::tokenize($source);

		expect($source)->not->toContain('json_validate(')
			->and($source)->not->toContain('#[\\Override]')
			->and($source)->not->toContain('#[Override]');

		foreach ($tokens as $i => $token) {
			if (!$token->is(T_CONST)) {
				continue;
			}

			// A typed constant has two identifiers between const and "=".
			$names = 0;

			for ($j = $i + 1; $j < count($tokens) && $tokens[$j]->text !== '='; $j++) {
				$names += $tokens[$j]->is(T_STRING) ? 1 : 0;
			}

			expect($names)->toBe(1, "$file:{$token->line} declares a typed constant");
		}
	}
});

it('defines exactly one class, interface, or enum per lib file, named after the file', function () {
	foreach (glob(hpcsched_root() . '/lib/*.php') as $path) {
		$tokens = PhpToken::tokenize((string) file_get_contents($path));
		$names  = [];

		foreach ($tokens as $i => $token) {
			if ($token->is([T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT]) && !$tokens[$i - 1]->is(T_DOUBLE_COLON)) {
				$next = $tokens[$i + 1]->is(T_WHITESPACE) ? $tokens[$i + 2] : $tokens[$i + 1];

				if ($next->is(T_STRING)) {
					$names[] = $next->text;
				}
			}
		}

		expect($names)->toBe([basename($path, '.php')], basename($path))
			->and((string) file_get_contents($path))->toContain("namespace Somethingwithproof\\Hpcsched;");
	}
});

it('keeps lib classes away from request variables and globals', function () {
	foreach (glob(hpcsched_root() . '/lib/*.php') as $path) {
		$source = (string) file_get_contents($path);

		expect($source)->not->toMatch('/\$_(REQUEST|GET|POST|SESSION|SERVER|COOKIE)\b/', basename($path))
			->and($source)->not->toMatch('/\bglobal\s+\$/', basename($path))
			->and($source)->not->toContain('$GLOBALS', basename($path));
	}
});

it('issues SQL only from repository and schema classes, through prepared helpers', function () {
	foreach (hpcsched_sources() as $file) {
		$source = (string) file_get_contents(hpcsched_root() . '/' . $file);

		expect($source)->not->toMatch('/\bdb_(execute|fetch_cell|fetch_row|fetch_assoc)\s*\(/', $file);

		// include/unbooted-uninstall.php is the one exception: it runs where
		// lib/ cannot load (see its header).
		$repository = str_starts_with($file, 'lib/') && preg_match('/^(Schema|\w+Repository)\.php$/', basename($file)) === 1;

		if (!$repository && $file !== 'include/unbooted-uninstall.php') {
			expect($source)->not->toMatch('/\bdb_\w+_prepared\s*\(/', $file);
		}
	}
});

it('passes the hpcsched text domain to every translated plugin string', function () {
	// Labels intentionally reused from core keep the core domain.
	$core = ['Management'];

	foreach (hpcsched_sources() as $file) {
		$tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents(hpcsched_root() . '/' . $file)), fn ($t) => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));

		foreach ($tokens as $i => $token) {
			if (!$token->is(T_STRING) || $token->text !== '__' || ($tokens[$i + 1]->text ?? '') !== '(' || ($tokens[$i - 1]->is(T_FUNCTION))) {
				continue;
			}

			$depth = 0;
			$last  = null;

			for ($j = $i + 1; $j < count($tokens); $j++) {
				$depth += $tokens[$j]->text === '(' ? 1 : ($tokens[$j]->text === ')' ? -1 : 0);

				if ($depth === 0) {
					break;
				}

				if ($depth === 1 && $tokens[$j]->text !== ',' && $tokens[$j]->text !== '(') {
					$last = $tokens[$j];
				}
			}

			$first = $tokens[$i + 2];

			if ($first->is(T_CONSTANT_ENCAPSED_STRING) && in_array(trim($first->text, '\'"'), $core, true)) {
				continue;
			}

			expect($last?->text)->toBe("'hpcsched'", "$file:{$token->line}");
		}
	}
});

it('ships a translation template containing every plugin string', function () {
	$pot = (string) file_get_contents(hpcsched_root() . '/locales/po/cacti.pot');

	foreach (['The HPC Scheduler plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.'] as $msgid) {
		expect($pot)->toContain('msgid "' . $msgid . '"');
	}
});

it('formats translated strings with __() itself, never sprintf(__())', function () {
	// Core's __() hands the text domain to sprintf(), so a placeholder in
	// sprintf(__("%d", "hpcsched"), $n) takes "hpcsched" and prints 0 or the
	// domain. The values go inside the call: __("%d", $n, "hpcsched").
	foreach (hpcsched_sources() as $file) {
		expect((string) file_get_contents(hpcsched_root() . '/' . $file))->not->toContain('sprintf(__(', $file);
	}
});

it('uses plain placeholders in translated strings, because core\'s __() does not format positional ones', function () {
	// __() only formats a string that has %d, %s, and the like. A string with
	// only %1$d placeholders comes back raw.
	foreach (hpcsched_sources() as $file) {
		$source = (string) file_get_contents(hpcsched_root() . '/' . $file);

		preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", $source, $texts);

		foreach ($texts[1] as $text) {
			expect($text)->not->toMatch('/%[0-9]+\$/', $file);
		}
	}
});

it('logs through cacti_log() under the HPCSCHED tag only', function () {
	foreach (hpcsched_sources() as $file) {
		$code = '';

		foreach (PhpToken::tokenize((string) file_get_contents(hpcsched_root() . '/' . $file)) as $token) {
			$code .= $token->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $token->text;
		}

		preg_match_all('/\bcacti_log\s*\((.*?)\);/s', $code, $calls);

		foreach ($calls[1] as $arguments) {
			expect(str_contains($arguments, "'HPCSCHED'"))->toBeTrue($file);
		}
	}
});

it('ships no Composer runtime dependencies', function () {
	$manifest = hpcsched_root() . '/composer.json';

	if (is_file($manifest)) {
		$require = json_decode((string) file_get_contents($manifest), true)['require'] ?? [];

		foreach (array_keys($require) as $package) {
			expect($package === 'php' || str_starts_with($package, 'ext-'))->toBeTrue($package);
		}
	}

	expect(is_dir(hpcsched_root() . '/vendor'))->toBeFalse();
});

/**
 * @return string the file without comments
 */
function hpcsched_code(string $file): string {
	$code = '';

	foreach (PhpToken::tokenize((string) file_get_contents(hpcsched_root() . '/' . $file)) as $token) {
		$code .= $token->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $token->text;
	}

	return $code;
}

it('starts processes only from CommandRunner, with an argument array, and never through a shell', function () {
	foreach (hpcsched_sources() as $file) {
		$code = hpcsched_code($file);

		expect($code)->not->toMatch('/\b(shell_exec|passthru|system|popen|pcntl_exec)\s*\(/', $file)
			->and($code)->not->toMatch('/(?<![\w>:$])exec\s*\(/', $file)
			->and($code)->not->toContain('`', $file);

		if ($file !== 'lib/CommandRunner.php') {
			expect($code)->not->toMatch('/\bproc_\w+\s*\(/', $file);
		}

		if ($file !== 'setup.php') {
			expect($code)->not->toMatch('/\bexec_background\s*\(/', $file);
		}
	}

	// proc_open() with an array command runs the program directly.
	expect(hpcsched_code('lib/CommandRunner.php'))->toMatch('/proc_open\(\$argv,/');
});

it('reaches the network only from SocketTransport', function () {
	foreach (hpcsched_sources() as $file) {
		$code = hpcsched_code($file);

		expect($code)->not->toMatch('/\b(curl_\w+|fsockopen|pfsockopen|socket_create|get_headers)\s*\(/', $file)
			->and($code)->not->toMatch('/\b(file_get_contents|fopen)\s*\(\s*[\'"]https?:/', $file);

		if ($file !== 'lib/SocketTransport.php') {
			expect($code)->not->toMatch('/\bstream_socket_client\s*\(/', $file);
		}
	}
});

it('keeps pages away from scheduler access, so a page view never contacts a scheduler or reads a token', function () {
	$pages = array_merge(Somethingwithproof\Hpcsched\Pages::files(), ['lib/ClustersPage.php', 'lib/HealthPage.php', 'lib/OverviewPage.php', 'lib/HealthWarnings.php', 'lib/ClusterInput.php', 'lib/ClusterValues.php']);

	foreach ($pages as $file) {
		expect(hpcsched_code($file))->not->toMatch('/\b(Reader|Transports|CommandTransport|RestTransport|SocketTransport|CommandRunner|SlurmBackend|TokenFile::read|hpcsched_reader)\b/', $file);
	}
});

it('reveals a secret only to put it in the request header', function () {
	foreach (hpcsched_sources() as $file) {
		if ($file !== 'lib/RestTransport.php' && $file !== 'lib/Redactor.php') {
			expect(hpcsched_code($file))->not->toContain('->reveal()', $file);
		}
	}
});

it('fixes every scheduler call to a read', function () {
	foreach (Somethingwithproof\Hpcsched\CommandTransport::COMMANDS as [$binary, $args]) {
		expect(['squeue', 'scontrol', 'sshare'])->toContain($binary);

		foreach ($args as $arg) {
			expect(strtolower($arg))->not->toMatch('/update|reconfig|requeue|token|create|delete|cancel|hold|release|suspend|resume|reboot|shutdown|takeover|write/');
		}

		if ($binary === 'scontrol') {
			expect($args[1])->toBe('show');
		}
	}

	foreach (Somethingwithproof\Hpcsched\RestTransport::PATHS as $path) {
		expect($path)->toMatch('#^/slurm/\{p\}/(jobs|nodes|partitions|reservations)/$|^/slurm/\{p\}/shares$#');
	}

	foreach (hpcsched_sources() as $file) {
		expect(hpcsched_code($file))->not->toMatch('/scontrol\s+token|[\'"]token[\'"]\s*\]/', $file);
	}
});

it('never logs or prints what a scheduler sent', function () {
	foreach (hpcsched_sources() as $file) {
		preg_match_all('/\b(?:Log::\w+|cacti_log|print|echo|fwrite)\s*\(?(.*?)\);/s', hpcsched_code($file), $calls);

		foreach ($calls[1] as $arguments) {
			expect($arguments)->not->toMatch('/->(stdout|stderr|body|reason)\b|\$(stdout|stderr|body|json|doc)\b/', $file);
		}
	}
});

it('keeps every test script outside tests/Unit command line only', function () {
	$files    = [];
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(hpcsched_root() . '/tests', FilesystemIterator::SKIP_DOTS));

	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen(hpcsched_root()) + 1);

		if ($file->getExtension() === 'php' && !str_starts_with($relative, 'tests/Unit/')) {
			$files[] = $relative;
		}
	}

	expect($files)->not->toBeEmpty();

	foreach ($files as $relative) {
		// The first statement after any declare, namespace, and use
		// statements must be the guard, before any other code runs.
		$tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents(hpcsched_root() . '/' . $relative)), static fn (PhpToken $t): bool => !$t->isIgnorable()));
		$i      = 0;

		while (isset($tokens[$i]) && ($tokens[$i]->is([T_DECLARE, T_NAMESPACE, T_USE]))) {
			while (isset($tokens[$i]) && $tokens[$i]->text !== ';') {
				$i++;
			}

			$i++;
		}

		$statement = implode(' ', array_map(static fn (PhpToken $t): string => $t->text, array_slice($tokens, $i, 9)));

		expect($statement)->toBe("PHP_SAPI === 'cli' || exit ( 1 ) ;", $relative);
	}
});

it('keeps tests and other development files out of release archives', function () {
	$attributes = (string) file_get_contents(hpcsched_root() . '/.gitattributes');

	foreach (['/tests', '/.github', '/phpstan.neon', '/phpunit.xml', '/phpunit-integration.xml', '/.editorconfig'] as $path) {
		expect($attributes)->toMatch('#^' . preg_quote($path, '#') . '\s+export-ignore$#m', $path);
	}
});
