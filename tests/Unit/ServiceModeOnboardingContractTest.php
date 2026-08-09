<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Workflows\ServiceMode\WelcomeWorkflow;
use PHPUnit\Framework\TestCase;
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
        foreach (['worker-app-setup', 'observer-app-setup', 'php-worker', 'waterline', 'journey', 'browser-smoke'] as $serviceName) {
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
        $this->assertSame('service', $services['waterline']['environment']['WATERLINE_BACKEND'] ?? null);
        $this->assertSame(
            'http://server:8080',
            $services['waterline']['environment']['WATERLINE_SERVER_ENDPOINT'] ?? null,
        );
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
        $this->assertStringContainsString('browser-smoke screenshot', $script);
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

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
