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
        $this->assertStringContainsString('durableworkflow/server:0.2.112', $smokeShell);
        $this->assertStringNotContainsString('durableworkflow/server:0.2.111', $smokeShell);

        $smokeDriver = (string) file_get_contents($this->repoPath('polyglot/python_worker/scripts/polyglot_smoke.py'));
        $this->assertStringContainsString('durableworkflow/server:0.2.112', $smokeDriver);
        $this->assertStringNotContainsString('durableworkflow/server:0.2.111', $smokeDriver);
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

        $this->assertContains(
            '--poll-timeout=5',
            $services['php-workflow-worker']['command'] ?? [],
        );
        $this->assertContains(
            '--poll-timeout=5',
            $services['php-activity-worker']['command'] ?? [],
        );

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

    public function test_polyglot_validation_rebuilds_baked_smoke_driver(): void
    {
        $workflow = Yaml::parseFile($this->repoPath('.github/workflows/polyglot-validation.yml'));
        $steps = $workflow['jobs']['smoke']['steps'] ?? [];
        $smokeSteps = array_values(array_filter(
            $steps,
            static fn (array $step): bool => ($step['name'] ?? null) === 'Run polyglot smoke',
        ));

        $this->assertNotEmpty($smokeSteps);
        $this->assertSame(
            'docker compose run --rm --build smoke',
            trim((string) ($smokeSteps[0]['run'] ?? '')),
        );
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
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
            version_compare($matches['version'], '0.2.112', '>='),
            sprintf('Expected durableworkflow/server default >= 0.2.112, got %s.', $image),
        );
    }
}
