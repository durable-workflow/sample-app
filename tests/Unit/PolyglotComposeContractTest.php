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

    public function test_polyglot_server_artifact_defaults_to_current_public_image(): void
    {
        $compose = Yaml::parseFile($this->repoPath('polyglot/docker-compose.yml'));
        $services = $compose['services'] ?? [];

        foreach (['bootstrap', 'server'] as $serviceName) {
            $this->assertServerImageDefaultIsCurrent($services[$serviceName]['image'] ?? null);
        }

        $this->assertServerImageDefaultIsCurrent(
            $services['smoke']['environment']['DURABLE_SERVER_IMAGE'] ?? null,
        );

        $smokeShell = (string) file_get_contents($this->repoPath('polyglot/python_worker/scripts/smoke.sh'));
        $this->assertStringContainsString('durableworkflow/server:0.2.364', $smokeShell);
        $this->assertStringNotContainsString('durableworkflow/server:0.2.128', $smokeShell);

        $smokeDriver = (string) file_get_contents($this->repoPath('polyglot/python_worker/scripts/polyglot_smoke.py'));
        $this->assertStringContainsString('durableworkflow/server:0.2.364', $smokeDriver);
        $this->assertStringNotContainsString('durableworkflow/server:0.2.128', $smokeDriver);
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
        $phpWorker = (string) file_get_contents($this->repoPath('app/Console/Commands/PolyglotWorker.php'));
        $phpEntrypoint = (string) file_get_contents($this->repoPath('docker/entrypoint.sh'));
        $smokeShell = (string) file_get_contents($this->repoPath('polyglot/python_worker/scripts/smoke.sh'));
        $composerLock = json_decode(
            (string) file_get_contents($this->repoPath('composer.lock')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $lockedPackages = array_column($composerLock['packages'] ?? [], null, 'name');

        $this->assertStringContainsString('DURABLE_WORKFLOW_CLI_VERSION=0.1.77', $dockerfile);
        $this->assertStringContainsString('ARG DURABLE_WORKFLOW_PYTHON_SDK_VERSION=0.4.85', $dockerfile);
        $this->assertStringContainsString('https://durable-workflow.com/install.sh', $dockerfile);
        $this->assertStringContainsString('VERSION="${DURABLE_WORKFLOW_CLI_VERSION}"', $dockerfile);
        $this->assertStringContainsString(
            'durable-workflow==${DURABLE_WORKFLOW_PYTHON_SDK_VERSION}',
            $dockerfile,
        );
        $this->assertStringContainsString('ARG DURABLE_WORKFLOW_PYTHON_SDK_VERSION=0.4.85', $pythonWorkflowDockerfile);
        $this->assertStringContainsString(
            'durable-workflow==${DURABLE_WORKFLOW_PYTHON_SDK_VERSION}',
            $pythonWorkflowDockerfile,
        );
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_PHP_SDK_PIN=\n", $phpDockerfile);
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_WATERLINE_PIN=\n", $phpDockerfile);
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_PHP_SDK_VERSION=2.0.0-alpha.201\n", $phpDockerfile);
        $this->assertStringContainsString("ARG DURABLE_WORKFLOW_WATERLINE_VERSION=2.0.0-alpha.84\n", $phpDockerfile);
        $this->assertStringContainsString('if [ -n "$DURABLE_WORKFLOW_PHP_SDK_PIN" ]; then', $phpDockerfile);
        $this->assertStringContainsString('if [ -n "$DURABLE_WORKFLOW_WATERLINE_PIN" ]; then', $phpDockerfile);
        $this->assertStringContainsString('if [ -n "$workflow_version" ] && [ -n "$waterline_version" ]; then', $phpDockerfile);
        $this->assertStringContainsString('elif [ -n "$workflow_version" ]; then', $phpDockerfile);
        $this->assertStringContainsString('elif [ -n "$waterline_version" ]; then', $phpDockerfile);
        $this->assertStringContainsString('composer require --no-update', $phpDockerfile);
        $this->assertStringContainsString('composer update durable-workflow/workflow durable-workflow/waterline', $phpDockerfile);
        $this->assertStringNotContainsString('2.0.0-alpha.154', $phpDockerfile);
        $this->assertStringNotContainsString('2.0.0-alpha.50', $phpDockerfile);
        $this->assertStringContainsString('RUN chmod +x docker/entrypoint.sh', $phpDockerfile);
        $this->assertStringContainsString('ENTRYPOINT ["/app/docker/entrypoint.sh"]', $phpDockerfile);
        $this->assertStringContainsString('use Composer\\InstalledVersions;', $phpWorker);
        $this->assertStringContainsString('sdkVersion: $this->phpSdkVersion()', $phpWorker);
        $this->assertStringContainsString("InstalledVersions::getPrettyVersion('durable-workflow/workflow')", $phpWorker);
        $this->assertStringContainsString('append_env_var WATERLINE_PATH', $phpEntrypoint);
        $this->assertStringContainsString('append_env_var WATERLINE_ENGINE_SOURCE', $phpEntrypoint);
        $this->assertStringContainsString('append_env_var WATERLINE_NAMESPACE', $phpEntrypoint);
        $this->assertStringContainsString('append_env_var WATERLINE_ALLOW_UNAUTHENTICATED', $phpEntrypoint);
        $this->assertStringContainsString(
            'DURABLE_WORKFLOW_CLI_PIN:=dw==${DURABLE_WORKFLOW_CLI_VERSION}',
            $smokeShell,
        );
        $this->assertStringContainsString('DURABLE_WORKFLOW_CLI_VERSION:=0.1.77', $smokeShell);
        $this->assertStringContainsString('DURABLE_WORKFLOW_PYTHON_SDK_VERSION:=0.4.85', $smokeShell);
        $this->assertStringContainsString('DURABLE_WORKFLOW_PHP_SDK_VERSION:=2.0.0-alpha.201', $smokeShell);
        $this->assertStringContainsString('DURABLE_WORKFLOW_WATERLINE_VERSION:=2.0.0-alpha.84', $smokeShell);
        $this->assertStringContainsString('DURABLE_WORKFLOW_PHP_SDK_PIN:=}', $smokeShell);
        $this->assertStringContainsString('DURABLE_WORKFLOW_WATERLINE_PIN:=}', $smokeShell);
        $this->assertStringContainsString('${DURABLE_WORKFLOW_PHP_SDK_PIN#durable-workflow/workflow:}', $smokeShell);
        $this->assertStringContainsString(
            'DURABLE_WORKFLOW_PHP_SDK_PIN="durable-workflow/workflow:${DURABLE_WORKFLOW_PHP_SDK_VERSION}"',
            $smokeShell,
        );
        $this->assertStringContainsString(
            '${DURABLE_WORKFLOW_WATERLINE_PIN#durable-workflow/waterline:}',
            $smokeShell,
        );
        $this->assertStringContainsString(
            'DURABLE_WORKFLOW_WATERLINE_PIN="durable-workflow/waterline:${DURABLE_WORKFLOW_WATERLINE_VERSION}"',
            $smokeShell,
        );
        $this->assertStringNotContainsString('DURABLE_WORKFLOW_PHP_SDK_VERSION:=2.0.0-alpha.154', $smokeShell);
        $this->assertStringNotContainsString('DURABLE_WORKFLOW_WATERLINE_VERSION:=2.0.0-alpha.50', $smokeShell);
        $this->assertIsArray($lockedPackages['durable-workflow/workflow'] ?? null);
        $this->assertSame(
            '2.0.0-alpha.201',
            $lockedPackages['durable-workflow/workflow']['version'] ?? null,
        );
        $this->assertSame(
            '12cac5d1e2f36a1d1e19e938a28925a726b5c353',
            $lockedPackages['durable-workflow/workflow']['source']['reference'] ?? null,
        );
        $this->assertIsArray($lockedPackages['durable-workflow/waterline'] ?? null);
        $this->assertSame(
            '2.0.0-alpha.84',
            $lockedPackages['durable-workflow/waterline']['version'] ?? null,
        );
        $this->assertSame(
            'a8ebadd9d0b197b488cdf7eb7f201cf24b856c70',
            $lockedPackages['durable-workflow/waterline']['source']['reference'] ?? null,
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
            '${DURABLE_WORKFLOW_PYTHON_SDK_VERSION:-0.4.85}',
            $services['smoke']['environment']['DURABLE_WORKFLOW_PYTHON_SDK_VERSION'] ?? null,
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_PHP_SDK_PIN:-}',
            $services['smoke']['environment']['DURABLE_WORKFLOW_PHP_SDK_PIN'] ?? null,
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_PHP_SDK_VERSION:-2.0.0-alpha.201}',
            $services['smoke']['environment']['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null,
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_WATERLINE_PIN:-}',
            $services['smoke']['environment']['DURABLE_WORKFLOW_WATERLINE_PIN'] ?? null,
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_WATERLINE_VERSION:-2.0.0-alpha.84}',
            $services['smoke']['environment']['DURABLE_WORKFLOW_WATERLINE_VERSION'] ?? null,
        );

        foreach ([
            'php-same-workflow-worker',
            'php-same-activity-worker',
            'php-workflow-worker',
            'php-activity-worker',
            'waterline',
        ] as $serviceName) {
            $this->assertSame(
                '${DURABLE_WORKFLOW_PHP_SDK_PIN:-}',
                $services[$serviceName]['build']['args']['DURABLE_WORKFLOW_PHP_SDK_PIN'] ?? null,
            );
            $this->assertSame(
                '${DURABLE_WORKFLOW_WATERLINE_PIN:-}',
                $services[$serviceName]['build']['args']['DURABLE_WORKFLOW_WATERLINE_PIN'] ?? null,
            );
            $this->assertSame(
                '${DURABLE_WORKFLOW_PHP_SDK_VERSION:-2.0.0-alpha.201}',
                $services[$serviceName]['build']['args']['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null,
            );
            $this->assertSame(
                '${DURABLE_WORKFLOW_WATERLINE_VERSION:-2.0.0-alpha.84}',
                $services[$serviceName]['build']['args']['DURABLE_WORKFLOW_WATERLINE_VERSION'] ?? null,
            );
        }
    }

    public function test_committed_waterline_assets_match_current_locked_package(): void
    {
        $manifest = json_decode(
            (string) file_get_contents($this->repoPath('public/vendor/waterline/mix-manifest.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame([
            '/app.js' => '/app.js?id=550e047707c9d564596b428bb30a0011',
            '/app-dark.css' => '/app-dark.css?id=a84f0f42b0d872355eb4eca96e5be831',
            '/app.css' => '/app.css?id=f87a5bb3ecda2dceae68cca620f0cd5e',
            '/img/favicon.png' => '/img/favicon.png?id=7c006241b093796d6abfa3049df93a59',
            '/img/sprite.svg' => '/img/sprite.svg?id=afc4952b74895bdef3ab4ebe9adb746f',
        ], $manifest);

        foreach ([
            'public/vendor/waterline/app.js' => '2537e882a14838617d53404579f0da0ed4c127e23bea40f895542e3dc6f6f4aa',
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
            'DURABLE_WORKFLOW_PHP_SDK_PIN' => 'durable-workflow/workflow:2.0.0-alpha.777',
            'DURABLE_WORKFLOW_WATERLINE_PIN' => 'durable-workflow/waterline:2.0.0-alpha.778',
        ]);

        $this->assertSame('ghcr.io/example/server:9.9.9', $assignments['DURABLE_SERVER_IMAGE'] ?? null);
        $this->assertSame('9.9.9', $assignments['DURABLE_SERVER_VERSION'] ?? null);
        $this->assertSame('example/cli:9.9.8', $assignments['DURABLE_WORKFLOW_CLI_PIN'] ?? null);
        $this->assertSame('9.9.8', $assignments['DURABLE_WORKFLOW_CLI_VERSION'] ?? null);
        $this->assertSame(
            'durable-workflow/workflow:2.0.0-alpha.777',
            $assignments['DURABLE_WORKFLOW_PHP_SDK_PIN'] ?? null,
        );
        $this->assertSame('2.0.0-alpha.777', $assignments['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null);
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

    public function test_polyglot_artifact_resolver_defaults_to_current_verified_tuple(): void
    {
        $assignments = $this->resolveArtifactAssignments();

        $this->assertSame('durableworkflow/server:0.2.364', $assignments['DURABLE_SERVER_IMAGE'] ?? null);
        $this->assertSame('0.2.364', $assignments['DURABLE_SERVER_VERSION'] ?? null);
        $this->assertSame('0.1.77', $assignments['DURABLE_WORKFLOW_CLI_VERSION'] ?? null);
        $this->assertSame('dw==0.1.77', $assignments['DURABLE_WORKFLOW_CLI_PIN'] ?? null);
        $this->assertSame('0.4.85', $assignments['DURABLE_WORKFLOW_PYTHON_SDK_VERSION'] ?? null);
        $this->assertSame('2.0.0-alpha.201', $assignments['DURABLE_WORKFLOW_PHP_SDK_VERSION'] ?? null);
        $this->assertSame(
            'durable-workflow/workflow:2.0.0-alpha.201',
            $assignments['DURABLE_WORKFLOW_PHP_SDK_PIN'] ?? null,
        );
        $this->assertSame('2.0.0-alpha.84', $assignments['DURABLE_WORKFLOW_WATERLINE_VERSION'] ?? null);
        $this->assertSame(
            'durable-workflow/waterline:2.0.0-alpha.84',
            $assignments['DURABLE_WORKFLOW_WATERLINE_PIN'] ?? null,
        );
    }

    public function test_polyglot_validation_exports_resolved_artifacts_before_compose(): void
    {
        $workflow = (string) file_get_contents($this->repoPath('.github/workflows/polyglot-validation.yml'));

        $this->assertStringContainsString('Resolve current artifact tuple', $workflow);
        $this->assertStringContainsString('scripts/resolve-current-artifacts.sh', $workflow);
        $this->assertStringContainsString('echo "$assignment" >> "$GITHUB_ENV"', $workflow);
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
            'latest_packagist_alpha_version',
            'polyglot.python.signal-query',
            'polyglot.php.signal-query',
            'polyglot.python-to-php.type-roundtrip',
            'polyglot.php-to-python.type-roundtrip',
            'polyglot.python-to-php.typed-error',
            'polyglot.php-to-python.typed-error',
            'REQUIRED_ARTIFACT_VERSIONS',
            '"artifactVersions": artifact_versions',
            '"requiredArtifactVersions": REQUIRED_ARTIFACT_VERSIONS',
            '"artifactProbe":',
            '"artifact_versions_current": True',
            '"waterline_assets_current": True',
            '"artifact_blocked"',
            'def waterline_asset_findings(',
            '"stale_assets": stale_assets',
            'PHP worker advertised workflow SDK',
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
        $this->assertStringContainsString("'durable-workflow/workflow'", $routes);
        $this->assertStringContainsString("'durable-workflow/waterline'", $routes);
        $this->assertStringContainsString("'assets' => [", $routes);
        $this->assertStringContainsString("'waterline' => [", $routes);
        $this->assertStringContainsString("public_path('vendor/waterline/mix-manifest.json')", $routes);
        $this->assertStringContainsString("base_path('vendor/durable-workflow/waterline/public/mix-manifest.json')", $routes);
        $this->assertStringContainsString("'current' =>", $routes);

        $this->assertStringContainsString('def fetch_php_artifact_probe()', $smoke);
        $this->assertStringContainsString('"http://waterline:8081/polyglot/conformance/artifacts"', $smoke);
        $this->assertStringContainsString('fetch_json_url(php_artifact_probe_url(), label="PHP artifact probe")', $smoke);
        $this->assertStringContainsString('def php_artifact_versions(', $smoke);
        $this->assertStringContainsString('def php_waterline_assets(', $smoke);
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
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private function resolveArtifactAssignments(array $env = []): array
    {
        $command = 'env -i PATH='.escapeshellarg((string) getenv('PATH'));
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
     * @param array<string, mixed> $compose
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

    private function assertServerImageDefaultIsCurrent(mixed $image): void
    {
        $this->assertIsString($image);
        $this->assertMatchesRegularExpression(
            '/^\$\{DURABLE_SERVER_IMAGE:-durableworkflow\/server:(?<version>[0-9]+\.[0-9]+\.[0-9]+)\}$/',
            $image,
        );

        preg_match('/durableworkflow\/server:(?<version>[0-9]+\.[0-9]+\.[0-9]+)/', $image, $matches);

        $this->assertArrayHasKey('version', $matches);
        $this->assertTrue(
            version_compare($matches['version'], '0.2.364', '>='),
            sprintf('Expected durableworkflow/server default >= 0.2.364, got %s.', $image),
        );
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
