#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$composerPath = $argv[1] ?? $root.'/composer.json';
$lockPath = $argv[2] ?? $root.'/composer.lock';
$tuplePath = $argv[3] ?? $root.'/polyglot/qualified-artifact-tuple.json';

/** @return array<string, mixed> */
function readJsonObject(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException("required JSON file is missing: {$path}");
    }

    $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($value)) {
        throw new RuntimeException("required JSON file is not an object: {$path}");
    }

    return $value;
}

try {
    $composer = readJsonObject($composerPath);
    $lock = readJsonObject($lockPath);
    $tuple = readJsonObject($tuplePath);

    $expected = $tuple['artifacts'] ?? null;
    if (! is_array($expected)) {
        throw new RuntimeException("qualified artifact tuple has no artifacts object: {$tuplePath}");
    }

    $lockedPackages = [];
    foreach (($lock['packages'] ?? []) as $package) {
        if (is_array($package) && is_string($package['name'] ?? null)) {
            $lockedPackages[$package['name']] = $package;
        }
    }

    $packageArtifacts = [
        'durable-workflow/sdk' => 'sdk-php',
        'durable-workflow/workflow' => 'workflow',
        'durable-workflow/waterline' => 'waterline',
    ];
    $resolved = [];

    foreach ($packageArtifacts as $package => $artifact) {
        $qualifiedVersion = $expected[$artifact] ?? null;
        $rootRequirement = $composer['require'][$package] ?? null;
        $lockedVersion = $lockedPackages[$package]['version'] ?? null;

        if (! is_string($qualifiedVersion) || $qualifiedVersion === '') {
            throw new RuntimeException("qualified artifact tuple has no {$artifact} version");
        }
        if (! is_string($rootRequirement) || $rootRequirement === '') {
            throw new RuntimeException("composer.json does not require {$package}");
        }
        if ($lockedVersion !== $qualifiedVersion) {
            throw new RuntimeException(
                "{$package} locked version ".json_encode($lockedVersion)
                ." does not match qualified {$artifact} {$qualifiedVersion}"
            );
        }

        $resolved[$artifact] = $lockedVersion;
    }

    $waterlineSdkRequirement = $lockedPackages['durable-workflow/waterline']['require']['durable-workflow/sdk'] ?? null;
    $serverVersion = $expected['server'] ?? null;
    if (! is_string($serverVersion) || $serverVersion === '') {
        throw new RuntimeException('qualified artifact tuple has no server version');
    }

    echo json_encode([
        'schema' => 'durable-workflow.sample-app.composer-artifact-graph/v1',
        'artifacts' => [
            'server' => $serverVersion,
            'sdk-php' => $resolved['sdk-php'],
            'workflow' => $resolved['workflow'],
            'waterline' => $resolved['waterline'],
        ],
        'waterline-requires-sdk-php' => $waterlineSdkRequirement,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "composer-artifact-graph: {$error->getMessage()}\n");
    exit(1);
}
