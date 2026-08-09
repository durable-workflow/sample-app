<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RedisExtensionBuildContractTest extends TestCase
{
    private const REDIS_DOCKERFILES = [
        '.devcontainer/docker/Dockerfile',
        'Dockerfile',
        'polyglot/laravel/Dockerfile',
    ];

    private const INSTALLER_SOURCE = 'docker/install-phpredis.sh';

    private const INSTALLER_TARGET = '/usr/local/bin/install-phpredis';

    public function test_dockerfiles_never_install_redis_through_the_live_pecl_channel(): void
    {
        foreach ($this->dockerfiles() as $dockerfile) {
            $source = $this->fileContents($dockerfile);

            $this->assertDoesNotMatchRegularExpression(
                '/^[^\r\n]*\bpecl\s+install\b[^\r\n]*\bredis\b/im',
                $source,
                "{$dockerfile} must not install Redis through PECL.",
            );
        }
    }

    public function test_every_redis_image_uses_the_shared_pinned_installer(): void
    {
        $installerIdentities = [];

        foreach (self::REDIS_DOCKERFILES as $dockerfile) {
            $source = $this->fileContents($dockerfile);

            $this->assertStringContainsString(
                'COPY '.self::INSTALLER_SOURCE.' '.self::INSTALLER_TARGET,
                $source,
                "{$dockerfile} must copy the shared phpredis installer.",
            );
            $this->assertStringContainsString(
                'sh '.self::INSTALLER_TARGET,
                $source,
                "{$dockerfile} must run the shared phpredis installer.",
            );

            $installerIdentities[] = hash_file(
                'sha256',
                $this->repoPath(self::INSTALLER_SOURCE),
            );
        }

        $this->assertCount(1, array_unique($installerIdentities));
    }

    public function test_installer_verifies_one_immutable_source_with_bounded_retries(): void
    {
        $installer = $this->fileContents(self::INSTALLER_SOURCE);

        $this->assertMatchesRegularExpression(
            "/^readonly PHPREDIS_VERSION='[0-9]+\.[0-9]+\.[0-9]+'$/m",
            $installer,
        );
        $this->assertMatchesRegularExpression(
            "/^readonly PHPREDIS_COMMIT='[0-9a-f]{40}'$/m",
            $installer,
        );
        $this->assertMatchesRegularExpression(
            "/^readonly PHPREDIS_SHA256='[0-9a-f]{64}'$/m",
            $installer,
        );
        $this->assertStringContainsString(
            'https://codeload.github.com/phpredis/phpredis/tar.gz/${PHPREDIS_COMMIT}',
            $installer,
        );

        foreach ([
            '--connect-timeout 10',
            '--max-time 120',
            '--retry 5',
            '--retry-all-errors',
            '--retry-delay 2',
            '--retry-max-time 180',
            '--remove-on-error',
        ] as $curlOption) {
            $this->assertStringContainsString($curlOption, $installer);
        }

        $checksumPosition = strpos($installer, 'sha256sum --check --strict');
        $extractPosition = strpos($installer, "\ntar \\");

        $this->assertIsInt($checksumPosition);
        $this->assertIsInt($extractPosition);
        $this->assertLessThan($extractPosition, $checksumPosition);
        $this->assertStringContainsString('docker-php-ext-install redis', $installer);
    }

    /**
     * @return list<string>
     */
    private function dockerfiles(): array
    {
        $dockerfiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->repoPath(),
                FilesystemIterator::SKIP_DOTS,
            ),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'Dockerfile') {
                $dockerfiles[] = ltrim(
                    str_replace($this->repoPath(), '', $file->getPathname()),
                    '/',
                );
            }
        }

        sort($dockerfiles);

        return $dockerfiles;
    }

    private function fileContents(string $path): string
    {
        $contents = file_get_contents($this->repoPath($path));

        $this->assertIsString($contents);

        return $contents;
    }

    private function repoPath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }
}
