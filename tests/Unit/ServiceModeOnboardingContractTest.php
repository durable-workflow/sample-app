<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Workflows\ServiceMode\WelcomeWorkflow;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class ServiceModeOnboardingContractTest extends TestCase
{
    public function test_service_mode_reuses_published_images_without_building_toolchains(): void
    {
        $compose = Yaml::parseFile($this->repoPath('polyglot/service-mode.yml'));
        $services = $compose['services'] ?? [];
        $developmentImage = '${SAMPLE_APP_DEVCONTAINER_IMAGE:-ghcr.io/durable-workflow/sample-app-devcontainer:main}';

        foreach ($services as $service) {
            $this->assertArrayNotHasKey('build', $service);
        }
        foreach (['worker-app-setup', 'observer-app-setup', 'waterline-migrate', 'php-worker', 'waterline', 'journey', 'browser-smoke'] as $serviceName) {
            $this->assertSame($developmentImage, $services[$serviceName]['image'] ?? null);
        }
        foreach (['python-setup', 'python-worker'] as $serviceName) {
            $this->assertSame('python:3.12-slim', $services[$serviceName]['image'] ?? null);
        }
        foreach (['bootstrap', 'server'] as $serviceName) {
            $this->assertSame(
                '${DURABLE_SERVER_IMAGE:?resolve the current artifact tuple first}',
                $services[$serviceName]['image'] ?? null,
            );
        }

        $this->assertSame(
            '${DURABLE_WORKFLOW_PHP_SDK_VERSION:?resolve artifacts first}',
            $services['worker-app-setup']['environment']['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null,
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_PYTHON_SDK_VERSION:?resolve artifacts first}',
            $services['python-setup']['environment']['DURABLE_WORKFLOW_PYTHON_SDK_VERSION'] ?? null,
        );
        $this->assertSame(
            ['/bin/sh', '/source/scripts/setup-service-mode-python.sh'],
            $services['python-setup']['entrypoint'] ?? null,
        );
        $this->assertSame(
            WelcomeWorkflow::PHP_TASK_QUEUE,
            $services['php-worker']['environment']['DURABLE_WORKFLOW_TASK_QUEUE'] ?? null,
        );
        $this->assertSame(
            WelcomeWorkflow::PYTHON_TASK_QUEUE,
            $services['python-worker']['environment']['DURABLE_WORKFLOW_TASK_QUEUE'] ?? null,
        );
        foreach (['php-worker', 'waterline', 'journey'] as $serviceName) {
            $this->assertSame('file', $services[$serviceName]['environment']['SESSION_DRIVER'] ?? null);
        }
        $this->assertSame('service', $services['waterline']['environment']['WATERLINE_BACKEND'] ?? null);
        $this->assertSame(
            'http://server:8080',
            $services['waterline']['environment']['WATERLINE_SERVER_ENDPOINT'] ?? null,
        );
        $this->assertContains('service-observer-app:/observer:ro', $services['browser-smoke']['volumes'] ?? []);
    }

    public function test_waterline_migrations_gate_observer_readiness(): void
    {
        $compose = Yaml::parseFile($this->repoPath('polyglot/service-mode.yml'));
        $services = $compose['services'] ?? [];
        $migration = $services['waterline-migrate'] ?? [];

        $this->assertSame(
            [
                'php',
                'artisan',
                'migrate',
                '--path=vendor/durable-workflow/waterline/database/migrations',
                '--force',
                '--no-interaction',
            ],
            $migration['entrypoint'] ?? null,
        );
        $this->assertContains('service-observer-app:/var/www/html', $migration['volumes'] ?? []);
        $this->assertSame(
            'service_healthy',
            $migration['depends_on']['mysql']['condition'] ?? null,
        );
        $this->assertSame(
            'service_completed_successfully',
            $migration['depends_on']['observer-app-setup']['condition'] ?? null,
        );
        $this->assertSame(
            'service_completed_successfully',
            $services['waterline']['depends_on']['waterline-migrate']['condition'] ?? null,
        );

        $script = (string) file_get_contents($this->repoPath('scripts/service-mode.sh'));
        $setup = strpos($script, 'run_phase "application and language setup"');
        $database = strpos($script, 'run_phase "database readiness"');
        $migrations = strpos($script, 'run_phase "Waterline database migrations"');
        $readiness = strpos($script, 'run_phase "service startup and readiness"');

        $this->assertIsInt($setup);
        $this->assertIsInt($database);
        $this->assertIsInt($migrations);
        $this->assertIsInt($readiness);
        $this->assertTrue($setup < $database && $database < $migrations && $migrations < $readiness);
    }

    public function test_waterline_migration_failure_stops_in_the_migration_phase(): void
    {
        $temporaryDirectory = sys_get_temp_dir().'/service-mode-migration-'.bin2hex(random_bytes(6));
        $dockerPath = $temporaryDirectory.'/docker';
        $dockerLog = $temporaryDirectory.'/docker.log';

        $this->assertTrue(mkdir($temporaryDirectory, 0700));
        $this->assertNotFalse(file_put_contents($dockerPath, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

printf '%s\n' "$*" >> "$SERVICE_MODE_FAKE_DOCKER_LOG"

has_exit_code_from=false
for argument in "$@"; do
    if [[ "$argument" == "--exit-code-from" ]]; then
        has_exit_code_from=true
    fi
done

if [[ "$has_exit_code_from" == true && "${*: -1}" == "waterline-migrate" ]]; then
    exit 37
fi
BASH));
        $this->assertTrue(chmod($dockerPath, 0700));

        try {
            $process = new Process(
                ['bash', $this->repoPath('scripts/service-mode.sh')],
                env: [
                    'PATH' => $temporaryDirectory.PATH_SEPARATOR.getenv('PATH'),
                    'COMPOSE_PROJECT_NAME' => 'service-mode-migration-test',
                    'DURABLE_WORKFLOW_ARTIFACT_SOURCE' => 'pinned',
                    'SERVICE_MODE_EVIDENCE_PATH' => $temporaryDirectory.'/evidence.json',
                    'SERVICE_MODE_FAKE_DOCKER_LOG' => $dockerLog,
                ],
            );
            $process->run();

            $output = $process->getOutput().$process->getErrorOutput();
            $this->assertSame(37, $process->getExitCode(), $output);
            $this->assertStringContainsString(
                'Service mode failed during phase: Waterline database migrations.',
                $process->getErrorOutput(),
            );
            $this->assertStringNotContainsString('==> service startup and readiness', $output);

            $commands = (string) file_get_contents($dockerLog);
            $this->assertStringContainsString(
                'up --no-build --force-recreate --no-deps --exit-code-from waterline-migrate waterline-migrate',
                $commands,
            );
        } finally {
            @unlink($dockerPath);
            @unlink($dockerLog);
            @unlink($temporaryDirectory.'/evidence.json');
            @rmdir($temporaryDirectory);
        }
    }

    public function test_entrypoint_resolves_qualified_artifacts_and_keeps_builds_disabled(): void
    {
        $script = (string) file_get_contents($this->repoPath('scripts/service-mode.sh'));
        $workflow = Yaml::parseFile($this->repoPath('.github/workflows/smoke.yml'));

        $this->assertStringContainsString('scripts/resolve-current-artifacts.sh', $script);
        $this->assertStringContainsString(
            'DURABLE_WORKFLOW_ARTIFACT_SOURCE:-pinned',
            $script,
        );
        $this->assertSame(
            'pinned',
            $workflow['jobs']['service-mode']['env']['DURABLE_WORKFLOW_ARTIFACT_SOURCE'] ?? null,
        );
        $this->assertStringContainsString('--no-build', $script);
        $this->assertStringNotContainsString('docker compose build', $script);
        $this->assertStringContainsString('sample-app-service-mode', $script);
        $this->assertStringContainsString('service-mode-evidence.json', $script);
        $this->assertStringContainsString('waterline-mount-readiness.mjs', $script);
        $this->assertStringContainsString('run-service-mode-dialog-visual.mjs', $script);
        $this->assertStringContainsString('SERVICE_MODE_MOUNT_EVIDENCE', $script);
        $this->assertStringContainsString('SERVICE_MODE_DIALOG_EVIDENCE', $script);
    }

    public function test_observer_bootstrap_publishes_assets_from_the_installed_waterline_package(): void
    {
        $script = (string) file_get_contents($this->repoPath('scripts/setup-service-mode-app.sh'));

        $this->assertStringContainsString(
            '${DURABLE_WORKFLOW_PHP_SDK_VERSION:?Resolve the current PHP SDK version first}',
            $script,
        );
        $this->assertStringContainsString(
            '"durable-workflow/sdk:${DURABLE_WORKFLOW_PHP_SDK_VERSION}"',
            $script,
        );
        $this->assertStringNotContainsString('durable-workflow/sdk:^2.0@RC', $script);
        $this->assertStringContainsString('if [[ "$role" == observer ]]', $script);
        $this->assertStringContainsString('php artisan waterline:publish --no-interaction', $script);
        $this->assertLessThan(
            strpos($script, 'php artisan waterline:publish --no-interaction'),
            strpos($script, 'composer dump-autoload --no-dev --optimize --no-interaction'),
        );
    }

    public function test_qualified_php_artifacts_match_the_bootable_laravel_graph(): void
    {
        $tuple = json_decode(
            (string) file_get_contents($this->repoPath('polyglot/qualified-artifact-tuple.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $composer = json_decode(
            (string) file_get_contents($this->repoPath('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ([
            'sdk-php' => 'durable-workflow/sdk',
            'workflow' => 'durable-workflow/workflow',
            'waterline' => 'durable-workflow/waterline',
        ] as $artifact => $package) {
            $this->assertSame(
                $composer['require'][$package] ?? null,
                $tuple['artifacts'][$artifact] ?? null,
            );
        }
    }

    public function test_composer_artifact_validation_records_the_exact_installable_graph(): void
    {
        $tuple = json_decode(
            (string) file_get_contents($this->repoPath('polyglot/qualified-artifact-tuple.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $process = new Process([
            PHP_BINARY,
            $this->repoPath('scripts/ci/validate-composer-artifact-graph.php'),
        ]);
        $process->mustRun();

        $evidence = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            'durable-workflow.sample-app.composer-artifact-graph/v1',
            $evidence['schema'] ?? null,
        );
        $this->assertSame(
            [
                'server' => $tuple['artifacts']['server'] ?? null,
                'sdk-php' => $tuple['artifacts']['sdk-php'] ?? null,
                'workflow' => $tuple['artifacts']['workflow'] ?? null,
                'waterline' => $tuple['artifacts']['waterline'] ?? null,
            ],
            $evidence['artifacts'] ?? null,
        );
        $this->assertNull($evidence['waterline-requires-sdk-php'] ?? null);
    }

    public function test_composer_artifact_validation_rejects_a_stale_root_sdk_pin(): void
    {
        $composer = json_decode(
            (string) file_get_contents($this->repoPath('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $tuple = json_decode(
            (string) file_get_contents($this->repoPath('polyglot/qualified-artifact-tuple.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $qualifiedSdk = $tuple['artifacts']['sdk-php'] ?? null;
        $staleSdk = '2.0.0-rc.1';
        $this->assertIsString($qualifiedSdk);
        $this->assertNotSame($qualifiedSdk, $staleSdk);
        $composer['require']['durable-workflow/sdk'] = $staleSdk;
        $temporaryComposer = tempnam(sys_get_temp_dir(), 'sample-app-composer-');
        $this->assertNotFalse($temporaryComposer);
        $this->assertNotFalse(file_put_contents(
            $temporaryComposer,
            json_encode($composer, JSON_THROW_ON_ERROR),
        ));

        try {
            $process = new Process([
                PHP_BINARY,
                $this->repoPath('scripts/ci/validate-composer-artifact-graph.php'),
                $temporaryComposer,
                $this->repoPath('composer.lock'),
                $this->repoPath('polyglot/qualified-artifact-tuple.json'),
            ]);
            $process->run();

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString(
                "durable-workflow/sdk root requirement \"{$staleSdk}\" does not match qualified sdk-php {$qualifiedSdk}",
                $process->getErrorOutput(),
            );
        } finally {
            @unlink($temporaryComposer);
        }
    }

    public function test_python_setup_is_repeatable_and_fails_closed(): void
    {
        $scriptPath = $this->repoPath('scripts/setup-service-mode-python.sh');
        $script = (string) file_get_contents($scriptPath);

        $this->assertTrue(is_executable($scriptPath));
        $this->assertStringContainsString('set -eu', $script);
        $this->assertStringContainsString('python_version=', $script);
        $this->assertStringContainsString('if [ "$installed" = "$python_version" ]', $script);
        $this->assertStringContainsString('if [ "$installed" != "$python_version" ]', $script);
    }

    public function test_entrypoint_verifies_the_exact_waterline_run_before_reporting_success(): void
    {
        $script = (string) file_get_contents($this->repoPath('scripts/service-mode.sh'));

        $this->assertStringContainsString('waterline_page_path=', $script);
        $this->assertStringContainsString('waterline_api_path=', $script);
        $this->assertStringContainsString('exec -T waterline curl --fail', $script);
        $this->assertStringContainsString(
            'selection.instance_id !== journey.workflow_id',
            $script,
        );
        $this->assertStringContainsString(
            'selection.selected_run_id !== journey.run_id',
            $script,
        );
        $this->assertLessThan(
            strpos($script, 'Browser proof:'),
            strpos($script, 'selection.selected_run_id !== journey.run_id'),
        );
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
