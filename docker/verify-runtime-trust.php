<?php

declare(strict_types=1);

if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4 || PHP_VERSION_ID < 80427) {
    throw new RuntimeException('PHP 8.4.27 or newer on the 8.4 line is required.');
}
Phar::loadPhar($argv[1]);
require_once 'phar://composer.phar/vendor/autoload.php';
if (!str_starts_with(Composer\Composer::VERSION, '2.') || version_compare(Composer\Composer::VERSION, '2.10.3', '<')) {
    throw new RuntimeException('Composer 2.10.3 or newer on the 2 line is required.');
}

function inspectSampleCaBundle(string $path): int
{
    $contents = file_get_contents($path);
    if ($contents === false || !preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $contents, $certificates)) {
        throw new RuntimeException('The effective CA store must contain PEM certificates.');
    }
    foreach ($certificates[0] as $certificate) {
        $parsed = openssl_x509_parse($certificate);
        if ($parsed === false) {
            throw new RuntimeException('Malformed certificate in an effective CA store.');
        }
        foreach ((array) ($parsed['subject']['CN'] ?? []) as $subject) {
            if (in_array($subject, ['AAA Certificate Services', 'emSign ECC Root CA - G3'], true)) {
                throw new RuntimeException('Blocked certificate in an effective CA store.');
            }
        }
    }
    return count($certificates[0]);
}

$config = Composer\Factory::createConfig(null, getcwd());
if (is_file('composer.json')) {
    $manifest = json_decode((string) file_get_contents('composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $config->merge(['config' => $manifest['config'] ?? []], 'project');
}
if ($config->get('disable-tls') || !$config->get('secure-http')) {
    throw new RuntimeException('Composer TLS and secure HTTP must remain enabled.');
}
$locations = openssl_get_cert_locations();
$paths = array_filter([
    '/etc/ssl/certs/ca-certificates.crt', $locations['default_cert_file'],
    getenv('SSL_CERT_FILE'), ini_get('openssl.cafile'),
    $config->get('cafile') ?: Composer\CaBundle\CaBundle::getSystemCaRootBundlePath(),
]);
$directories = array_filter([
    $locations['default_cert_dir'], getenv('SSL_CERT_DIR'),
    ini_get('openssl.capath'), $config->get('capath'),
]);
foreach (array_unique($directories) as $directory) {
    if (!is_dir($directory)) {
        throw new RuntimeException('Cannot inspect an effective CA directory.');
    }
    foreach (['*.pem', '*.crt', '[a-f0-9]*.[0-9]'] as $pattern) {
        $paths = array_merge($paths, glob($directory.'/'.$pattern) ?: []);
    }
}
$bundles = 0;
foreach (array_unique($paths) as $path) {
    inspectSampleCaBundle($path);
    $bundles++;
}
echo json_encode(['php' => PHP_VERSION, 'composer' => Composer\Composer::VERSION, 'inspected_stores' => $bundles, 'blocked_roots' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
