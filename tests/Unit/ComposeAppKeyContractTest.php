<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class ComposeAppKeyContractTest extends TestCase
{
    private const LARAVEL_SERVICES = ['app', 'worker', 'seed'];

    private const OVERRIDE_KEY = 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    public function test_laravel_services_share_one_persistent_default_key_source(): void
    {
        $source = $this->composeSource();
        $compose = Yaml::parse($source);

        $this->assertIsArray($compose);
        $this->assertSame(1, substr_count($source, 'APP_KEY: ${APP_KEY:-'));

        $sharedKey = $compose['x-laravel-environment']['APP_KEY'] ?? null;

        $this->assertIsString($sharedKey);
        $this->assertMatchesRegularExpression(
            '/^\$\{APP_KEY:-(?<key>base64:[A-Za-z0-9+\/=]+)\}$/',
            $sharedKey,
        );

        preg_match('/^\$\{APP_KEY:-(?<key>.+)\}$/', $sharedKey, $matches);
        $this->assertLaravelAes256Key($matches['key'] ?? null);

        foreach (self::LARAVEL_SERVICES as $service) {
            $this->assertSame(
                $sharedKey,
                $compose['services'][$service]['environment']['APP_KEY'] ?? null,
                sprintf('%s must inherit the shared APP_KEY source.', $service),
            );
        }
    }

    public function test_compose_resolves_the_same_key_for_fresh_and_recreated_services(): void
    {
        $docker = (new ExecutableFinder)->find('docker');

        if ($docker === null) {
            $this->markTestSkipped('Docker Compose is required to verify the rendered service environments.');
        }

        $first = $this->resolvedKeys($docker);
        $second = $this->resolvedKeys($docker);

        $this->assertSame($first, $second, 'Resolving the stack again must not rotate APP_KEY.');
        $this->assertCount(1, array_unique($first));
        $this->assertLaravelAes256Key($first['app'] ?? null);

        $overridden = $this->resolvedKeys($docker, self::OVERRIDE_KEY);

        $this->assertSame(
            array_fill_keys(self::LARAVEL_SERVICES, self::OVERRIDE_KEY),
            $overridden,
        );
    }

    /**
     * @return array<string, string>
     */
    private function resolvedKeys(string $docker, ?string $appKey = null): array
    {
        $process = new Process(
            [
                $docker,
                'compose',
                '--env-file',
                $this->repoPath('.env.example'),
                '--profile',
                'seed',
                '--file',
                $this->repoPath('docker-compose.yml'),
                'config',
                '--format',
                'json',
            ],
            $this->repoPath(),
            ['APP_KEY' => $appKey ?? false],
        );
        $process->mustRun();

        $compose = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $keys = [];

        foreach (self::LARAVEL_SERVICES as $service) {
            $key = $compose['services'][$service]['environment']['APP_KEY'] ?? null;

            $this->assertIsString($key, sprintf('%s must receive a rendered APP_KEY.', $service));
            $this->assertNotSame('', $key, sprintf('%s must receive a non-empty APP_KEY.', $service));
            $keys[$service] = $key;
        }

        return $keys;
    }

    private function assertLaravelAes256Key(mixed $key): void
    {
        $this->assertIsString($key);
        $this->assertStringStartsWith('base64:', $key);

        $decoded = base64_decode(substr($key, strlen('base64:')), true);

        $this->assertIsString($decoded);
        $this->assertSame(32, strlen($decoded));
    }

    private function composeSource(): string
    {
        $source = file_get_contents($this->repoPath('docker-compose.yml'));

        $this->assertIsString($source);

        return $source;
    }

    private function repoPath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }
}
