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

    public function test_entrypoint_resolves_current_artifacts_and_keeps_builds_disabled(): void
    {
        $script = (string) file_get_contents($this->repoPath('scripts/service-mode.sh'));

        $this->assertStringContainsString('scripts/resolve-current-artifacts.sh', $script);
        $this->assertStringContainsString('--no-build', $script);
        $this->assertStringNotContainsString('docker compose build', $script);
        $this->assertStringContainsString('sample-app-service-mode', $script);
        $this->assertStringContainsString('service-mode-evidence.json', $script);
        $this->assertStringContainsString('browser-smoke screenshot', $script);
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
