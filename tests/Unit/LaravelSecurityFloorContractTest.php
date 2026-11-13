<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LaravelSecurityFloorContractTest extends TestCase
{
    // Covers the concurrency and signed-URL advisories published in November 2026.
    private const APPLICATION_FLOORS = [
        '.' => '13.41.0',
        'microservice' => '12.72.0',
    ];

    public function test_application_constraints_exclude_affected_framework_versions(): void
    {
        foreach (self::APPLICATION_FLOORS as $directory => $floor) {
            $composer = $this->readJson($directory.'/composer.json');
            $constraint = $composer['require']['laravel/framework'] ?? null;

            $this->assertIsString($constraint, $directory);
            $this->assertMatchesRegularExpression('/^\^\d+\.\d+\.\d+$/D', $constraint, $directory);
            $this->assertTrue(version_compare(substr($constraint, 1), $floor, '>='), $directory);
        }
    }

    public function test_application_locks_exclude_affected_framework_versions(): void
    {
        foreach (self::APPLICATION_FLOORS as $directory => $floor) {
            $lock = $this->readJson($directory.'/composer.lock');
            $packages = array_column($lock['packages'], null, 'name');
            $version = $packages['laravel/framework']['version'] ?? null;

            $this->assertIsString($version, $directory);
            $this->assertTrue(version_compare(ltrim($version, 'v'), $floor, '>='), $directory);
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/'.$path),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
