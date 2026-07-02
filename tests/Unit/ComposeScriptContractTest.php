<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ComposeScriptContractTest extends TestCase
{
    public function test_smoke_restarts_worker_after_schema_refresh_before_samples(): void
    {
        $script = $this->script('scripts/compose-smoke.sh');

        $this->assertStringContainsString('compose_diagnostics()', $script);
        $this->assertStringContainsString('SAMPLE_APP_SAMPLE_TIMEOUT_SECONDS:-180', $script);
        $this->assertStringContainsString('SAMPLE_APP_DB_PROBE_TIMEOUT_SECONDS:-10', $script);
        $this->assertStringContainsString('SAMPLE_APP_MIGRATION_TIMEOUT_SECONDS:-180', $script);
        $this->assertStringContainsString('compose-smoke: all deterministic sample workflows passed', $script);
        $this->assertStringContainsString('restart_worker_after_schema_refresh()', $script);
        $this->assertStringContainsString('docker compose up -d --no-deps --force-recreate --wait worker', $script);
        $this->assertStringContainsString('SAMPLE_APP_CONFORMANCE_AFTER_SMOKE:-0', $script);
        $this->assertStringContainsString(
            'if [[ "${SAMPLE_APP_CONFORMANCE_AFTER_SMOKE:-0}" == "1" && "${SAMPLE_APP_SMOKE_ONLY:-0}" != "1" ]]; then',
            $script,
        );
        $this->assertStringContainsString('SAMPLE_APP_CONFORMANCE_AFTER_SMOKE_TIMEOUT_SECONDS:-1800', $script);
        $this->assertOrdered(
            $script,
            'docker compose exec -T app php artisan migrate:fresh --force',
            "\nrestart_worker_after_schema_refresh\n",
            'run_sample "simple workflow"',
            'run_sample "webhook workflow"',
            'SAMPLE_APP_CONFORMANCE_AFTER_SMOKE',
        );
    }

    public function test_conformance_restarts_worker_after_schema_refresh_before_harness(): void
    {
        $script = $this->script('scripts/compose-conformance.sh');

        $this->assertStringContainsString('compose_diagnostics()', $script);
        $this->assertStringContainsString('SAMPLE_APP_SERVICE_REBUILD_TIMEOUT_SECONDS:-600', $script);
        $this->assertStringContainsString('SAMPLE_APP_SERVICE_REFRESH_TIMEOUT_SECONDS:-300', $script);
        $this->assertStringContainsString('SAMPLE_APP_DB_PROBE_TIMEOUT_SECONDS:-10', $script);
        $this->assertStringContainsString('SAMPLE_APP_MIGRATION_TIMEOUT_SECONDS:-180', $script);
        $this->assertStringContainsString('SAMPLE_APP_CONFORMANCE_TIMEOUT_SECONDS:-1800', $script);
        $this->assertStringContainsString('SAMPLE_APP_METADATA_COPY_TIMEOUT_SECONDS:-60', $script);
        $this->assertStringContainsString('restart_worker_after_schema_refresh()', $script);
        $this->assertStringContainsString('docker compose up -d --no-deps --force-recreate --wait worker', $script);
        $this->assertStringContainsString('export SAMPLE_APP_COMMIT="$sample_app_commit"', $script);
        $this->assertStringContainsString('metadata_path="${SAMPLE_APP_CONFORMANCE_METADATA_PATH:-storage/app/sample-app-conformance-metadata.json}"', $script);
        $this->assertStringContainsString('--output="${metadata_container_path}"', $script);
        $this->assertStringContainsString('docker compose cp "app:${metadata_container_abs}" "$metadata_path"', $script);
        $this->assertOrdered(
            $script,
            'export SAMPLE_APP_COMMIT="$sample_app_commit"',
            "printf '\\n==> resolving current published artifact tuple\\n'",
            "\nrebuild_services_for_artifact_tuple\n",
            'docker compose exec -T app php artisan migrate:fresh --force',
            "\nrestart_worker_after_schema_refresh\n",
            'docker compose exec -T \\',
            'docker compose cp "app:${metadata_container_abs}" "$metadata_path"',
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
