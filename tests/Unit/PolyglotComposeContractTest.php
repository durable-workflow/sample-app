<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PolyglotComposeContractTest extends TestCase
{
    public function test_polyglot_compose_does_not_pin_a_shared_project_name(): void
    {
        $compose = Yaml::parseFile($this->repoPath('polyglot/docker-compose.yml'));

        $this->assertArrayNotHasKey('name', $compose);
    }

    public function test_polyglot_server_does_not_publish_a_fixed_host_port(): void
    {
        $composePath = $this->repoPath('polyglot/docker-compose.yml');
        $compose = Yaml::parseFile($composePath);
        $server = $compose['services']['server'] ?? [];

        $this->assertArrayNotHasKey('ports', $server);
        $this->assertSame(['8080'], $server['expose'] ?? null);

        $composeYaml = (string) file_get_contents($composePath);
        $this->assertStringNotContainsString('${SERVER_PORT:-8080}:8080', $composeYaml);
        $this->assertStringNotContainsString('SERVER_PORT:-8080', $composeYaml);
    }

    public function test_polyglot_compose_artifacts_come_from_resolved_environment(): void
    {
        $compose = Yaml::parseFile($this->repoPath('polyglot/docker-compose.yml'));
        $services = $compose['services'] ?? [];
        $resolvedServerImage = $this->requiredResolvedEnv('DURABLE_SERVER_IMAGE');
        $resolvedCliVersion = $this->requiredResolvedEnv('DURABLE_WORKFLOW_CLI_VERSION');
        $resolvedPythonVersion = $this->requiredResolvedEnv('DURABLE_WORKFLOW_PYTHON_SDK_VERSION');
        $resolvedRustVersion = $this->requiredResolvedEnv('DURABLE_WORKFLOW_RUST_SDK_VERSION');
        $resolvedPhpSdkVersion = $this->requiredResolvedEnv('DURABLE_WORKFLOW_PHP_SDK_VERSION');
        $resolvedWorkflowVersion = $this->requiredResolvedEnv('DURABLE_WORKFLOW_WORKFLOW_VERSION');
        $resolvedWaterlineVersion = $this->requiredResolvedEnv('DURABLE_WORKFLOW_WATERLINE_VERSION');

        foreach (['bootstrap', 'server'] as $serviceName) {
            $this->assertSame($resolvedServerImage, $services[$serviceName]['image'] ?? null);
        }

        $this->assertSame(
            $resolvedServerImage,
            $services['smoke']['environment']['DURABLE_SERVER_IMAGE'] ?? null,
        );
        $this->assertSame(
            $resolvedCliVersion,
            $services['smoke']['environment']['DURABLE_WORKFLOW_CLI_VERSION'] ?? null,
        );
        $this->assertSame(
            $resolvedPythonVersion,
            $services['smoke']['environment']['DURABLE_WORKFLOW_PYTHON_SDK_VERSION'] ?? null,
        );
        $this->assertSame(
            $resolvedRustVersion,
            $services['smoke']['environment']['DURABLE_WORKFLOW_RUST_SDK_VERSION'] ?? null,
        );
        $this->assertSame(
            $resolvedPhpSdkVersion,
            $services['smoke']['environment']['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null,
        );
        $this->assertSame(
            $resolvedWorkflowVersion,
            $services['smoke']['environment']['DURABLE_WORKFLOW_WORKFLOW_VERSION'] ?? null,
        );
        $this->assertSame(
            $resolvedWaterlineVersion,
            $services['smoke']['environment']['DURABLE_WORKFLOW_WATERLINE_VERSION'] ?? null,
        );

        foreach ([
            'python-workflow-worker',
            'python-activity-worker',
            'smoke',
        ] as $serviceName) {
            $buildArgs = $services[$serviceName]['build']['args'] ?? [];
            $this->assertSame($resolvedPythonVersion, $buildArgs['DURABLE_WORKFLOW_PYTHON_SDK_VERSION'] ?? null);
            $this->assertSame(
                '${DURABLE_WORKFLOW_PYTHON_AVRO_VERSION:-1.12.1}',
                $buildArgs['APACHE_AVRO_PYTHON_VERSION'] ?? null,
            );
        }

        foreach (['python-activity-worker', 'smoke'] as $serviceName) {
            $buildArgs = $services[$serviceName]['build']['args'] ?? [];
            $this->assertSame($resolvedCliVersion, $buildArgs['DURABLE_WORKFLOW_CLI_VERSION'] ?? null);
        }

        foreach ([
            'php-same-workflow-worker',
            'php-same-activity-worker',
            'php-workflow-worker',
            'php-to-rust-workflow-worker',
            'php-query-worker',
            'php-activity-worker',
        ] as $serviceName) {
            $buildArgs = $services[$serviceName]['build']['args'] ?? [];
            $this->assertSame('./php_worker', $services[$serviceName]['build']['context'] ?? null);
            $this->assertSame($resolvedPhpSdkVersion, $buildArgs['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null);
            $this->assertArrayNotHasKey('DURABLE_WORKFLOW_WORKFLOW_VERSION', $buildArgs);
            $this->assertArrayNotHasKey('DURABLE_WORKFLOW_WATERLINE_VERSION', $buildArgs);
        }

        $waterlineBuildArgs = $services['waterline']['build']['args'] ?? [];
        $this->assertSame('polyglot/laravel/Dockerfile', $services['waterline']['build']['dockerfile'] ?? null);
        $this->assertSame($resolvedWorkflowVersion, $waterlineBuildArgs['DURABLE_WORKFLOW_WORKFLOW_VERSION'] ?? null);
        $this->assertSame($resolvedWaterlineVersion, $waterlineBuildArgs['DURABLE_WORKFLOW_WATERLINE_VERSION'] ?? null);
        $this->assertArrayNotHasKey('DURABLE_WORKFLOW_PHP_SDK_VERSION', $waterlineBuildArgs);

        foreach (['rust-workflow-worker', 'rust-activity-worker'] as $serviceName) {
            $buildArgs = $services[$serviceName]['build']['args'] ?? [];
            $this->assertSame($resolvedRustVersion, $buildArgs['DURABLE_WORKFLOW_RUST_SDK_VERSION'] ?? null);
            $this->assertSame('${DURABLE_WORKFLOW_RUST_AVRO_VERSION:-0.21.0}', $buildArgs['APACHE_AVRO_RUST_VERSION'] ?? null);
        }

        $smokeShell = (string) file_get_contents($this->repoPath('polyglot/python_worker/scripts/smoke.sh'));
        $this->assertStringContainsString('require_artifact_env DURABLE_SERVER_IMAGE', $smokeShell);
        $this->assertDoesNotMatchRegularExpression('/durableworkflow\/server:0\.2\.\d+/', $smokeShell);

        $smokeDriver = (string) file_get_contents($this->repoPath('polyglot/python_worker/scripts/polyglot_smoke.py'));
        $this->assertStringContainsString('SERVER_PIN = required_env("DURABLE_SERVER_IMAGE")', $smokeDriver);
        $this->assertDoesNotMatchRegularExpression('/durableworkflow\/server:0\.2\.\d+/', $smokeDriver);
    }

    public function test_sample_app_compose_can_build_against_resolved_php_artifacts(): void
    {
        $compose = Yaml::parseFile($this->repoPath('docker-compose.yml'));
        $services = $compose['services'] ?? [];
        $dockerfile = (string) file_get_contents($this->repoPath('Dockerfile'));
        $installScript = (string) file_get_contents($this->repoPath('scripts/install-composer-artifacts.sh'));
        $script = (string) file_get_contents($this->repoPath('scripts/compose-conformance.sh'));

        foreach (['app', 'worker', 'seed'] as $serviceName) {
            $buildArgs = $services[$serviceName]['build']['args'] ?? [];

            $this->assertSame(
                '${DURABLE_WORKFLOW_WORKFLOW_PIN:-}',
                $buildArgs['DURABLE_WORKFLOW_WORKFLOW_PIN'] ?? null,
            );
            $this->assertSame(
                '${DURABLE_WORKFLOW_WORKFLOW_VERSION:-}',
                $buildArgs['DURABLE_WORKFLOW_WORKFLOW_VERSION'] ?? null,
            );
            $this->assertSame(
                '${DURABLE_WORKFLOW_WATERLINE_PIN:-}',
                $buildArgs['DURABLE_WORKFLOW_WATERLINE_PIN'] ?? null,
            );
            $this->assertSame(
                '${DURABLE_WORKFLOW_WATERLINE_VERSION:-}',
                $buildArgs['DURABLE_WORKFLOW_WATERLINE_VERSION'] ?? null,
            );
            $this->assertSame(
                '${SAMPLE_APP_COMMIT:-}',
                $buildArgs['SAMPLE_APP_COMMIT'] ?? null,
            );
        }

        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_WORKFLOW_PIN=\n", $dockerfile);
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_WATERLINE_PIN=\n", $dockerfile);
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_WORKFLOW_VERSION=\n", $dockerfile);
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_WATERLINE_VERSION=\n", $dockerfile);
        $this->assertStringContainsString("ARG SAMPLE_APP_COMMIT=\n", $dockerfile);
        $this->assertStringContainsString('ENV SAMPLE_APP_COMMIT=${SAMPLE_APP_COMMIT}', $dockerfile);
        $this->assertSame(
            '${DURABLE_WORKFLOW_RUST_SDK_VERSION:-}',
            $services['app']['environment']['DURABLE_WORKFLOW_RUST_SDK_VERSION'] ?? null,
        );
        $this->assertStringContainsString('-e DURABLE_WORKFLOW_RUST_SDK_VERSION', $script);
        $this->assertStringContainsString(
            'COPY scripts/install-composer-artifacts.sh /usr/local/bin/install-composer-artifacts',
            $dockerfile,
        );
        $this->assertStringContainsString('RUN bash /usr/local/bin/install-composer-artifacts', $dockerfile);
        $this->assertStringContainsString('artifact_constraint_from_pin', $installScript);
        $this->assertStringContainsString('locked_package_version durable-workflow/workflow', $installScript);
        $this->assertStringContainsString('locked_package_version durable-workflow/waterline', $installScript);
        $this->assertStringContainsString('composer install "${install_flags[@]}"', $installScript);
        $this->assertStringContainsString('composer require --no-update', $installScript);
        $this->assertStringContainsString('composer update durable-workflow/workflow durable-workflow/waterline', $installScript);
        $this->assertStringContainsString(
            <<<'SH'
composer update durable-workflow/workflow durable-workflow/waterline \
  "${update_flags[@]}"
SH,
            $installScript,
        );
        $this->assertStringContainsString(
            <<<'SH'
update_flags=(
  --with-dependencies
  --no-dev
  --no-scripts
  --no-autoloader
  --prefer-dist
  --no-interaction
)
SH,
            $installScript,
        );
        $this->assertStringContainsString(
            <<<'SH'
if [[ "$locked_workflow_version" == "$workflow_constraint" && "$locked_waterline_version" == "$waterline_constraint" ]]; then
  composer install "${install_flags[@]}"
  exit 0
fi

composer require --no-update
SH,
            $installScript,
        );
        $this->assertStringContainsString('rebuild_services_for_artifact_tuple', $script);
        $this->assertStringContainsString('docker compose up -d --build --wait app worker', $script);
        $this->assertStringContainsString('export SAMPLE_APP_COMMIT="$sample_app_commit"', $script);
        $this->assertStringContainsString('--output="${metadata_container_path}"', $script);
        $this->assertStringContainsString('docker compose cp "app:${metadata_container_abs}" "$metadata_path"', $script);
        $this->assertOrdered(
            $script,
            'export SAMPLE_APP_COMMIT="$sample_app_commit"',
            "printf '\\n==> resolving current published artifact tuple\\n'",
            "\n  rebuild_services_for_artifact_tuple\n",
            'app php artisan app:conformance',
            'docker compose cp "app:${metadata_container_abs}" "$metadata_path"',
        );
        $this->assertStringContainsString('-e DURABLE_WORKFLOW_PHP_SDK_VERSION', $script);
        $this->assertStringContainsString('-e DURABLE_WORKFLOW_WORKFLOW_VERSION', $script);
        $this->assertStringContainsString('-e DURABLE_WORKFLOW_WATERLINE_VERSION', $script);
    }

    public function test_polyglot_validation_derives_compose_project_from_actions_run_context(): void
    {
        $workflowPath = $this->repoPath('.github/workflows/polyglot-validation.yml');
        $workflow = Yaml::parseFile($workflowPath);
        $steps = $workflow['jobs']['smoke']['steps'] ?? [];

        $setupStepIndex = null;
        $firstComposeStepIndex = null;
        $setupRun = null;

        foreach ($steps as $index => $step) {
            if (($step['name'] ?? null) === 'Set isolated Compose project') {
                $setupStepIndex = $index;
                $setupRun = (string) ($step['run'] ?? '');
            }

            if (
                $firstComposeStepIndex === null
                && is_string($step['run'] ?? null)
                && str_contains($step['run'], 'docker compose')
            ) {
                $firstComposeStepIndex = $index;
            }
        }

        $this->assertNotNull($setupStepIndex);
        $this->assertNotNull($firstComposeStepIndex);
        $this->assertLessThan($firstComposeStepIndex, $setupStepIndex);
        $this->assertStringContainsString('COMPOSE_PROJECT_NAME=$project', (string) $setupRun);
        $this->assertStringContainsString('$GITHUB_ENV', (string) $setupRun);
        $this->assertStringContainsString('GITHUB_RUN_ID', (string) $setupRun);
        $this->assertStringContainsString('GITHUB_JOB', (string) $setupRun);
        $this->assertStringContainsString('GITHUB_RUN_ATTEMPT', (string) $setupRun);
        $this->assertDoesNotMatchRegularExpression(
            '/COMPOSE_PROJECT_NAME:\s*sample-app-polyglot\s*(?:\R|$)/',
            (string) file_get_contents($workflowPath),
        );
    }

    public function test_compose_smoke_uses_an_ephemeral_host_port_and_isolates_retries(): void
    {
        $workflowPath = $this->repoPath('.github/workflows/smoke.yml');
        $workflow = Yaml::parseFile($workflowPath);
        $job = $workflow['jobs']['compose'] ?? [];
        $environment = $job['env'] ?? [];
        $steps = $job['steps'] ?? [];

        $this->assertSame('0', $environment['APP_PORT'] ?? null);
        $this->assertStringContainsString('github.run_id', $environment['COMPOSE_PROJECT_NAME'] ?? '');
        $this->assertStringContainsString('github.run_attempt', $environment['COMPOSE_PROJECT_NAME'] ?? '');
        $this->assertStringNotContainsString('18080', (string) file_get_contents($workflowPath));

        $teardownSteps = array_values(array_filter(
            $steps,
            static fn (array $step): bool => ($step['name'] ?? null) === 'Tear down stack',
        ));

        $this->assertCount(1, $teardownSteps);
        $this->assertSame('always()', $teardownSteps[0]['if'] ?? null);
        $this->assertStringContainsString('docker compose down', $teardownSteps[0]['run'] ?? '');
    }

    public function test_polyglot_worker_long_polls_are_ci_bounded(): void
    {
        $compose = Yaml::parseFile($this->repoPath('polyglot/docker-compose.yml'));
        $services = $compose['services'] ?? [];
        $serverEnv = $this->serverEnvironment($compose);

        $this->assertSame(
            '5',
            $services['python-workflow-worker']['environment']['DURABLE_WORKFLOW_POLL_TIMEOUT_SECONDS'] ?? null,
        );
        $this->assertSame(
            '5',
            $services['python-activity-worker']['environment']['DURABLE_WORKFLOW_POLL_TIMEOUT_SECONDS'] ?? null,
        );

        foreach ([
            'php-workflow-worker',
            'php-to-rust-workflow-worker',
            'php-query-worker',
            'php-activity-worker',
            'php-same-workflow-worker',
            'php-same-activity-worker',
        ] as $serviceName) {
            $this->assertContains(
                '--poll-timeout=5',
                $services[$serviceName]['command'] ?? [],
                sprintf('Expected %s to bound worker poll timeouts for CI smoke.', $serviceName),
            );
        }

        $serverPollTimeout = (int) ($serverEnv['DW_WORKER_POLL_TIMEOUT'] ?? 0);
        $pythonPollTimeout = (int) (
            $services['python-workflow-worker']['environment']['DURABLE_WORKFLOW_POLL_TIMEOUT_SECONDS'] ?? 0
        );

        $this->assertGreaterThan(0, $serverPollTimeout);
        $this->assertLessThan(
            $pythonPollTimeout,
            $serverPollTimeout,
            'The server long-poll window must stay below the Python worker HTTP poll timeout.',
        );
    }

    public function test_polyglot_smoke_covers_both_same_language_corners(): void
    {
        $compose = Yaml::parseFile($this->repoPath('polyglot/docker-compose.yml'));
        $services = $compose['services'] ?? [];
        $smoke = (string) file_get_contents($this->repoPath('polyglot/python_worker/scripts/polyglot_smoke.py'));

        $this->assertArrayHasKey('php-same-workflow-worker', $services);
        $this->assertArrayHasKey('php-same-activity-worker', $services);
        $this->assertStringContainsString('php_same_language', $smoke);
        $this->assertStringContainsString('python_same_language', $smoke);
    }

    public function test_polyglot_validation_rebuilds_baked_smoke_driver(): void
    {
        $workflow = Yaml::parseFile($this->repoPath('.github/workflows/polyglot-validation.yml'));
        $steps = $workflow['jobs']['smoke']['steps'] ?? [];
        $bringUpSteps = array_values(array_filter(
            $steps,
            static fn (array $step): bool => ($step['name'] ?? null) === 'Bring up polyglot stack',
        ));
        $smokeSteps = array_values(array_filter(
            $steps,
            static fn (array $step): bool => ($step['name'] ?? null) === 'Run polyglot smoke',
        ));

        $this->assertNotEmpty($bringUpSteps);
        $bringUpRun = (string) ($bringUpSteps[0]['run'] ?? '');
        $bringUpLines = array_values(array_filter(
            array_map('trim', explode("\n", $bringUpRun)),
            static fn (string $line): bool => $line !== '',
        ));
        $waitLineIndex = array_search('docker compose up -d --build --wait \\', $bringUpLines, true);

        $this->assertStringContainsString('docker compose up -d --build --wait', $bringUpRun);
        $this->assertStringContainsString('docker compose up -d --build --wait waterline', $bringUpRun);
        $this->assertStringContainsString('php-query-worker', $bringUpRun);
        $this->assertNotFalse($waitLineIndex);
        $this->assertArrayHasKey($waitLineIndex + 1, $bringUpLines);
        $this->assertStringNotContainsString('waterline', $bringUpLines[$waitLineIndex + 1]);

        $this->assertNotEmpty($smokeSteps);
        $this->assertSame(
            'docker compose run --rm --build smoke',
            trim((string) ($smokeSteps[0]['run'] ?? '')),
        );
    }

    public function test_polyglot_smoke_installs_published_cli_and_configures_waterline(): void
    {
        $compose = Yaml::parseFile($this->repoPath('polyglot/docker-compose.yml'));
        $services = $compose['services'] ?? [];
        $dockerfile = (string) file_get_contents($this->repoPath('polyglot/python_worker/Dockerfile'));
        $pythonWorkflowDockerfile = (string) file_get_contents($this->repoPath('polyglot/python_workflow/Dockerfile'));
        $phpDockerfile = (string) file_get_contents($this->repoPath('polyglot/php_worker/Dockerfile'));
        $phpWorker = (string) file_get_contents($this->repoPath('polyglot/php_worker/worker.php'));
        $laravelDockerfile = (string) file_get_contents($this->repoPath('polyglot/laravel/Dockerfile'));
        $phpEntrypoint = (string) file_get_contents($this->repoPath('docker/entrypoint.sh'));
        $smokeShell = (string) file_get_contents($this->repoPath('polyglot/python_worker/scripts/smoke.sh'));
        $composerJson = json_decode(
            (string) file_get_contents($this->repoPath('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $composerLock = json_decode(
            (string) file_get_contents($this->repoPath('composer.lock')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $lockedPackages = array_column($composerLock['packages'] ?? [], null, 'name');

        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_CLI_VERSION\n", $dockerfile);
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_PYTHON_SDK_VERSION\n", $dockerfile);
        $this->assertStringContainsString('ARG APACHE_AVRO_PYTHON_VERSION=1.12.1', $dockerfile);
        $this->assertStringContainsString('test -n "$DURABLE_WORKFLOW_CLI_VERSION"', $dockerfile);
        $this->assertStringContainsString('test -n "$DURABLE_WORKFLOW_PYTHON_SDK_VERSION"', $dockerfile);
        $this->assertStringContainsString('https://durable-workflow.com/install.sh', $dockerfile);
        $this->assertStringContainsString('VERSION="${DURABLE_WORKFLOW_CLI_VERSION}"', $dockerfile);
        $this->assertStringContainsString(
            'durable-workflow==${DURABLE_WORKFLOW_PYTHON_SDK_VERSION}',
            $dockerfile,
        );
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_PYTHON_SDK_VERSION\n", $pythonWorkflowDockerfile);
        $this->assertStringContainsString('ARG APACHE_AVRO_PYTHON_VERSION=1.12.1', $pythonWorkflowDockerfile);
        $this->assertStringContainsString('test -n "$DURABLE_WORKFLOW_PYTHON_SDK_VERSION"', $pythonWorkflowDockerfile);
        $this->assertStringContainsString(
            'durable-workflow==${DURABLE_WORKFLOW_PYTHON_SDK_VERSION}',
            $pythonWorkflowDockerfile,
        );
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_PHP_SDK_PIN=\n", $phpDockerfile);
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_PHP_SDK_VERSION\n", $phpDockerfile);
        $this->assertStringNotContainsString('composer require --no-dev', $phpDockerfile);
        $this->assertStringContainsString('"durable-workflow/sdk:${constraint}"', $phpDockerfile);
        $this->assertStringContainsString('composer show apache/avro', $phpDockerfile);
        $this->assertStringContainsString('! composer show durable-workflow/workflow', $phpDockerfile);
        $this->assertStringContainsString('! composer show laravel/framework', $phpDockerfile);
        $this->assertStringNotContainsString('DURABLE_WORKFLOW_WATERLINE', $phpDockerfile);
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_WORKFLOW_PIN=\n", $laravelDockerfile);
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_WATERLINE_PIN=\n", $laravelDockerfile);
        $this->assertStringContainsString('RUN bash /usr/local/bin/install-composer-artifacts', $laravelDockerfile);
        $this->assertStringNotContainsString('ARG DURABLE_WORKFLOW_CLI_VERSION=0.1.', $dockerfile);
        $this->assertStringNotContainsString('ARG DURABLE_WORKFLOW_PYTHON_SDK_VERSION=0.4.', $dockerfile);
        $this->assertStringNotContainsString('ARG DURABLE_WORKFLOW_PYTHON_SDK_VERSION=0.4.', $pythonWorkflowDockerfile);
        $this->assertStringNotContainsString('ARG DURABLE_WORKFLOW_PHP_SDK_VERSION=2.0.0-', $phpDockerfile);
        $this->assertStringNotContainsString('2.0.0-alpha.', $phpDockerfile);
        $this->assertStringContainsString('use Composer\\InstalledVersions;', $phpWorker);
        $this->assertStringContainsString('use DurableWorkflow\\Client;', $phpWorker);
        $this->assertStringContainsString('use DurableWorkflow\\Worker;', $phpWorker);
        $this->assertStringContainsString("'polyglot.php-to-python.typed-error'", $phpWorker);
        $this->assertStringContainsString("'polyglot.php.signal-query'", $phpWorker);
        $this->assertStringContainsString("'package' => 'apache/avro'", $phpWorker);
        $this->assertStringNotContainsString('Illuminate\\', $phpWorker);
        $this->assertStringNotContainsString('Workflow\\V2', $phpWorker);
        $this->assertStringContainsString('append_env_var WATERLINE_PATH', $phpEntrypoint);
        $this->assertStringContainsString('append_env_var WATERLINE_ENGINE_SOURCE', $phpEntrypoint);
        $this->assertStringContainsString('append_env_var WATERLINE_NAMESPACE', $phpEntrypoint);
        $this->assertStringContainsString('append_env_var WATERLINE_ALLOW_UNAUTHENTICATED', $phpEntrypoint);
        $this->assertStringContainsString(
            'DURABLE_WORKFLOW_CLI_PIN="dw==${DURABLE_WORKFLOW_CLI_VERSION}"',
            $smokeShell,
        );
        $this->assertStringContainsString('require_artifact_env DURABLE_WORKFLOW_CLI_VERSION', $smokeShell);
        $this->assertStringContainsString('require_artifact_env DURABLE_WORKFLOW_PYTHON_SDK_VERSION', $smokeShell);
        $this->assertStringContainsString('require_artifact_env DURABLE_WORKFLOW_RUST_SDK_VERSION', $smokeShell);
        $this->assertStringContainsString('require_artifact_env DURABLE_WORKFLOW_PHP_SDK_VERSION', $smokeShell);
        $this->assertStringContainsString('require_artifact_env DURABLE_WORKFLOW_WORKFLOW_VERSION', $smokeShell);
        $this->assertStringContainsString('require_artifact_env DURABLE_WORKFLOW_WATERLINE_VERSION', $smokeShell);
        $this->assertStringContainsString('DURABLE_WORKFLOW_PHP_SDK_PIN:=}', $smokeShell);
        $this->assertStringContainsString('DURABLE_WORKFLOW_WORKFLOW_PIN:=}', $smokeShell);
        $this->assertStringContainsString('DURABLE_WORKFLOW_WATERLINE_PIN:=}', $smokeShell);
        $this->assertStringContainsString('${DURABLE_WORKFLOW_PHP_SDK_PIN#durable-workflow/sdk:}', $smokeShell);
        $this->assertStringContainsString(
            'DURABLE_WORKFLOW_PHP_SDK_PIN="durable-workflow/sdk:${DURABLE_WORKFLOW_PHP_SDK_VERSION}@beta"',
            $smokeShell,
        );
        $this->assertStringContainsString(
            'DURABLE_WORKFLOW_WORKFLOW_PIN="durable-workflow/workflow:${DURABLE_WORKFLOW_WORKFLOW_VERSION}@beta"',
            $smokeShell,
        );
        $this->assertStringContainsString(
            '${DURABLE_WORKFLOW_WATERLINE_PIN#durable-workflow/waterline:}',
            $smokeShell,
        );
        $this->assertStringContainsString(
            'DURABLE_WORKFLOW_WATERLINE_PIN="durable-workflow/waterline:${DURABLE_WORKFLOW_WATERLINE_VERSION}@beta"',
            $smokeShell,
        );
        $this->assertStringNotContainsString('DURABLE_WORKFLOW_WATERLINE_VERSION:=2.0.0-alpha.50', $smokeShell);
        $this->assertStringNotContainsString('DURABLE_WORKFLOW_PHP_SDK_VERSION:=2.0.0-', $smokeShell);
        $this->assertStringNotContainsString('DURABLE_WORKFLOW_WATERLINE_VERSION:=2.0.0-', $smokeShell);
        $this->assertSame('2.0.0-beta.5', $composerJson['require']['durable-workflow/sdk'] ?? null);
        $this->assertSame('2.0.0-beta.5', $composerJson['require']['durable-workflow/workflow'] ?? null);
        $this->assertSame('2.0.0-beta.5', $composerJson['require']['durable-workflow/waterline'] ?? null);
        $this->assertArrayNotHasKey('repositories', $composerJson);
        $this->assertIsArray($lockedPackages['durable-workflow/sdk'] ?? null);
        $this->assertSame(
            '2.0.0-beta.5',
            $lockedPackages['durable-workflow/sdk']['version'] ?? null,
        );
        $this->assertSame(
            '2.0.0-beta.5',
            $lockedPackages['durable-workflow/sdk']['extra']['durable-workflow']['product-train'] ?? null,
        );
        $this->assertSame(
            'ce0a27a2ef487a8d56e84c38851eed421bf8f7a0',
            $lockedPackages['durable-workflow/sdk']['source']['reference'] ?? null,
        );
        $this->assertSame(
            'https://packagist.org/downloads/',
            $lockedPackages['durable-workflow/sdk']['notification-url'] ?? null,
        );
        $this->assertIsArray($lockedPackages['durable-workflow/workflow'] ?? null);
        $this->assertSame(
            '2.0.0-beta.5',
            $lockedPackages['durable-workflow/workflow']['version'] ?? null,
        );
        $this->assertSame(
            '2.0.0-beta.5',
            $lockedPackages['durable-workflow/workflow']['extra']['durable-workflow']['product-train'] ?? null,
        );
        $this->assertSame(
            '08fab5ff5c51fd31ce8306b39edb10996d5a8531',
            $lockedPackages['durable-workflow/workflow']['source']['reference'] ?? null,
        );
        $this->assertSame(
            'https://github.com/durable-workflow/workflow.git',
            $lockedPackages['durable-workflow/workflow']['source']['url'] ?? null,
        );
        $this->assertSame(
            'https://api.github.com/repos/durable-workflow/workflow/zipball/08fab5ff5c51fd31ce8306b39edb10996d5a8531',
            $lockedPackages['durable-workflow/workflow']['dist']['url'] ?? null,
        );
        $this->assertSame(
            '08fab5ff5c51fd31ce8306b39edb10996d5a8531',
            $lockedPackages['durable-workflow/workflow']['dist']['reference'] ?? null,
        );
        $this->assertSame(
            'https://packagist.org/downloads/',
            $lockedPackages['durable-workflow/workflow']['notification-url'] ?? null,
        );
        $this->assertIsArray($lockedPackages['durable-workflow/waterline'] ?? null);
        $this->assertSame(
            '2.0.0-beta.5',
            $lockedPackages['durable-workflow/waterline']['version'] ?? null,
        );
        $this->assertSame(
            '2.0.0-beta.5',
            $lockedPackages['durable-workflow/waterline']['extra']['durable-workflow']['product-train'] ?? null,
        );
        $this->assertSame(
            'e8d88425751e212cfd32dc5e2cae8f8e9a15cfd7',
            $lockedPackages['durable-workflow/waterline']['source']['reference'] ?? null,
        );
        $this->assertSame(
            'https://github.com/durable-workflow/waterline.git',
            $lockedPackages['durable-workflow/waterline']['source']['url'] ?? null,
        );
        $this->assertSame(
            'https://api.github.com/repos/durable-workflow/waterline/zipball/e8d88425751e212cfd32dc5e2cae8f8e9a15cfd7',
            $lockedPackages['durable-workflow/waterline']['dist']['url'] ?? null,
        );
        $this->assertSame(
            '2.0.0-beta.5',
            $lockedPackages['durable-workflow/waterline']['require']['durable-workflow/sdk'] ?? null,
        );
        $this->assertSame(
            'https://packagist.org/downloads/',
            $lockedPackages['durable-workflow/waterline']['notification-url'] ?? null,
        );

        $this->assertArrayHasKey('waterline', $services);
        $this->assertSame('v2', $services['waterline']['environment']['WATERLINE_ENGINE_SOURCE'] ?? null);
        $this->assertSame('default', $services['waterline']['environment']['WATERLINE_NAMESPACE'] ?? null);
        $this->assertSame('true', $services['waterline']['environment']['WATERLINE_ALLOW_UNAUTHENTICATED'] ?? null);
        $this->assertSame('mysql', $services['waterline']['environment']['DB_HOST'] ?? null);
        $this->assertSame(3306, $services['waterline']['environment']['DB_PORT'] ?? null);
        $this->assertSame('durable_workflow', $services['waterline']['environment']['DB_DATABASE'] ?? null);
        $this->assertSame('workflow', $services['waterline']['environment']['DB_USERNAME'] ?? null);
        $this->assertSame('workflow', $services['waterline']['environment']['DB_PASSWORD'] ?? null);
        $this->assertSame('mysql', $services['waterline']['environment']['SHARED_DB_HOST'] ?? null);
        $this->assertSame(3306, $services['waterline']['environment']['SHARED_DB_PORT'] ?? null);
        $this->assertSame('durable_workflow', $services['waterline']['environment']['SHARED_DB_DATABASE'] ?? null);
        $this->assertSame('workflow', $services['waterline']['environment']['SHARED_DB_USERNAME'] ?? null);
        $this->assertSame('workflow', $services['waterline']['environment']['SHARED_DB_PASSWORD'] ?? null);
        $this->assertSame(['8081'], $services['waterline']['expose'] ?? null);
        $this->assertSame(
            ['CMD', 'curl', '-f', 'http://localhost:8081/waterline/api/v2/health'],
            $services['waterline']['healthcheck']['test'] ?? null,
        );
        $this->assertArrayHasKey('waterline', $services['smoke']['depends_on'] ?? []);
        $this->assertSame('service_healthy', $services['smoke']['depends_on']['waterline']['condition'] ?? null);
        $this->assertSame(
            'http://waterline:8081/waterline',
            $services['smoke']['environment']['DURABLE_WORKFLOW_WATERLINE_URL'] ?? null,
        );
        $this->assertSame(
            'http://waterline:8081/polyglot/conformance/artifacts',
            $services['smoke']['environment']['DURABLE_WORKFLOW_ARTIFACT_PROBE_URL'] ?? null,
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_CLI_PIN:-}',
            $services['smoke']['environment']['DURABLE_WORKFLOW_CLI_PIN'] ?? null,
        );
        $this->assertSame(
            $this->requiredResolvedEnv('DURABLE_WORKFLOW_PYTHON_SDK_VERSION'),
            $services['smoke']['environment']['DURABLE_WORKFLOW_PYTHON_SDK_VERSION'] ?? null,
        );
        $this->assertSame(
            $this->requiredResolvedEnv('DURABLE_WORKFLOW_RUST_SDK_VERSION'),
            $services['smoke']['environment']['DURABLE_WORKFLOW_RUST_SDK_VERSION'] ?? null,
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_PHP_SDK_PIN:-}',
            $services['smoke']['environment']['DURABLE_WORKFLOW_PHP_SDK_PIN'] ?? null,
        );
        $this->assertSame(
            $this->requiredResolvedEnv('DURABLE_WORKFLOW_PHP_SDK_VERSION'),
            $services['smoke']['environment']['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null,
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_WORKFLOW_PIN:-}',
            $services['smoke']['environment']['DURABLE_WORKFLOW_WORKFLOW_PIN'] ?? null,
        );
        $this->assertSame(
            $this->requiredResolvedEnv('DURABLE_WORKFLOW_WORKFLOW_VERSION'),
            $services['smoke']['environment']['DURABLE_WORKFLOW_WORKFLOW_VERSION'] ?? null,
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_WATERLINE_PIN:-}',
            $services['smoke']['environment']['DURABLE_WORKFLOW_WATERLINE_PIN'] ?? null,
        );
        $this->assertSame(
            $this->requiredResolvedEnv('DURABLE_WORKFLOW_WATERLINE_VERSION'),
            $services['smoke']['environment']['DURABLE_WORKFLOW_WATERLINE_VERSION'] ?? null,
        );

        foreach ([
            'php-same-workflow-worker',
            'php-same-activity-worker',
            'php-workflow-worker',
            'php-to-rust-workflow-worker',
            'php-query-worker',
            'php-activity-worker',
        ] as $serviceName) {
            $this->assertSame(
                '${DURABLE_WORKFLOW_PHP_SDK_PIN:-}',
                $services[$serviceName]['build']['args']['DURABLE_WORKFLOW_PHP_SDK_PIN'] ?? null,
            );
            $this->assertSame(
                $this->requiredResolvedEnv('DURABLE_WORKFLOW_PHP_SDK_VERSION'),
                $services[$serviceName]['build']['args']['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null,
            );
            $this->assertArrayNotHasKey('DURABLE_WORKFLOW_WORKFLOW_PIN', $services[$serviceName]['build']['args']);
            $this->assertArrayNotHasKey('DURABLE_WORKFLOW_WATERLINE_PIN', $services[$serviceName]['build']['args']);
        }

        $this->assertSame(
            '${DURABLE_WORKFLOW_WORKFLOW_PIN:-}',
            $services['waterline']['build']['args']['DURABLE_WORKFLOW_WORKFLOW_PIN'] ?? null,
        );
        $this->assertSame(
            $this->requiredResolvedEnv('DURABLE_WORKFLOW_WORKFLOW_VERSION'),
            $services['waterline']['build']['args']['DURABLE_WORKFLOW_WORKFLOW_VERSION'] ?? null,
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_WATERLINE_PIN:-}',
            $services['waterline']['build']['args']['DURABLE_WORKFLOW_WATERLINE_PIN'] ?? null,
        );
        $this->assertArrayNotHasKey(
            'DURABLE_WORKFLOW_PHP_SDK_PIN',
            $services['waterline']['build']['args'],
        );
    }

    public function test_committed_waterline_assets_match_current_locked_package(): void
    {
        $manifest = json_decode(
            (string) file_get_contents($this->repoPath('public/vendor/waterline/mix-manifest.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame([
            '/app.js' => '/app.js?id=c1613d31cfa6fd3b2963f1e03724a9d9',
            '/app-dark.css' => '/app-dark.css?id=a84f0f42b0d872355eb4eca96e5be831',
            '/app.css' => '/app.css?id=f87a5bb3ecda2dceae68cca620f0cd5e',
            '/img/favicon.png' => '/img/favicon.png?id=7c006241b093796d6abfa3049df93a59',
            '/img/sprite.svg' => '/img/sprite.svg?id=afc4952b74895bdef3ab4ebe9adb746f',
        ], $manifest);

        foreach ([
            'public/vendor/waterline/app.js' => '3a5dc062408b64b87b46e36b7e5600c8a74920544ceda3d220f0e4450ae346de',
            'public/vendor/waterline/app-dark.css' => '3ab900036ac2eaa4fd4e6ca29147cc387463a1d2d5897319b95ffc0037ad5990',
            'public/vendor/waterline/app.css' => '75ea859e81f5df8749c0721fc56b4b769bd593a1ba3c71d46290db71660ebe85',
        ] as $path => $expectedHash) {
            $this->assertSame($expectedHash, hash_file('sha256', $this->repoPath($path)), $path);
        }
    }

    public function test_polyglot_laravel_services_use_valid_aes_256_app_keys(): void
    {
        $compose = Yaml::parseFile($this->repoPath('polyglot/docker-compose.yml'));
        $serverEnv = $this->serverEnvironment($compose);
        $waterlineEnv = $compose['services']['waterline']['environment'] ?? [];

        $this->assertLaravelAppKeySupportsAes256($serverEnv['APP_KEY'] ?? null, 'server APP_KEY');
        $this->assertLaravelAppKeySupportsAes256($waterlineEnv['APP_KEY'] ?? null, 'waterline APP_KEY');
    }

    public function test_polyglot_artifact_resolver_preserves_explicit_overrides(): void
    {
        $assignments = $this->resolveArtifactAssignments([
            'DURABLE_SERVER_IMAGE' => 'ghcr.io/example/server:9.9.9',
            'DURABLE_WORKFLOW_CLI_PIN' => 'example/cli:9.9.8',
            'DURABLE_WORKFLOW_RUST_SDK_VERSION' => '9.9.7',
            'DURABLE_WORKFLOW_PHP_SDK_PIN' => 'durable-workflow/sdk:0.1.777',
            'DURABLE_WORKFLOW_WORKFLOW_PIN' => 'durable-workflow/workflow:2.0.0-alpha.777',
            'DURABLE_WORKFLOW_WATERLINE_PIN' => 'durable-workflow/waterline:2.0.0-alpha.778',
        ]);

        $this->assertSame('ghcr.io/example/server:9.9.9', $assignments['DURABLE_SERVER_IMAGE'] ?? null);
        $this->assertSame('9.9.9', $assignments['DURABLE_SERVER_VERSION'] ?? null);
        $this->assertSame('example/cli:9.9.8', $assignments['DURABLE_WORKFLOW_CLI_PIN'] ?? null);
        $this->assertSame('9.9.8', $assignments['DURABLE_WORKFLOW_CLI_VERSION'] ?? null);
        $this->assertSame('9.9.7', $assignments['DURABLE_WORKFLOW_RUST_SDK_VERSION'] ?? null);
        $this->assertSame(
            'durable-workflow/sdk:0.1.777',
            $assignments['DURABLE_WORKFLOW_PHP_SDK_PIN'] ?? null,
        );
        $this->assertSame('0.1.777', $assignments['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null);
        $this->assertSame(
            'durable-workflow/workflow:2.0.0-alpha.777',
            $assignments['DURABLE_WORKFLOW_WORKFLOW_PIN'] ?? null,
        );
        $this->assertSame('2.0.0-alpha.777', $assignments['DURABLE_WORKFLOW_WORKFLOW_VERSION'] ?? null);
        $this->assertSame(
            'durable-workflow/waterline:2.0.0-alpha.778',
            $assignments['DURABLE_WORKFLOW_WATERLINE_PIN'] ?? null,
        );
        $this->assertSame('2.0.0-alpha.778', $assignments['DURABLE_WORKFLOW_WATERLINE_VERSION'] ?? null);
    }

    public function test_polyglot_artifact_resolver_normalizes_legacy_cli_package_pin(): void
    {
        $assignments = $this->resolveArtifactAssignments([
            'DURABLE_WORKFLOW_CLI_PIN' => 'durable-workflow/cli:0.1.64',
        ]);

        $this->assertSame('0.1.64', $assignments['DURABLE_WORKFLOW_CLI_VERSION'] ?? null);
        $this->assertSame('dw==0.1.64', $assignments['DURABLE_WORKFLOW_CLI_PIN'] ?? null);
    }

    public function test_polyglot_artifact_resolver_loads_complete_official_tuple(): void
    {
        $assignments = $this->resolveArtifactAssignments();

        $this->assertSame('durableworkflow/server:2.0.0-beta.7', $assignments['DURABLE_SERVER_IMAGE'] ?? null);
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_SERVER_VERSION'] ?? null);
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_CLI_VERSION'] ?? null);
        $this->assertSame('dw==2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_CLI_PIN'] ?? null);
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_PYTHON_SDK_VERSION'] ?? null);
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_RUST_SDK_VERSION'] ?? null);
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null);
        $this->assertSame(
            'durable-workflow/sdk:2.0.0-beta.7@beta',
            $assignments['DURABLE_WORKFLOW_PHP_SDK_PIN'] ?? null,
        );
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_WORKFLOW_VERSION'] ?? null);
        $this->assertSame(
            'durable-workflow/workflow:2.0.0-beta.7@beta',
            $assignments['DURABLE_WORKFLOW_WORKFLOW_PIN'] ?? null,
        );
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_WATERLINE_VERSION'] ?? null);
        $this->assertSame(
            'durable-workflow/waterline:2.0.0-beta.7@beta',
            $assignments['DURABLE_WORKFLOW_WATERLINE_PIN'] ?? null,
        );
    }

    public function test_polyglot_artifact_resolver_rejects_genuinely_unknown_tuple_key(): void
    {
        $fixture = $this->repoPath('tests/Fixtures/unknown-artifact-tuple.json');
        $resolver = $this->repoPath('scripts/resolve-current-artifacts.sh');
        $command = sprintf(
            'env -i PATH=%s DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE=%s bash %s 2>&1',
            escapeshellarg((string) getenv('PATH')),
            escapeshellarg($fixture),
            escapeshellarg($resolver),
        );

        exec($command, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'contains unknown artifact keys: unknown-sdk',
            implode("\n", $output),
        );
    }

    public function test_polyglot_artifact_resolver_uses_current_tuple_source_by_default(): void
    {
        $assignments = $this->resolveArtifactAssignments([
            'DURABLE_WORKFLOW_CURRENT_ARTIFACT_TUPLE_URL' => 'file://'.$this->repoPath('tests/Fixtures/synthetic-artifact-tuple.json'),
        ], false);
        $artifactResolver = (string) file_get_contents($this->repoPath('scripts/resolve-current-artifacts.sh'));

        $this->assertSame('durableworkflow/server:2.0.0-beta.7', $assignments['DURABLE_SERVER_IMAGE'] ?? null);
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_CLI_VERSION'] ?? null);
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_PYTHON_SDK_VERSION'] ?? null);
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_RUST_SDK_VERSION'] ?? null);
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null);
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_WORKFLOW_VERSION'] ?? null);
        $this->assertSame('2.0.0-beta.7', $assignments['DURABLE_WORKFLOW_WATERLINE_VERSION'] ?? null);
        $this->assertStringContainsString('https://durable-workflow.com/docs-page-release-audit.json', $artifactResolver);
        $this->assertStringContainsString('must expose one synchronized 2.0 beta version', $artifactResolver);
        $this->assertStringNotContainsString('latest_dockerhub_server_version', $artifactResolver);
    }

    public function test_polyglot_artifact_resolver_rejects_mixed_beta_generations(): void
    {
        $resolver = $this->repoPath('scripts/resolve-current-artifacts.sh');
        $fixture = $this->repoPath('tests/Fixtures/lagging-artifact-tuple.json');
        $command = sprintf(
            'env -i PATH=%s DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE=%s bash %s 2>&1',
            escapeshellarg((string) getenv('PATH')),
            escapeshellarg($fixture),
            escapeshellarg($resolver),
        );

        exec($command, $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('must expose one synchronized 2.0 beta version', implode("\n", $output));
    }

    public function test_polyglot_artifact_resolver_keeps_pinned_tuple_explicit(): void
    {
        $assignments = $this->resolveArtifactAssignments([
            'DURABLE_WORKFLOW_ARTIFACT_SOURCE' => 'pinned',
        ]);

        $this->assertSame('durableworkflow/server:2.0.0-beta.5', $assignments['DURABLE_SERVER_IMAGE'] ?? null);
        $this->assertSame('2.0.0-beta.5', $assignments['DURABLE_SERVER_VERSION'] ?? null);
        $this->assertSame('2.0.0-beta.5', $assignments['DURABLE_WORKFLOW_CLI_VERSION'] ?? null);
        $this->assertSame('dw==2.0.0-beta.5', $assignments['DURABLE_WORKFLOW_CLI_PIN'] ?? null);
        $this->assertSame('2.0.0-beta.5', $assignments['DURABLE_WORKFLOW_PYTHON_SDK_VERSION'] ?? null);
        $this->assertSame('2.0.0-beta.5', $assignments['DURABLE_WORKFLOW_RUST_SDK_VERSION'] ?? null);
        $this->assertSame('2.0.0-beta.5', $assignments['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null);
        $this->assertSame(
            'durable-workflow/sdk:2.0.0-beta.5@beta',
            $assignments['DURABLE_WORKFLOW_PHP_SDK_PIN'] ?? null,
        );
        $this->assertSame('2.0.0-beta.5', $assignments['DURABLE_WORKFLOW_WORKFLOW_VERSION'] ?? null);
        $this->assertSame(
            'durable-workflow/workflow:2.0.0-beta.5@beta',
            $assignments['DURABLE_WORKFLOW_WORKFLOW_PIN'] ?? null,
        );
        $this->assertSame('2.0.0-beta.5', $assignments['DURABLE_WORKFLOW_WATERLINE_VERSION'] ?? null);
        $this->assertSame(
            'durable-workflow/waterline:2.0.0-beta.5@beta',
            $assignments['DURABLE_WORKFLOW_WATERLINE_PIN'] ?? null,
        );
    }

    public function test_polyglot_validation_uses_pinned_artifacts_for_push_and_pull_requests(): void
    {
        $workflowPath = $this->repoPath('.github/workflows/polyglot-validation.yml');
        $workflow = Yaml::parseFile($workflowPath);
        $triggers = $workflow['on'] ?? [];
        $job = $workflow['jobs']['smoke'] ?? [];
        $steps = $job['steps'] ?? [];
        $resolveSteps = array_values(array_filter(
            $steps,
            static fn (array $step): bool => ($step['name'] ?? null) === 'Resolve validation artifact tuple',
        ));

        $this->assertArrayHasKey('push', $triggers);
        $this->assertArrayHasKey('pull_request', $triggers);
        $this->assertSame('pinned', $job['env']['DURABLE_WORKFLOW_ARTIFACT_SOURCE'] ?? null);

        $this->assertCount(1, $resolveSteps);
        $resolution = (string) ($resolveSteps[0]['run'] ?? '');
        $this->assertStringContainsString('scripts/resolve-current-artifacts.sh', $resolution);
        $this->assertStringContainsString('echo "$assignment" >> "$GITHUB_ENV"', $resolution);
    }

    public function test_polyglot_smoke_metadata_covers_required_conformance_surfaces(): void
    {
        $smoke = (string) file_get_contents($this->repoPath('polyglot/python_worker/scripts/polyglot_smoke.py'));

        foreach ([
            'cli_start_result',
            'signals_queries',
            'type_matrix',
            'typed_errors',
            'waterline',
            'required_env_version',
            'polyglot.python.signal-query',
            'polyglot.php.signal-query',
            'polyglot.python-to-php.type-roundtrip',
            'polyglot.php-to-python.type-roundtrip',
            'polyglot.rust.signal-query',
            'polyglot.rust-to-python.type-roundtrip',
            'polyglot.python-to-rust.type-roundtrip',
            'polyglot.rust-to-php.type-roundtrip',
            'polyglot.php-to-rust.type-roundtrip',
            'polyglot.python-to-php.typed-error',
            'polyglot.php-to-python.typed-error',
            'REQUIRED_ARTIFACT_VERSIONS',
            '"sdk-rust": required_env_version("DURABLE_WORKFLOW_RUST_SDK_VERSION")',
            '"version_source": "rust_worker_registration"',
            '"exercised": rust_exercised',
            '"execution_evidence": "runtime_matrix" if rust_exercised else None',
            'cargo add durable-workflow@',
            '"rust_execution": True',
            '"publishedDependencies": {"officialApacheAvro": avro_packages}',
            '"artifactVersions": artifact_versions',
            '"requiredArtifactVersions": REQUIRED_ARTIFACT_VERSIONS',
            '"artifactProbe":',
            '"artifact_versions_current": True',
            '"waterline_assets_current": True',
            '"artifact_blocked"',
            'def waterline_asset_findings(',
            '"stale_assets": stale_assets',
            'PHP worker advertised standalone SDK',
            '"artifact": "durable-workflow/sdk"',
            '"role": "framework-neutral standalone client and remote worker SDK"',
            '"artifact": "durable-workflow/workflow"',
            '"role": "embedded Laravel engine and Waterline host"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $smoke);
        }

        $this->assertStringContainsString('"workflow_start_result_driver": "dw CLI"', $smoke);
        $this->assertStringContainsString('"signal_driver": "dw CLI"', $smoke);
        $this->assertStringContainsString('"query_driver": "dw CLI"', $smoke);
        $this->assertStringContainsString('"result_driver": "dw CLI"', $smoke);
        $this->assertStringContainsString('def wait_for_signal_wait_open(', $smoke);
        $this->assertStringContainsString('durable_wait = wait_for_signal_wait_open(wid, SIGNAL_NAME)', $smoke);
        $this->assertStringContainsString('"durable_wait_before_signal"', $smoke);
        $this->assertStringContainsString('"query_after_signal"', $smoke);
        $this->assertStringNotContainsString('server_php_worker_query_routing', $smoke);
        $this->assertStringNotContainsString('"blocked_surfaces": blocked_surfaces', $smoke);
        $this->assertStringContainsString('こんにちは', $smoke);
        $this->assertStringContainsString('binary_base64', $smoke);
        $this->assertStringContainsString('"exercised": False', $smoke);

        $pythonWorkflow = (string) file_get_contents($this->repoPath('polyglot/python_workflow/workflow.py'));
        $this->assertStringContainsString('POLYGLOT_SIGNAL_CONDITION_KEY = f"polyglot.signal.{POLYGLOT_SIGNAL_NAME}"', $pythonWorkflow);
        $this->assertStringContainsString('key=POLYGLOT_SIGNAL_CONDITION_KEY', $pythonWorkflow);
    }

    public function test_python_typed_error_activity_worker_refreshes_manual_registration(): void
    {
        $activities = (string) file_get_contents($this->repoPath('polyglot/python_worker/activities.py'));

        $this->assertStringContainsString('max_concurrent_activity_tasks=1', $activities);
        $this->assertStringContainsString('heartbeat_typed_error_worker', $activities);
        $this->assertStringContainsString('asyncio.create_task(', $activities);
        $this->assertStringContainsString('client.heartbeat_worker(', $activities);
        $this->assertStringContainsString('task_slots={"activity_available": 1}', $activities);
        $this->assertStringContainsString('POLYGLOT_TYPED_ERROR_HEARTBEAT_SECONDS', $activities);
    }

    public function test_polyglot_waterline_probe_reports_installed_php_artifacts(): void
    {
        $routes = (string) file_get_contents($this->repoPath('routes/web.php'));
        $smoke = (string) file_get_contents($this->repoPath('polyglot/python_worker/scripts/polyglot_smoke.py'));

        $this->assertStringContainsString("Route::get('/polyglot/conformance/artifacts'", $routes);
        $this->assertStringContainsString("app()->environment('testing')", $routes);
        $this->assertStringContainsString('InstalledVersions::getPrettyVersion($package)', $routes);
        $this->assertStringContainsString("'sdk-php'", $routes);
        $this->assertStringContainsString("'durable-workflow/sdk'", $routes);
        $this->assertStringContainsString("'durable-workflow/workflow'", $routes);
        $this->assertStringContainsString("'durable-workflow/waterline'", $routes);
        $this->assertStringContainsString("'apache-avro-php'", $routes);
        $this->assertStringContainsString("'apache/avro'", $routes);
        $this->assertStringContainsString("'assets' => [", $routes);
        $this->assertStringContainsString("'waterline' => [", $routes);
        $this->assertStringContainsString("public_path('vendor/waterline/mix-manifest.json')", $routes);
        $this->assertStringContainsString("base_path('vendor/durable-workflow/waterline/public/mix-manifest.json')", $routes);
        $this->assertStringContainsString("'current' =>", $routes);

        $this->assertStringContainsString('def fetch_php_artifact_probe()', $smoke);
        $this->assertStringContainsString('"http://waterline:8081/polyglot/conformance/artifacts"', $smoke);
        $this->assertStringContainsString('fetch_json_url(php_artifact_probe_url(), label="PHP artifact probe")', $smoke);
        $this->assertStringContainsString('?history_limit=all', $smoke);
        $this->assertStringContainsString('def php_artifact_versions(', $smoke);
        $this->assertStringContainsString('def php_waterline_assets(', $smoke);
        $this->assertStringContainsString('"sdk-php": php_sdk_worker_version', $smoke);
        $this->assertStringContainsString('"workflow": php_versions.get("workflow")', $smoke);
        $this->assertStringContainsString('"waterline": php_versions.get("waterline")', $smoke);
        $this->assertStringContainsString('"assets": php_waterline_assets(php_probe)', $smoke);
        $this->assertStringContainsString('"artifact_probe_error": php_probe_error', $smoke);
    }

    public function test_polyglot_smoke_uses_supported_dw_connection_and_input_configuration(): void
    {
        $smoke = (string) file_get_contents($this->repoPath('polyglot/python_worker/scripts/polyglot_smoke.py'));

        $this->assertStringContainsString('env["DURABLE_WORKFLOW_SERVER_URL"] = SERVER_URL', $smoke);
        $this->assertStringContainsString('env["DURABLE_WORKFLOW_NAMESPACE"] = NAMESPACE', $smoke);
        $this->assertStringContainsString('env["DURABLE_WORKFLOW_AUTH_TOKEN"] = TOKEN', $smoke);
        $this->assertStringContainsString('cmd = [DW, *args]', $smoke);
        $this->assertStringNotContainsString('"--server",', $smoke);
        $this->assertStringNotContainsString('"--namespace",', $smoke);
        $this->assertStringNotContainsString('"--token",', $smoke);

        $this->assertStringContainsString('f"--input={json_arg(input_args)}"', $smoke);
        $this->assertStringNotContainsString("\"--input\",\n        json.dumps", $smoke);
    }

    public function test_polyglot_php_signal_query_is_a_required_surface(): void
    {
        $smoke = (string) file_get_contents($this->repoPath('polyglot/python_worker/scripts/polyglot_smoke.py'));

        $this->assertStringContainsString('class DwCommandError(RuntimeError):', $smoke);
        $this->assertStringContainsString(
            '("php_signal_query", "polyglot.php.signal-query", PHP2PY_QUEUE, "php")',
            $smoke,
        );
        $this->assertStringNotContainsString(
            'def is_php_query_routing_blocker(error: DwCommandError) -> bool:',
            $smoke,
        );
        $this->assertStringNotContainsString(
            'php_query_blocked_payload',
            $smoke,
        );
        $this->assertStringNotContainsString(
            '"status": "blocked"',
            $smoke,
        );
    }

    public function test_polyglot_rust_services_execute_the_published_crate(): void
    {
        $compose = Yaml::parseFile($this->repoPath('polyglot/docker-compose.yml'));
        $services = $compose['services'] ?? [];
        $cargo = (string) file_get_contents($this->repoPath('polyglot/rust_worker/Cargo.toml'));
        $cargoLock = (string) file_get_contents($this->repoPath('polyglot/rust_worker/Cargo.lock'));
        $dockerfile = (string) file_get_contents($this->repoPath('polyglot/rust_worker/Dockerfile'));
        $worker = (string) file_get_contents($this->repoPath('polyglot/rust_worker/src/main.rs'));

        $this->assertArrayHasKey('rust-workflow-worker', $services);
        $this->assertArrayHasKey('rust-activity-worker', $services);
        $this->assertSame('workflow', $services['rust-workflow-worker']['environment']['POLYGLOT_RUST_MODE'] ?? null);
        $this->assertSame('activity', $services['rust-activity-worker']['environment']['POLYGLOT_RUST_MODE'] ?? null);
        $this->assertStringContainsString('durable-workflow = "=2.0.0-beta.5"', $cargo);
        $this->assertStringContainsString("name = \"durable-workflow\"\nversion = \"2.0.0-beta.5\"", $cargoLock);
        $this->assertStringContainsString('apache-avro = "=0.21.0"', $cargo);
        $this->assertStringContainsString('cargo update -p durable-workflow --precise', $dockerfile);
        $this->assertStringNotContainsString('path =', $cargo);
        $this->assertStringContainsString('polyglot.rust.greeter', $worker);
        $this->assertStringContainsString('polyglot.rust-to-python.greeter', $worker);
        $this->assertStringContainsString('polyglot.rust-to-php.greeter', $worker);
        $this->assertStringContainsString('polyglot.php-to-rust.echo', $worker);
        $this->assertStringContainsString('polyglot.python-to-rust.echo', $worker);
        $this->assertStringContainsString('verify_official_avro_runtime', $worker);
    }

    public function test_waterline_config_exposes_v2_engine_source_and_namespace(): void
    {
        $config = (string) file_get_contents($this->repoPath('config/waterline.php'));

        $this->assertStringContainsString("'engine_source' => env('WATERLINE_ENGINE_SOURCE', 'auto')", $config);
        $this->assertStringContainsString("'namespace' => env('WATERLINE_NAMESPACE')", $config);
        $this->assertStringContainsString("'allow_unauthenticated' => env('WATERLINE_ALLOW_UNAUTHENTICATED', false)", $config);
    }

    public function test_polyglot_waterline_provider_allows_conformance_opt_in_without_users(): void
    {
        $provider = (string) file_get_contents($this->repoPath('app/Providers/WaterlineServiceProvider.php'));

        $this->assertStringContainsString("filter_var(config('waterline.allow_unauthenticated'), FILTER_VALIDATE_BOOL)", $provider);
        $this->assertStringContainsString('Waterline::auth', $provider);
        $this->assertStringContainsString('function ($user = null)', $provider);
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function resolveArtifactAssignments(array $env = [], bool $includeFixture = true): array
    {
        $command = 'env -i PATH='.escapeshellarg((string) getenv('PATH'));
        if ($includeFixture) {
            $env = [
                'DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE' => $this->repoPath('tests/Fixtures/synthetic-artifact-tuple.json'),
                ...$env,
            ];
        }
        foreach ($env as $name => $value) {
            $command .= ' '.escapeshellarg($name.'='.$value);
        }
        $command .= ' bash '.escapeshellarg($this->repoPath('scripts/resolve-current-artifacts.sh'));

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));

        $assignments = [];
        foreach ($output as $line) {
            $parts = explode('=', $line, 2);
            $this->assertCount(2, $parts, sprintf('Expected NAME=value assignment, got %s', $line));
            $assignments[$parts[0]] = $parts[1];
        }

        return $assignments;
    }

    /**
     * @param  array<string, mixed>  $compose
     * @return array<string, mixed>
     */
    private function serverEnvironment(array $compose): array
    {
        $serverEnvironment = $compose['x-server-env'] ?? null;

        if (! is_array($serverEnvironment)) {
            $serverEnvironment = $compose['services']['server']['environment'] ?? [];
        }

        return is_array($serverEnvironment) ? $serverEnvironment : [];
    }

    private function requiredResolvedEnv(string $name): string
    {
        return sprintf('${%s:?run ../scripts/resolve-current-artifacts.sh before starting polyglot compose}', $name);
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

    private function assertLaravelAppKeySupportsAes256(mixed $key, string $label): void
    {
        $this->assertIsString($key, $label);
        $this->assertStringStartsWith('base64:', $key, $label);

        $decoded = base64_decode(substr($key, strlen('base64:')), true);

        $this->assertIsString($decoded, $label);
        $this->assertSame(
            32,
            strlen($decoded),
            sprintf('%s must decode to 32 bytes for Laravel AES-256-CBC middleware.', $label),
        );
    }
}
