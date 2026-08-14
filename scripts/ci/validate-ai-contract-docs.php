#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$write = in_array('--write', $argv, true);
$requireInstalled = in_array('--require-installed', $argv, true);
$supportedArguments = [$argv[0], '--write', '--require-installed'];
$unknownArguments = array_diff($argv, $supportedArguments);

if ($unknownArguments !== []) {
    fwrite(STDERR, 'ai-contract-docs: unsupported argument(s): '.implode(', ', $unknownArguments).PHP_EOL);
    exit(2);
}

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

/** @param array<string, mixed> $lock
 * @return array<string, mixed>
 */
function lockedPackage(array $lock, string $name): array
{
    foreach (($lock['packages'] ?? []) as $package) {
        if (is_array($package) && ($package['name'] ?? null) === $name) {
            return $package;
        }
    }

    throw new RuntimeException("composer.lock does not resolve {$name}");
}

/** @return array<string, string> */
function contractLinks(string $guide, string $repository): array
{
    preg_match_all('~https://[^\\s)<>\"\']+~', $guide, $matches);

    $links = [];
    $repositoryPath = (string) parse_url($repository, PHP_URL_PATH);
    foreach ($matches[0] as $url) {
        if (parse_url($url, PHP_URL_HOST) !== parse_url($repository, PHP_URL_HOST)) {
            continue;
        }

        $urlPath = (string) parse_url($url, PHP_URL_PATH);
        $prefix = rtrim($repositoryPath, '/').'/blob/';
        if (! str_starts_with($urlPath, $prefix)) {
            continue;
        }

        $remainder = substr($urlPath, strlen($prefix));
        $separator = strpos($remainder, '/');
        if ($separator === false) {
            continue;
        }

        $document = substr($remainder, $separator + 1);
        if (in_array($document, [
            'docs/delivery-and-recovery.md',
            'docs/provider-author-guide.md',
        ], true)) {
            if (isset($links[$document])) {
                throw new RuntimeException("sandbox guide links {$document} more than once");
            }

            $links[$document] = $url;
        }
    }

    return $links;
}

try {
    $lock = readJsonObject($root.'/composer.lock');
    $package = lockedPackage($lock, 'durable-workflow/ai');
    $source = $package['source'] ?? null;
    $dist = $package['dist'] ?? null;

    if (! is_array($source)) {
        throw new RuntimeException('locked durable-workflow/ai package has no source metadata');
    }

    $sourceUrl = $source['url'] ?? null;
    $reference = $source['reference'] ?? null;
    if (! is_string($sourceUrl) || ! str_starts_with($sourceUrl, 'https://')) {
        throw new RuntimeException('locked durable-workflow/ai source URL is not an HTTPS URL');
    }
    if (! is_string($reference) || preg_match('/^[0-9a-f]{40}$/D', $reference) !== 1) {
        throw new RuntimeException('locked durable-workflow/ai source reference is not an immutable commit');
    }
    if (is_array($dist) && ($dist['reference'] ?? $reference) !== $reference) {
        throw new RuntimeException('locked durable-workflow/ai source and dist references disagree');
    }

    $repository = preg_replace('/\\.git$/', '', $sourceUrl);
    if (! is_string($repository)) {
        throw new RuntimeException('could not derive the AI repository from Composer source metadata');
    }

    $documents = [
        'docs/delivery-and-recovery.md',
        'docs/provider-author-guide.md',
    ];
    $expectedLinks = [];
    foreach ($documents as $document) {
        $expectedLinks[$document] = "{$repository}/blob/{$reference}/{$document}";
    }

    $guidePath = $root.'/docs/sandbox-orchestration.md';
    $guide = file_get_contents($guidePath);
    if (! is_string($guide)) {
        throw new RuntimeException("could not read {$guidePath}");
    }

    if ($write) {
        $currentLinks = contractLinks($guide, $repository);
        foreach ($documents as $document) {
            $current = $currentLinks[$document] ?? null;
            if ($current === null) {
                throw new RuntimeException("sandbox guide does not link {$document}");
            }

            $guide = str_replace($current, $expectedLinks[$document], $guide);
        }

        if (file_put_contents($guidePath, $guide) === false) {
            throw new RuntimeException("could not update {$guidePath}");
        }
    }

    $actualLinks = contractLinks($guide, $repository);
    foreach ($expectedLinks as $document => $expectedLink) {
        $actualLink = $actualLinks[$document] ?? null;
        if ($actualLink !== $expectedLink) {
            throw new RuntimeException(
                "sandbox guide link for {$document} does not match locked AI reference {$reference}"
            );
        }
    }

    $installedPath = $root.'/vendor/composer/installed.php';
    if ($requireInstalled && ! is_file($installedPath)) {
        throw new RuntimeException('installed Composer metadata is required; run composer install first');
    }

    if ($requireInstalled) {
        $installed = require $installedPath;
        $installedPackage = $installed['versions']['durable-workflow/ai'] ?? null;
        if (! is_array($installedPackage)) {
            throw new RuntimeException('installed Composer metadata does not contain durable-workflow/ai');
        }
        if (($installedPackage['reference'] ?? null) !== $reference) {
            throw new RuntimeException('installed durable-workflow/ai reference does not match composer.lock');
        }

        $packagePath = $installedPackage['install_path'] ?? null;
        if (! is_string($packagePath) || ! is_dir($packagePath)) {
            throw new RuntimeException('installed durable-workflow/ai package path is unavailable');
        }

        foreach ($documents as $document) {
            if (! is_file($packagePath.'/'.$document)) {
                throw new RuntimeException("installed durable-workflow/ai package does not contain {$document}");
            }
        }

        $installedComposer = readJsonObject($packagePath.'/composer.json');
        $lockedContracts = $package['extra']['durable-workflow']['contracts'] ?? null;
        $installedContracts = $installedComposer['extra']['durable-workflow']['contracts'] ?? null;
        if (! is_array($lockedContracts) || $installedContracts !== $lockedContracts) {
            throw new RuntimeException('installed AI provider contract metadata does not match composer.lock');
        }

        require_once $root.'/vendor/autoload.php';
        $contractTypes = [
            'sandbox-provider' => 'DurableWorkflow\\AI\\Contracts\\V1\\SandboxProvider',
            'sandbox-provider.snapshot-deletion' => 'DurableWorkflow\\AI\\Contracts\\V1\\SnapshotDeletingSandboxProvider',
            'sandbox-provider.snapshot-reconciliation' => 'DurableWorkflow\\AI\\Contracts\\V1\\SnapshotReconcilingSandboxProvider',
        ];
        foreach ($contractTypes as $contract => $type) {
            if (! isset($lockedContracts[$contract]) || ! interface_exists($type)) {
                throw new RuntimeException("installed AI package does not provide advertised contract {$contract}");
            }
        }

        $contractVersions = [
            'sandbox-provider' => constant('DurableWorkflow\\AI\\Contracts\\V1\\ProviderCapabilities::CONTRACT_VERSION'),
            'sandbox-provider.snapshot-deletion' => constant($contractTypes['sandbox-provider.snapshot-deletion'].'::CONTRACT_VERSION'),
            'sandbox-provider.snapshot-reconciliation' => constant($contractTypes['sandbox-provider.snapshot-reconciliation'].'::CONTRACT_VERSION'),
        ];
        foreach ($contractVersions as $contract => $version) {
            if (($lockedContracts[$contract] ?? null) !== $version) {
                throw new RuntimeException("installed AI contract {$contract} does not match its package metadata");
            }
        }
    }

    echo json_encode([
        'schema' => 'durable-workflow.sample-app.ai-contract-docs/v1',
        'package' => 'durable-workflow/ai',
        'reference' => $reference,
        'documents' => $expectedLinks,
        'installed-package-verified' => $requireInstalled,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "ai-contract-docs: {$error->getMessage()}\n");
    exit(1);
}
