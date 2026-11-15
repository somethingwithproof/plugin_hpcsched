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
 * Adapted from plugin_cloud's transport spike. Writes the test certificates into <dir>:
 *   ca.pem        test CA the client trusts
 *   good.pem      cert and key for 127.0.0.1 and localhost, signed by the CA
 *   wrongname.pem cert and key for other.example, signed by the CA
 *   selfsigned.pem cert and key for 127.0.0.1, signed by nobody the client trusts
 * Uses ext-openssl so the run needs no openssl binary in the image.
 */

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
	fwrite(STDERR, "usage: php make-certs.php <dir>\n");
	exit(2);
}

// The SAN extension needs a config file; ext-openssl reads sections from it.
$conf = $dir . '/openssl.cnf';
file_put_contents($conf, <<<CNF
[req]
distinguished_name = dn
[dn]
[ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
[good]
basicConstraints = CA:FALSE
subjectAltName = IP:127.0.0.1,DNS:localhost
[wrong]
basicConstraints = CA:FALSE
subjectAltName = DNS:other.example
CNF);

$keyOpts = array('private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $conf);

function issue(array $dn, string $section, $signKey, $signCert, string $conf, array $keyOpts): array {
	$key = openssl_pkey_new($keyOpts);
	$csr = openssl_csr_new($dn, $key, array('digest_alg' => 'sha256', 'config' => $conf));
	$cert = openssl_csr_sign($csr, $signCert, $signKey ?? $key, 30, array('digest_alg' => 'sha256', 'config' => $conf, 'x509_extensions' => $section), random_int(1, PHP_INT_MAX));
	openssl_x509_export($cert, $certPem);
	openssl_pkey_export($key, $keyPem, null, array('config' => $conf));

	return array($cert, $key, $certPem, $keyPem);
}

[$caCert, $caKey, $caPem] = issue(array('commonName' => 'plugin_hpcsched test CA'), 'ca', null, null, $conf, $keyOpts);
file_put_contents($dir . '/ca.pem', $caPem);

[, , $c, $k] = issue(array('commonName' => '127.0.0.1'), 'good', $caKey, $caCert, $conf, $keyOpts);
file_put_contents($dir . '/good.pem', $c . $k);

[, , $c, $k] = issue(array('commonName' => 'other.example'), 'wrong', $caKey, $caCert, $conf, $keyOpts);
file_put_contents($dir . '/wrongname.pem', $c . $k);

[, , $c, $k] = issue(array('commonName' => '127.0.0.1'), 'good', null, null, $conf, $keyOpts);
file_put_contents($dir . '/selfsigned.pem', $c . $k);

echo "certificates written to $dir\n";
