<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DevcontainerImageContractTest extends TestCase
{
    public function test_codespaces_uses_the_published_image_and_persistent_service_state(): void
    {
        $config = json_decode(
            (string) file_get_contents($this->path('.devcontainer/devcontainer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $compose = Yaml::parseFile($this->path('.devcontainer/docker/docker-compose.yml'));
        $services = $compose['services'];

        $this->assertSame(['docker/docker-compose.yml'], $config['dockerComposeFile']);
        $this->assertSame('laravel', $config['service']);
        $this->assertSame('/var/www/html', $config['workspaceFolder']);
        $this->assertArrayNotHasKey('build', $services['laravel']);
        $this->assertSame(
            '${SAMPLE_APP_DEVCONTAINER_IMAGE:-ghcr.io/durable-workflow/sample-app-devcontainer:main}',
            $services['laravel']['image'],
        );
        $this->assertSame($services['laravel']['image'], $services['microservice']['image']);
        $this->assertArrayHasKey('laravel-mysql', $compose['volumes']);
        $this->assertArrayHasKey('laravel-redis', $compose['volumes']);
        $this->assertArrayHasKey('laravel-vendor', $compose['volumes']);
        $this->assertArrayHasKey('microservice-vendor', $compose['volumes']);
    }

    public function test_prepared_image_contains_the_advertised_development_toolchain(): void
    {
        $dockerfile = (string) file_get_contents($this->path('.devcontainer/docker/Dockerfile'));
        $postCreate = (string) file_get_contents($this->path('.devcontainer/post-create.sh'));

        foreach (['php', 'composer', 'python3', 'rustc', 'cargo', 'node', 'npm', 'docker', 'rg'] as $tool) {
            $this->assertStringContainsString($tool, $dockerfile);
        }

        $this->assertStringContainsString('ARG DURABLE_WORKFLOW_CLI_VERSION=2.0.0', $dockerfile);
        $this->assertStringContainsString('verify-devcontainer-image', $dockerfile);
        $this->assertStringContainsString('scripts/playground doctor', $postCreate);
        $this->assertStringNotContainsString('apt-get', $postCreate);
        $this->assertStringNotContainsString('rustup', $postCreate);
    }

    public function test_qualification_runs_the_real_codespaces_startup_once(): void
    {
        $script = (string) file_get_contents($this->path('scripts/ci/qualify-devcontainer-image.sh'));

        $this->assertStringContainsString('DEVCONTAINER_MAX_STARTUP_SECONDS:-600', $script);
        $this->assertStringContainsString('up --detach --no-build laravel microservice', $script);
        $this->assertStringContainsString('run_in_ready_devcontainer laravel .devcontainer/post-create.sh', $script);
        $this->assertStringContainsString('scripts/playground doctor', $script);
        $this->assertStringContainsString('node docker/playwright-smoke.js', $script);
        $this->assertStringContainsString('command -v "$command"', $script);
        $this->assertStringContainsString('cargo check --bins --locked --offline', $script);
        $this->assertStringContainsString('down --volumes --remove-orphans', $script);
        $this->assertStringNotContainsString('upload-artifact', $script);
        $this->assertStringNotContainsString('evidence_type', $script);
    }

    public function test_pull_request_build_cannot_publish_or_use_registry_credentials(): void
    {
        $workflow = (string) file_get_contents($this->path('.github/workflows/devcontainer-image-pr.yml'));

        $this->assertStringContainsString("pull_request:\n    branches: [main]", $workflow);
        $this->assertStringContainsString('no-cache: true', $workflow);
        $this->assertStringContainsString('push: false', $workflow);
        $this->assertStringContainsString('qualify-devcontainer-image.sh', $workflow);
        $this->assertStringNotContainsString('docker/login-action', $workflow);
        $this->assertStringNotContainsString('secrets.', $workflow);
        $this->assertStringNotContainsString('packages: write', $workflow);
    }

    public function test_main_publication_builds_both_architectures_before_promotion(): void
    {
        $workflow = Yaml::parseFile($this->path('.github/workflows/devcontainer-image.yml'));
        $jobs = $workflow['jobs'];

        $this->assertSame(['build'], $jobs['assemble']['needs']);
        $this->assertSame(['assemble'], $jobs['qualify']['needs']);
        $this->assertSame(['assemble', 'qualify'], $jobs['promote']['needs']);

        $matrix = $jobs['build']['strategy']['matrix']['include'];
        $this->assertSame(['linux/amd64', 'linux/arm64'], array_column($matrix, 'platform'));
        $this->assertSame(['ubuntu-24.04', 'ubuntu-24.04-arm'], array_column($matrix, 'runner'));

        $source = (string) file_get_contents($this->path('.github/workflows/devcontainer-image.yml'));
        $this->assertStringContainsString('ghcr.io/durable-workflow/sample-app-devcontainer', $source);
        $this->assertStringContainsString('durableworkflow/sample-app-devcontainer', $source);
        $this->assertStringContainsString('provenance: mode=max', $source);
        $this->assertStringContainsString('sbom: true', $source);
        $this->assertStringContainsString('DEVCONTAINER_REQUIRE_ANONYMOUS_PULL', $source);
        $this->assertStringContainsString('Publish main channel', $source);
        $this->assertStringNotContainsString('upload-artifact', $source);
        $this->assertStringNotContainsString('recover_revision_tag', $source);
    }

    private function path(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
