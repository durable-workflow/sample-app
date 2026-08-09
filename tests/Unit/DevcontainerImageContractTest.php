<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DevcontainerImageContractTest extends TestCase
{
    public function test_codespaces_consumes_the_public_image_without_a_build_fallback(): void
    {
        $compose = Yaml::parseFile($this->repoPath('.devcontainer/docker/docker-compose.yml'));
        $devcontainer = json_decode(
            $this->contents('.devcontainer/devcontainer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $services = $compose['services'] ?? [];
        $expectedImage = '${SAMPLE_APP_DEVCONTAINER_IMAGE:-ghcr.io/durable-workflow/sample-app-devcontainer:main}';

        $this->assertSame('laravel', $devcontainer['service'] ?? null);
        $this->assertSame('laravel', $devcontainer['remoteUser'] ?? null);
        $this->assertSame(
            'npm ci --no-audit --no-fund && node docker/playwright-smoke.js',
            $devcontainer['postCreateCommand'] ?? null,
        );

        foreach (['laravel', 'microservice'] as $serviceName) {
            $this->assertSame($expectedImage, $services[$serviceName]['image'] ?? null);
            $this->assertSame(
                '${SAMPLE_APP_DEVCONTAINER_PULL_POLICY:-always}',
                $services[$serviceName]['pull_policy'] ?? null,
            );
            $this->assertArrayNotHasKey('build', $services[$serviceName] ?? []);
        }

        $this->assertSame('mysql:8.0', $services['mysql']['image'] ?? null);
        $this->assertSame('redis:alpine', $services['redis']['image'] ?? null);
    }

    public function test_image_bakes_and_verifies_the_supported_toolchain(): void
    {
        $dockerfile = $this->contents('.devcontainer/docker/Dockerfile');
        $supervisor = $this->contents('.devcontainer/docker/supervisord.conf');
        $verification = $this->contents('.devcontainer/docker/verify-image.sh');
        $initCommand = $this->contents('app/Console/Commands/Init.php');

        $this->assertStringContainsString('FROM php:8.4-cli-bookworm', $dockerfile);
        $this->assertStringContainsString('FROM node:22-bookworm-slim', $dockerfile);
        $this->assertStringContainsString('FROM composer:2', $dockerfile);
        $this->assertStringContainsString('COPY package-lock.json /tmp/sample-app-package-lock.json', $dockerfile);
        $this->assertStringContainsString('lock.packages["node_modules/playwright"].version', $dockerfile);
        $this->assertStringContainsString('playwright install --with-deps chromium', $dockerfile);
        $this->assertStringContainsString('default-mysql-client', $dockerfile);
        $this->assertStringContainsString('redis-tools', $dockerfile);
        $this->assertStringContainsString('ffmpeg', $dockerfile);
        $this->assertStringContainsString('libcap2-bin', $dockerfile);
        $this->assertStringContainsString('openssh-server', $dockerfile);
        $this->assertStringContainsString("setcap 'cap_net_bind_service=+ep'", $dockerfile);
        $this->assertStringContainsString('getcap "$(command -v php)"', $dockerfile);
        $this->assertStringNotContainsString('setcap_path=', $dockerfile);
        $this->assertStringContainsString('sshd -t', $dockerfile);
        $this->assertStringContainsString("'PasswordAuthentication no'", $dockerfile);
        $this->assertStringContainsString("'PubkeyAuthentication yes'", $dockerfile);
        $this->assertStringContainsString('passwd --delete laravel', $dockerfile);
        $this->assertStringContainsString('rm -f /etc/ssh/ssh_host_*_key', $dockerfile);
        $this->assertStringContainsString(
            'DEVCONTAINER_SSH_HOST_KEY_STATE=absent gosu laravel verify-devcontainer-image',
            $dockerfile,
        );
        $this->assertStringContainsString('org.opencontainers.image.revision="${VCS_REF}"', $dockerfile);
        $this->assertStringContainsString('gosu laravel verify-devcontainer-image', $dockerfile);
        $this->assertStringNotContainsString('ppa.launchpadcontent.net', $dockerfile);
        $this->assertStringNotContainsString('deb.nodesource.com', $dockerfile);
        $this->assertStringContainsString('command=/usr/local/bin/php ', $supervisor);
        $this->assertStringContainsString('command=/usr/sbin/sshd -D -e', $supervisor);

        foreach (['pdo_mysql', 'pdo_sqlite', 'redis', 'pcntl', 'bcmath', 'gd', 'intl', 'mbstring', 'zip'] as $extension) {
            $this->assertStringContainsString($extension, $verification);
        }

        foreach (['composer', 'curl', 'ffmpeg', 'git', 'mysql', 'node', 'playwright', 'redis-cli', 'ssh', 'sshd'] as $executable) {
            $this->assertStringContainsString($executable, $verification);
        }
        $this->assertStringContainsString("compgen -G '/etc/ssh/ssh_host_*_key'", $verification);
        $this->assertStringContainsString('must not contain shared SSH host private keys', $verification);

        $this->assertStringContainsString("Process::run('npm ci --no-audit --no-fund')->throw()", $initCommand);
        $this->assertStringContainsString("Process::run('node docker/playwright-smoke.js')->throw()", $initCommand);
        $this->assertStringNotContainsString('npx playwright install', $initCommand);
    }

    public function test_publication_separates_untrusted_builds_from_protected_registry_jobs(): void
    {
        $workflow = $this->contents('.github/workflows/devcontainer-image.yml');
        $validate = $this->jobBlock($workflow, 'validate');
        $publish = $this->jobBlock($workflow, 'publish');
        $qualification = $this->jobBlock($workflow, 'qualify-published');
        $promotion = $this->jobBlock($workflow, 'promote-main');
        $movingChannel = $this->jobBlock($workflow, 'verify-main');

        $this->assertStringNotContainsString('pull_request_target', $workflow);
        $this->assertStringContainsString('contents: read', $validate);
        $this->assertStringNotContainsString('packages: write', $validate);
        $this->assertStringNotContainsString('secrets.', $validate);
        $this->assertStringNotContainsString('docker/login-action', $validate);
        $this->assertStringNotContainsString('cache-from', $validate);
        $this->assertStringNotContainsString('cache-to', $validate);
        $this->assertStringContainsString('no-cache: true', $validate);
        $this->assertStringContainsString('push: false', $validate);

        $this->assertStringContainsString("github.repository == 'durable-workflow/sample-app'", $publish);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $publish);
        $this->assertStringContainsString('packages: write', $publish);
        $this->assertStringContainsString('secrets.DOCKERHUB_TOKEN', $publish);
        $this->assertStringContainsString('platforms: linux/amd64,linux/arm64', $publish);
        $this->assertStringContainsString('provenance: mode=max', $publish);
        $this->assertStringContainsString('sbom: true', $publish);
        $this->assertStringContainsString('${{ env.GHCR_IMAGE }}:${{ env.REVISION_TAG }}', $publish);
        $this->assertStringContainsString('${{ env.DOCKERHUB_IMAGE }}:${{ env.REVISION_TAG }}', $publish);
        $this->assertStringContainsString('no-cache: true', $publish);
        $this->assertStringNotContainsString('cache-from', $publish);
        $this->assertStringNotContainsString('cache-to', $publish);

        $this->assertStringContainsString('needs: [publish]', $qualification);
        $this->assertStringContainsString('linux/amd64', $qualification);
        $this->assertStringContainsString('linux/arm64', $qualification);
        $this->assertStringNotContainsString('secrets.', $qualification);
        $this->assertStringNotContainsString('docker/login-action', $qualification);
        $this->assertStringContainsString('needs: [publish, qualify-published]', $promotion);
        $this->assertStringContainsString('needs: [promote-main]', $movingChannel);

        preg_match_all('/^\s*uses:\s+[^@\s]+@([^\s#]+)/m', $workflow, $actionRefs);
        $this->assertNotEmpty($actionRefs[1]);
        foreach ($actionRefs[1] as $ref) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $ref);
        }
    }

    public function test_qualification_never_builds_and_records_fresh_and_warm_phases(): void
    {
        $script = $this->contents('scripts/ci/qualify-devcontainer-image.sh');
        $entrypoint = $this->contents('.devcontainer/docker/start-container');

        $this->assertStringNotContainsString('docker compose build', $script);
        $this->assertStringContainsString('up --detach --no-build --wait', $script);
        $this->assertStringContainsString('up --detach --no-build --force-recreate --wait', $script);
        $this->assertStringContainsString('environment_builds', $script);
        $this->assertStringContainsString('exec -T laravel sshd -t', $script);
        $this->assertStringContainsString('/dev/tcp/127.0.0.1/22', $script);
        $this->assertStringContainsString('laravel@127.0.0.1 id -u', $script);
        $this->assertStringContainsString('-o BatchMode=yes', $script);
        $this->assertStringContainsString("if ! gosu laravel bash -c '", $entrypoint);
        $this->assertStringContainsString("ssh-keygen -A\n    sshd -t", $entrypoint);
        $this->assertStringContainsString('chown -R laravel:laravel /var/www/html', $entrypoint);

        foreach ([
            'image_pull',
            'container_readiness',
            'dependency_bootstrap',
            'application_readiness',
            'fresh_total_ms',
            'warm_rebuild_ms',
        ] as $timingKey) {
            $this->assertStringContainsString($timingKey, $script);
        }
    }

    private function jobBlock(string $workflow, string $job): string
    {
        $lines = preg_split('/\R/', $workflow);
        $this->assertIsArray($lines);
        $marker = "  {$job}:";
        $start = array_search($marker, $lines, true);
        $this->assertIsInt($start, "Workflow is missing job {$job}.");
        $end = count($lines);

        for ($index = $start + 1; $index < count($lines); $index++) {
            if (preg_match('/^  [a-zA-Z0-9_-]+:$/', $lines[$index]) === 1) {
                $end = $index;
                break;
            }
        }

        return implode("\n", array_slice($lines, $start, $end - $start));
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($this->repoPath($path));
        $this->assertIsString($contents);

        return $contents;
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
