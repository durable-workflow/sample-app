<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ComposeScriptContractTest extends TestCase
{
    public function test_smoke_restarts_worker_after_schema_refresh_before_samples(): void
    {
        $script = $this->script('scripts/compose-smoke.sh');

        $this->assertStringContainsString('restart_worker_after_schema_refresh()', $script);
        $this->assertStringContainsString('docker compose up -d --no-deps --force-recreate --wait worker', $script);
        $this->assertOrdered(
            $script,
            'docker compose exec -T app php artisan migrate:fresh --force',
            "\nrestart_worker_after_schema_refresh\n",
            'run_sample "simple workflow"',
        );
    }

    public function test_conformance_restarts_worker_after_schema_refresh_before_harness(): void
    {
        $script = $this->script('scripts/compose-conformance.sh');

        $this->assertStringContainsString('restart_worker_after_schema_refresh()', $script);
        $this->assertStringContainsString('docker compose up -d --no-deps --force-recreate --wait worker', $script);
        $this->assertOrdered(
            $script,
            'docker compose exec -T app php artisan migrate:fresh --force',
            "\nrestart_worker_after_schema_refresh\n",
            'docker compose exec -T \\',
        );
    }

    private function script(string $path): string
    {
        $contents = file_get_contents(__DIR__.'/../../'.$path);

        $this->assertIsString($contents);

        return $contents;
    }

    private function assertOrdered(string $haystack, string ...$needles): void
    {
        $previous = -1;

        foreach ($needles as $needle) {
            $position = strpos($haystack, $needle);

            $this->assertNotFalse($position, sprintf('Missing expected script fragment [%s].', $needle));
            $this->assertGreaterThan($previous, $position, sprintf(
                'Expected script fragment [%s] to appear after the previous fragment.',
                $needle,
            ));

            $previous = $position;
        }
    }
}
