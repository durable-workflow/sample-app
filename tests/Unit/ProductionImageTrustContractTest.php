<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionImageTrustContractTest extends TestCase
{
    public function test_external_runtime_and_composer_inputs_are_immutable(): void
    {
        $source = $this->dockerfile();
        preg_match_all('/^FROM (\S+)/mi', $source, $inputs);
        $external = array_values(array_filter($inputs[1], static fn (string $input): bool => $input !== 'base'));

        $this->assertCount(3, $external);
        foreach ($external as $input) {
            $this->assertMatchesRegularExpression('/^[^@]+:[^@]+@sha256:[a-f0-9]{64}$/D', $input);
        }
        $this->assertStringContainsString('node:22.23.4-bookworm-slim@', $source);
        $this->assertStringContainsString('composer:2.10.3@', $source);
        $this->assertStringContainsString('php:8.4.27-cli-bookworm@', $source);
    }

    public function test_node_is_checked_before_asset_and_final_browser_downloads(): void
    {
        $source = $this->dockerfile();
        $guard = 'node /usr/local/share/sample-app/verify-node-trust.mjs';
        $assetCheck = strpos($source, $guard);
        $assetDownload = strpos($source, 'npm ci');
        $finalCheck = strpos($source, $guard, $assetDownload);
        $browserDownload = strpos($source, 'npx playwright install');

        foreach ([$assetCheck, $assetDownload, $finalCheck, $browserDownload] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($assetDownload, $assetCheck);
        $this->assertStringContainsString('npm ci --ignore-scripts', $source);
        $this->assertGreaterThan($assetDownload, $finalCheck);
        $this->assertLessThan($browserDownload, $finalCheck);
    }

    public function test_php_trust_precedes_os_and_application_dependency_requests(): void
    {
        $source = $this->dockerfile();
        $guard = 'php /usr/local/share/sample-app/verify-runtime-trust.php';
        $firstCheck = strpos($source, $guard);
        $osDownload = strpos($source, 'apt-get update');
        $vendorCheck = strpos($source, $guard, $osDownload);
        $vendorDownload = strpos($source, 'bash /usr/local/bin/install-composer-artifacts');

        foreach ([$firstCheck, $osDownload, $vendorCheck, $vendorDownload] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($osDownload, $firstCheck);
        $this->assertGreaterThan($osDownload, $vendorCheck);
        $this->assertLessThan($vendorDownload, $vendorCheck);
    }

    private function dockerfile(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile');
    }
}
