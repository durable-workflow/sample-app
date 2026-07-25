<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class ScreenshotGeneratorContractTest extends TestCase
{
    public function test_host_options_use_reachable_defaults_and_preserve_explicit_overrides(): void
    {
        $this->assertSame(
            [
                'baseUrl' => 'http://localhost:8000',
                'outputDir' => './screenshots',
            ],
            $this->resolveOptions(['node', 'docker/screenshots.js']),
        );

        $this->assertSame(
            [
                'baseUrl' => 'http://environment.test:9000',
                'outputDir' => '/tmp/environment-screenshots',
            ],
            $this->resolveOptions(
                ['node', 'docker/screenshots.js'],
                [
                    'APP_URL' => 'http://environment.test:9000',
                    'OUTPUT_DIR' => '/tmp/environment-screenshots',
                ],
            ),
        );

        $this->assertSame(
            [
                'baseUrl' => 'http://explicit.test:7000',
                'outputDir' => '/tmp/explicit-screenshots',
            ],
            $this->resolveOptions(
                [
                    'node',
                    'docker/screenshots.js',
                    'http://explicit.test:7000',
                    '/tmp/explicit-screenshots',
                ],
                [
                    'APP_URL' => 'http://environment.test:9000',
                    'OUTPUT_DIR' => '/tmp/environment-screenshots',
                ],
            ),
        );
    }

    public function test_documented_compose_entrypoint_uses_the_browser_safe_app_alias(): void
    {
        $script = (string) file_get_contents($this->repoPath('docker/screenshots.js'));
        $compose = Yaml::parseFile($this->repoPath('docker-compose.yml'));
        $service = $compose['services']['screenshots'] ?? null;

        $this->assertIsArray($service);
        $this->assertStringContainsString(
            'npx playwright install --with-deps chromium',
            $script,
        );
        $this->assertStringContainsString(
            'node docker/screenshots.js [base_url] [output_dir]',
            $script,
        );
        $this->assertStringContainsString('docker compose run --rm screenshots', $script);
        $this->assertSame(
            ['node', 'docker/screenshots.js'],
            $service['entrypoint'] ?? null,
        );
        $this->assertSame([], $service['command'] ?? null);
        $this->assertSame(
            'service_healthy',
            $service['depends_on']['app']['condition'] ?? null,
        );
        $this->assertContains('screenshots', $service['profiles'] ?? []);
        $this->assertContains('./screenshots:/app/screenshots', $service['volumes'] ?? []);
        $this->assertSame('/app/screenshots', $service['environment']['OUTPUT_DIR'] ?? null);

        $composeBaseUrl = $service['environment']['APP_URL'] ?? null;

        $this->assertIsString($composeBaseUrl);
        $this->assertSame('http', parse_url($composeBaseUrl, PHP_URL_SCHEME));

        $composeHost = parse_url($composeBaseUrl, PHP_URL_HOST);
        $appAliases = $compose['services']['app']['networks']['default']['aliases'] ?? [];

        $this->assertSame('sample-app', $composeHost);
        $this->assertContains($composeHost, $appAliases);
        $this->assertDoesNotMatchRegularExpression('/\.app$/', $composeHost);
    }

    /**
     * @param  list<string>  $argv
     * @param  array<string, string>  $env
     * @return array{baseUrl: string, outputDir: string}
     */
    private function resolveOptions(array $argv, array $env = []): array
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node.js is required to exercise screenshot option resolution.');
        }

        $input = json_encode([$argv, $env], JSON_THROW_ON_ERROR);
        $program = <<<'JS'
import { resolveScreenshotOptions } from './docker/screenshot-options.js';

const [argv, env] = JSON.parse(process.argv[1]);
process.stdout.write(JSON.stringify(resolveScreenshotOptions(argv, env)));
JS;

        $process = new Process(
            [$node, '--input-type=module', '--eval', $program, $input],
            $this->repoPath(),
        );
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function repoPath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }
}
