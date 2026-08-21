<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\Init;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
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
            '.devcontainer/post-create.sh',
            $devcontainer['postCreateCommand'] ?? null,
        );

        foreach (['laravel', 'microservice'] as $serviceName) {
            $this->assertSame($expectedImage, $services[$serviceName]['image'] ?? null);
            $this->assertSame(
                '${SAMPLE_APP_DEVCONTAINER_PULL_POLICY:-always}',
                $services[$serviceName]['pull_policy'] ?? null,
            );
            $this->assertArrayNotHasKey('build', $services[$serviceName] ?? []);
            $this->assertSame(
                '${SAMPLE_APP_UID:-}',
                $services[$serviceName]['environment']['SAMPLE_APP_UID'] ?? null,
            );
            $this->assertSame(
                [
                    'mysql' => ['condition' => 'service_healthy'],
                    'redis' => ['condition' => 'service_healthy'],
                ],
                $services[$serviceName]['depends_on'] ?? null,
            );
        }

        foreach (['DB_DATABASE', 'SHARED_DB_DATABASE'] as $key) {
            $this->assertSame('${DB_DATABASE:-sample}', $services['laravel']['environment'][$key] ?? null);
        }
        foreach (['DB_USERNAME', 'SHARED_DB_USERNAME'] as $key) {
            $this->assertSame('${DB_USERNAME:-laravel}', $services['laravel']['environment'][$key] ?? null);
        }
        foreach (['DB_PASSWORD', 'SHARED_DB_PASSWORD'] as $key) {
            $this->assertSame('${DB_PASSWORD:-password}', $services['laravel']['environment'][$key] ?? null);
        }
        $this->assertSame('mysql', $services['laravel']['environment']['DB_HOST'] ?? null);
        $this->assertSame('mysql', $services['laravel']['environment']['SHARED_DB_HOST'] ?? null);
        $this->assertSame('mysql', $services['microservice']['environment']['SHARED_DB_HOST'] ?? null);
        $this->assertSame(
            '${DB_DATABASE:-sample}',
            $services['microservice']['environment']['SHARED_DB_DATABASE'] ?? null,
        );
        $this->assertSame(
            '${DB_USERNAME:-laravel}',
            $services['microservice']['environment']['SHARED_DB_USERNAME'] ?? null,
        );
        $this->assertSame(
            '${DB_PASSWORD:-password}',
            $services['microservice']['environment']['SHARED_DB_PASSWORD'] ?? null,
        );

        $this->assertSame('../../:/var/www/html', $services['laravel']['volumes'][0] ?? null);
        $this->assertSame('laravel-vendor:/var/www/html/vendor', $services['laravel']['volumes'][1] ?? null);
        $this->assertSame('/var/run/docker.sock:/var/run/docker.sock', $services['laravel']['volumes'][2] ?? null);
        $this->assertSame('/var/www/html/microservice', $services['microservice']['working_dir'] ?? null);
        $this->assertSame('../../:/var/www/html', $services['microservice']['volumes'][0] ?? null);
        $this->assertSame(
            'microservice-vendor:/var/www/html/microservice/vendor',
            $services['microservice']['volumes'][1] ?? null,
        );
        $this->assertSame('local', $compose['volumes']['laravel-vendor']['driver'] ?? null);
        $this->assertSame('local', $compose['volumes']['microservice-vendor']['driver'] ?? null);

        $this->assertSame($expectedImage, $services['mysql-seed']['image'] ?? null);
        $this->assertSame(
            '${SAMPLE_APP_DEVCONTAINER_PULL_POLICY:-always}',
            $services['mysql-seed']['pull_policy'] ?? null,
        );
        $this->assertSame('root', $services['mysql-seed']['user'] ?? null);
        $this->assertSame(
            ['/usr/local/bin/seed-mysql-volume'],
            $services['mysql-seed']['entrypoint'] ?? null,
        );
        $this->assertSame(
            [
                'MYSQL_DATABASE' => '${DB_DATABASE:-sample}',
                'MYSQL_USER' => '${DB_USERNAME:-laravel}',
                'MYSQL_PASSWORD' => '${DB_PASSWORD:-password}',
            ],
            $services['mysql-seed']['environment'] ?? null,
        );
        $this->assertSame(
            [
                'type' => 'volume',
                'source' => 'laravel-mysql',
                'target' => '/var/lib/mysql',
                'volume' => ['nocopy' => true],
            ],
            $services['mysql-seed']['volumes'][0] ?? null,
        );
        $this->assertSame('no', $services['mysql-seed']['restart'] ?? null);

        $this->assertSame('mariadb:11.4', $services['mysql']['image'] ?? null);
        $this->assertSame(
            [
                '--innodb-flush-method=nosync',
                '--innodb-flush-log-at-trx-commit=0',
                '--innodb-doublewrite=OFF',
                '--innodb-file-per-table=OFF',
                '--innodb-buffer-pool-size=64M',
                '--innodb-log-file-size=16M',
                '--performance-schema=OFF',
                '--skip-name-resolve',
            ],
            $services['mysql']['command'] ?? null,
        );
        $this->assertSame(
            [
                'type' => 'volume',
                'source' => 'laravel-mysql',
                'target' => '/var/lib/mysql',
                'volume' => ['nocopy' => true],
            ],
            $services['mysql']['volumes'][0] ?? null,
        );
        $this->assertSame(
            '../schema/mysql-schema.sql:/docker-entrypoint-initdb.d/20-sample-app-schema.sql:ro',
            $services['mysql']['volumes'][2] ?? null,
        );
        $this->assertSame(
            './mysql-healthcheck.sh:/usr/local/bin/check-codespaces-mysql-health:ro',
            $services['mysql']['volumes'][3] ?? null,
        );
        $this->assertSame(
            ['mysql-seed' => ['condition' => 'service_completed_successfully']],
            $services['mysql']['depends_on'] ?? null,
        );
        $this->assertSame('redis:alpine', $services['redis']['image'] ?? null);
        $this->assertSame(
            [
                'CMD',
                'bash',
                '/usr/local/bin/check-codespaces-mysql-health',
            ],
            $services['mysql']['healthcheck']['test'] ?? null,
        );
        $this->assertSame('2s', $services['mysql']['healthcheck']['interval'] ?? null);
        $this->assertSame(75, $services['mysql']['healthcheck']['retries'] ?? null);
        $this->assertSame('30s', $services['mysql']['healthcheck']['start_period'] ?? null);
        $this->assertSame(30, $services['redis']['healthcheck']['retries'] ?? null);
        $this->assertSame('5s', $services['redis']['healthcheck']['start_period'] ?? null);
    }

    public function test_image_bakes_and_verifies_the_supported_toolchain(): void
    {
        $dockerfile = $this->contents('.devcontainer/docker/Dockerfile');
        $qualifiedArtifacts = json_decode(
            $this->contents('polyglot/qualified-artifact-tuple.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['artifacts'];
        $supervisor = $this->contents('.devcontainer/docker/supervisord.conf');
        $verification = $this->contents('.devcontainer/docker/verify-image.sh');
        $databaseInitialization = $this->contents('.devcontainer/docker/create-testing-database.sh');
        $databaseHealthcheck = $this->contents('.devcontainer/docker/mysql-healthcheck.sh');
        $databaseSeed = $this->contents('.devcontainer/docker/seed-mysql-volume');
        $initCommand = $this->contents('app/Console/Commands/Init.php');
        $postCreate = $this->contents('.devcontainer/post-create.sh');

        $this->assertStringContainsString('FROM php:8.4-cli-bookworm', $dockerfile);
        $this->assertStringContainsString('FROM node:22-bookworm-slim', $dockerfile);
        $this->assertStringContainsString('FROM composer:2', $dockerfile);
        $this->assertStringContainsString('FROM docker:27.5.1-cli AS docker-cli', $dockerfile);
        $this->assertStringContainsString('FROM rust:1.86.0-slim-bookworm AS rust', $dockerfile);
        $this->assertStringContainsString('FROM mariadb:11.4 AS mysql-seed', $dockerfile);
        $this->assertStringContainsString(
            'ARG DURABLE_WORKFLOW_CLI_VERSION='.$qualifiedArtifacts['cli'],
            $dockerfile,
        );
        $this->assertStringContainsString('COPY --from=docker-cli /usr/local/bin/docker', $dockerfile);
        $this->assertStringContainsString('COPY --from=rust /usr/local/cargo /usr/local/cargo', $dockerfile);
        $this->assertStringContainsString('COPY --from=rust /usr/local/rustup /usr/local/rustup', $dockerfile);
        $this->assertStringContainsString('healthcheck.sh --connect --innodb_initialized', $dockerfile);
        $this->assertStringContainsString('--protocol=tcp', $dockerfile);
        $this->assertStringContainsString('--host=127.0.0.1', $dockerfile);
        $this->assertStringContainsString('--file=/tmp/sample-app-mysql-seed.tar', $dockerfile);
        $this->assertStringContainsString('--numeric-owner', $dockerfile);
        $this->assertStringContainsString(
            'COPY --from=mysql-seed /tmp/sample-app-mysql-seed.tar /usr/local/share/sample-app/mysql-datadir.tar',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'COPY .devcontainer/docker/seed-mysql-volume /usr/local/bin/seed-mysql-volume',
            $dockerfile,
        );
        $this->assertStringContainsString('COPY package-lock.json /tmp/sample-app-package-lock.json', $dockerfile);
        $this->assertStringContainsString('lock.packages["node_modules/playwright"].version', $dockerfile);
        $this->assertStringContainsString('PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1', $dockerfile);
        $this->assertStringContainsString('playwright install --with-deps --only-shell chromium', $dockerfile);
        $this->assertStringNotContainsString('--download-only', $dockerfile);
        $this->assertStringContainsString('composer.json composer.lock /var/www/html/', $dockerfile);
        $this->assertStringContainsString(
            'microservice/composer.json microservice/composer.lock /var/www/html/microservice/',
            $dockerfile,
        );
        $this->assertStringContainsString('for dependency_dir in /var/www/html /var/www/html/microservice', $dockerfile);
        $this->assertStringContainsString('test -s "${dependency_dir}/vendor/autoload.php"', $dockerfile);
        $this->assertStringContainsString('rm -rf /home/laravel/.composer/cache', $dockerfile);
        $composerCacheRemoval = strpos($dockerfile, 'rm -rf /home/laravel/.composer/cache');
        $composerCredentialGuard = strpos(
            $dockerfile,
            'test -e /home/laravel/.composer/auth.json',
        );
        $composerPermissionNormalization = strpos(
            $dockerfile,
            'chmod -R g=u /home/laravel/.composer',
        );
        $this->assertNotFalse($composerCacheRemoval);
        $this->assertNotFalse($composerCredentialGuard);
        $this->assertNotFalse($composerPermissionNormalization);
        $this->assertLessThan($composerCredentialGuard, $composerCacheRemoval);
        $this->assertLessThan($composerPermissionNormalization, $composerCredentialGuard);
        $this->assertStringContainsString(
            'Composer credentials must not be baked into the development image.',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'COPY .devcontainer/docker/verify-prepared-permissions /usr/local/bin/verify-prepared-permissions',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'COPY .devcontainer/docker/verify-dependencies /usr/local/bin/verify-devcontainer-dependencies',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'COPY .devcontainer/docker/with-disposable-composer-state /usr/local/bin/with-disposable-composer-state',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'COPY .devcontainer/docker/with-group-shared-umask /usr/local/bin/with-group-shared-umask',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'with-disposable-composer-state composer --no-ansi --version',
            $verification,
        );
        $this->assertStringContainsString('apt-get purge -y --auto-remove', $dockerfile);
        $this->assertStringContainsString('canonical_library="$(readlink -f "$library")"', $dockerfile);
        $this->assertStringContainsString('dpkg-query --search "$canonical_library"', $dockerfile);
        $this->assertStringContainsString('dpkg-query --search "$library"', $dockerfile);
        $this->assertStringContainsString('No Debian runtime package owns PHP extension dependency', $dockerfile);
        $this->assertStringContainsString('Required PHP extension is unavailable after build dependency cleanup', $dockerfile);
        $dependencyPurge = strpos($dockerfile, 'apt-get purge -y --auto-remove');
        $postPurgeExtensionCheck = strpos(
            $dockerfile,
            'for extension in bcmath curl gd intl mbstring pcntl pdo_mysql pdo_sqlite redis zip',
        );
        $this->assertNotFalse($dependencyPurge);
        $this->assertNotFalse($postPurgeExtensionCheck);
        $this->assertLessThan($postPurgeExtensionCheck, $dependencyPurge);
        $this->assertStringContainsString('default-mysql-client', $dockerfile);
        $this->assertStringContainsString('redis-tools', $dockerfile);
        $this->assertStringContainsString('ripgrep', $dockerfile);
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
            'DEVCONTAINER_DEPENDENCY_SCOPE=baked',
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

        foreach ([
            'cargo',
            'cc',
            'composer',
            'curl',
            'docker',
            'dw',
            'ffmpeg',
            'git',
            'make',
            'mysql',
            'node',
            'pip',
            'playwright',
            'python',
            'redis-cli',
            'rg',
            'rustc',
            'ssh',
            'sshd',
        ] as $executable) {
            $this->assertStringContainsString($executable, $verification);
        }
        $this->assertStringContainsString('python -m venv --help', $verification);
        $this->assertStringContainsString('rustc "${rust_probe_dir}/main.rs"', $verification);
        $this->assertStringContainsString('docker compose version', $verification);
        $this->assertStringContainsString("compgen -G '/etc/ssh/ssh_host_*_key'", $verification);
        $this->assertStringContainsString('must not contain shared SSH host private keys', $verification);
        $this->assertStringContainsString('command -v mariadb', $databaseInitialization);
        $this->assertStringContainsString('command -v mysql', $databaseInitialization);
        $this->assertStringContainsString('healthcheck.sh --connect --innodb_initialized', $databaseHealthcheck);
        $this->assertStringContainsString('--protocol=tcp', $databaseHealthcheck);
        $this->assertStringContainsString('--host=127.0.0.1', $databaseHealthcheck);
        $this->assertStringContainsString("--execute='SELECT 1'", $databaseHealthcheck);
        $this->assertStringNotContainsString('migrations', $databaseHealthcheck);
        $this->assertStringContainsString('.sample-app-seed-in-progress', $databaseSeed);
        $this->assertStringContainsString('[[ -d "$data_dir/mysql" ]]', $databaseSeed);
        $this->assertStringContainsString('find "$data_dir" -mindepth 1 -depth -delete', $databaseSeed);
        $this->assertStringContainsString('-c:v libx264', $verification);
        $this->assertStringContainsString('-c:a aac', $verification);
        $this->assertStringContainsString('output.mp4', $verification);

        $this->assertStringContainsString("'npm ci --no-audit --no-fund'", $initCommand);
        $this->assertStringContainsString("'node docker/playwright-smoke.js'", $initCommand);
        $this->assertStringContainsString("DB::connection('mysql')->table", $initCommand);
        $this->assertStringContainsString("Redis::connection()->command('ping')", $initCommand);
        $this->assertStringContainsString("\$this->option('schema-path')", $initCommand);
        $this->assertStringContainsString('is_file($schemaPath)', $initCommand);
        $this->assertStringContainsString("\$migrationOptions['--schema-path']", $initCommand);
        $this->assertStringNotContainsString('npx playwright install', $initCommand);
        $this->assertStringNotContainsString('README.md', $initCommand);
        $this->assertStringContainsString('php artisan app:init', $postCreate);
        $this->assertStringContainsString('--schema-path=.devcontainer/schema/mysql-schema.sql', $postCreate);
        $this->assertStringContainsString('http://127.0.0.1/up', $postCreate);
        $this->assertStringContainsString('http://127.0.0.1/', $postCreate);
        $this->assertStringContainsString('timestamp_ms', $postCreate);
        $entrypoint = $this->contents('.devcontainer/docker/start-container');
        $this->assertStringContainsString('composer validate', $entrypoint);
        $this->assertStringContainsString('--check-lock', $entrypoint);
        $this->assertStringContainsString('[[ ! -s vendor/autoload.php ]]', $entrypoint);
        $this->assertTrue(is_executable($this->repoPath('.devcontainer/post-create.sh')));
        $this->assertTrue(is_executable($this->repoPath('.devcontainer/docker/seed-mysql-volume')));
    }

    public function test_mysql_seed_uses_the_preseed_only_for_the_default_fresh_volume(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-mysql-seed-'.bin2hex(random_bytes(8));
        $seedSource = $temporaryDirectory.'/seed-source';
        $seedArchive = $temporaryDirectory.'/mysql-seed.tar';
        $dataDirectory = $temporaryDirectory.'/data';
        $interruptedDirectory = $temporaryDirectory.'/interrupted';
        $overrideDirectory = $temporaryDirectory.'/override';
        $interruptedOverrideDirectory = $temporaryDirectory.'/interrupted-override';
        $unexpectedDirectory = $temporaryDirectory.'/unexpected';
        $filesystem->mkdir([
            $seedSource.'/mysql',
            $seedSource.'/sample',
            $seedSource.'/testing',
            $dataDirectory,
            $interruptedDirectory,
            $overrideDirectory,
            $interruptedOverrideDirectory,
            $unexpectedDirectory,
        ], 0700);
        file_put_contents($seedSource.'/.sample-app-codespaces-seed', "seed\n");
        file_put_contents($seedSource.'/mysql/system-table', "system\n");
        file_put_contents($seedSource.'/sample/application-table', "application\n");
        file_put_contents($seedSource.'/testing/database-marker', "testing\n");

        try {
            (new Process([
                'tar',
                '--create',
                '--file='.$seedArchive,
                '--directory='.$seedSource,
                '.',
            ]))->mustRun();

            $fakeId = $temporaryDirectory.'/id';
            file_put_contents($fakeId, <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "${FAKE_ID_UID:-0}"
BASH);
            chmod($fakeId, 0700);

            $environment = [
                'PATH' => $temporaryDirectory.':'.getenv('PATH'),
                'FAKE_ID_UID' => '0',
                'MYSQL_DATABASE' => 'sample',
                'MYSQL_USER' => 'laravel',
                'MYSQL_PASSWORD' => 'password',
                'SAMPLE_APP_MYSQL_DATA_DIR' => $dataDirectory,
                'SAMPLE_APP_MYSQL_SEED_ARCHIVE' => $seedArchive,
            ];
            $overrideEnvironment = [
                ...$environment,
                'MYSQL_DATABASE' => 'custom_database',
                'MYSQL_USER' => 'custom_user',
                'MYSQL_PASSWORD' => 'custom_password',
            ];
            $seed = new Process(
                ['bash', $this->repoPath('.devcontainer/docker/seed-mysql-volume')],
                env: $environment,
            );
            $seed->mustRun();

            $this->assertFileExists($dataDirectory.'/.sample-app-codespaces-seed');
            $this->assertFileExists($dataDirectory.'/mysql/system-table');
            $this->assertFileExists($dataDirectory.'/sample/application-table');
            $this->assertFileExists($dataDirectory.'/testing/database-marker');
            $this->assertFileDoesNotExist($dataDirectory.'/.sample-app-seed-in-progress');

            file_put_contents($dataDirectory.'/sample/persistent-user-data', "preserve\n");
            $seed->mustRun();
            $this->assertFileExists($dataDirectory.'/sample/persistent-user-data');

            $existingOverride = new Process(
                ['bash', $this->repoPath('.devcontainer/docker/seed-mysql-volume')],
                env: $overrideEnvironment,
            );
            $existingOverride->mustRun();
            $this->assertFileExists($dataDirectory.'/sample/persistent-user-data');

            $freshOverride = new Process(
                ['bash', $this->repoPath('.devcontainer/docker/seed-mysql-volume')],
                env: [...$overrideEnvironment, 'SAMPLE_APP_MYSQL_DATA_DIR' => $overrideDirectory],
            );
            $freshOverride->mustRun();
            $this->assertStringContainsString('leaving the fresh volume', $freshOverride->getOutput());
            $this->assertSame([], array_values(array_diff(scandir($overrideDirectory) ?: [], ['.', '..'])));

            file_put_contents($interruptedDirectory.'/.sample-app-seed-in-progress', "partial\n");
            file_put_contents($interruptedDirectory.'/partial-data', "replace\n");
            (new Process(
                ['bash', $this->repoPath('.devcontainer/docker/seed-mysql-volume')],
                env: [...$environment, 'SAMPLE_APP_MYSQL_DATA_DIR' => $interruptedDirectory],
            ))->mustRun();
            $this->assertFileDoesNotExist($interruptedDirectory.'/partial-data');
            $this->assertFileExists($interruptedDirectory.'/sample/application-table');
            $this->assertFileDoesNotExist($interruptedDirectory.'/.sample-app-seed-in-progress');

            file_put_contents($interruptedOverrideDirectory.'/.sample-app-seed-in-progress', "partial\n");
            file_put_contents($interruptedOverrideDirectory.'/partial-data', "replace\n");
            (new Process(
                ['bash', $this->repoPath('.devcontainer/docker/seed-mysql-volume')],
                env: [
                    ...$overrideEnvironment,
                    'SAMPLE_APP_MYSQL_DATA_DIR' => $interruptedOverrideDirectory,
                ],
            ))->mustRun();
            $this->assertSame(
                [],
                array_values(array_diff(scandir($interruptedOverrideDirectory) ?: [], ['.', '..'])),
            );

            file_put_contents($unexpectedDirectory.'/unknown-data', "unknown\n");
            $unexpected = new Process(
                ['bash', $this->repoPath('.devcontainer/docker/seed-mysql-volume')],
                env: [...$environment, 'SAMPLE_APP_MYSQL_DATA_DIR' => $unexpectedDirectory],
            );
            $unexpected->run();
            $this->assertSame(1, $unexpected->getExitCode());
            $this->assertStringContainsString('Refusing to seed non-empty MySQL data directory', $unexpected->getErrorOutput());
            $this->assertFileExists($unexpectedDirectory.'/unknown-data');

            $nonRoot = new Process(
                ['bash', $this->repoPath('.devcontainer/docker/seed-mysql-volume')],
                env: [...$environment, 'FAKE_ID_UID' => '1000'],
            );
            $nonRoot->run();
            $this->assertSame(1, $nonRoot->getExitCode());
        } finally {
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_setup_persists_runtime_database_overrides(): void
    {
        $overrides = [
            'DB_HOST' => 'database.internal',
            'DB_DATABASE' => 'custom_database',
            'DB_USERNAME' => 'custom_user',
            'DB_PASSWORD' => 'custom_password',
            'SHARED_DB_HOST' => 'shared-database.internal',
            'SHARED_DB_DATABASE' => 'custom_shared_database',
            'SHARED_DB_USERNAME' => 'custom_shared_user',
            'SHARED_DB_PASSWORD' => 'custom_shared_password',
        ];
        $originalEnvironment = [];
        $command = new class extends Init
        {
            /** @var array<string, string> */
            public array $seededEnvironment = [];

            /** @return array<string, string> */
            public function seedEnvironment(): array
            {
                $this->seedEnvDefaults();

                return $this->seededEnvironment;
            }

            protected function setEnvVariable(string $key, string $value): void
            {
                $this->seededEnvironment[$key] = $value;
            }

            protected function reloadEnvConfig(): void {}
        };

        try {
            foreach ($overrides as $key => $value) {
                $originalEnvironment[$key] = getenv($key);
                putenv("{$key}={$value}");
            }

            $seededEnvironment = $command->seedEnvironment();

            foreach ($overrides as $key => $value) {
                $this->assertSame($value, $seededEnvironment[$key] ?? null);
            }
        } finally {
            foreach ($originalEnvironment as $key => $value) {
                putenv($value === false ? $key : "{$key}={$value}");
            }
        }
    }

    public function test_mysql_healthcheck_fails_closed_without_gating_on_the_schema_version(): void
    {
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-mysql-health-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($temporaryDirectory, 0700));

        try {
            $commands = [
                'healthcheck.sh' => <<<'BASH'
#!/usr/bin/env bash
exit 0
BASH,
                'mariadb' => <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" > "$FAKE_MYSQL_ARGUMENTS"
exit "${FAKE_MYSQL_STATUS:-0}"
BASH,
            ];

            foreach ($commands as $command => $contents) {
                $commandPath = $temporaryDirectory.'/'.$command;
                $this->assertNotFalse(file_put_contents($commandPath, $contents));
                $this->assertTrue(chmod($commandPath, 0700));
            }

            $argumentOutput = $temporaryDirectory.'/mariadb-arguments';
            $environment = [
                'PATH' => $temporaryDirectory.':'.getenv('PATH'),
                'MYSQL_USER' => 'laravel',
                'MYSQL_PASSWORD' => 'password',
                'MYSQL_DATABASE' => 'sample',
                'FAKE_MYSQL_ARGUMENTS' => $argumentOutput,
            ];
            $healthcheck = new Process(
                ['bash', $this->repoPath('.devcontainer/docker/mysql-healthcheck.sh')],
                env: $environment,
            );

            $healthcheck->mustRun();
            $arguments = file_get_contents($argumentOutput);
            $this->assertIsString($arguments);
            $this->assertStringContainsString('--protocol=tcp', $arguments);
            $this->assertStringContainsString('--host=127.0.0.1', $arguments);
            $this->assertStringContainsString('--execute=SELECT 1', $arguments);

            $unavailableDatabase = new Process(
                ['bash', $this->repoPath('.devcontainer/docker/mysql-healthcheck.sh')],
                env: [...$environment, 'FAKE_MYSQL_STATUS' => '1'],
            );
            $unavailableDatabase->run();

            $this->assertSame(1, $unavailableDatabase->getExitCode());
        } finally {
            foreach (['healthcheck.sh', 'mariadb', 'mariadb-arguments'] as $command) {
                @unlink($temporaryDirectory.'/'.$command);
            }
            @rmdir($temporaryDirectory);
        }
    }

    public function test_image_seeds_composer_dependencies_as_the_unprivileged_runtime_user(): void
    {
        $dockerfile = $this->contents('.devcontainer/docker/Dockerfile');
        $userCreation = strpos($dockerfile, 'useradd --create-home --gid laravel');
        $browserInstallation = strpos($dockerfile, 'playwright install --with-deps --only-shell chromium');
        $rootDependencyOwnership = strpos(
            $dockerfile,
            'COPY --chown=laravel:laravel composer.json composer.lock /var/www/html/',
        );
        $microserviceDependencyOwnership = strpos(
            $dockerfile,
            'COPY --chown=laravel:laravel microservice/composer.json microservice/composer.lock /var/www/html/microservice/',
        );
        $unprivilegedSeed = strpos(
            $dockerfile,
            'COMPOSER_HOME=/home/laravel/.composer gosu laravel composer install',
        );

        $this->assertNotFalse($userCreation);
        $this->assertNotFalse($browserInstallation);
        $this->assertNotFalse($rootDependencyOwnership);
        $this->assertNotFalse($microserviceDependencyOwnership);
        $this->assertNotFalse($unprivilegedSeed);
        $this->assertLessThan($rootDependencyOwnership, $userCreation);
        $this->assertLessThan($rootDependencyOwnership, $browserInstallation);
        $this->assertLessThan($microserviceDependencyOwnership, $rootDependencyOwnership);
        $this->assertLessThan($unprivilegedSeed, $microserviceDependencyOwnership);
        $this->assertStringContainsString('gosu laravel test -w "$writable_path"', $dockerfile);
        $this->assertStringContainsString(
            'install -d -m 0775 -o laravel -g laravel "${dependency_dir}/vendor"',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'Composer seed path is not writable by laravel: ${writable_path}',
            $dockerfile,
        );
    }

    public function test_prepared_permission_verifier_reports_the_exact_entry_and_mode(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-prepared-permissions-'.bin2hex(random_bytes(8));
        $composerHome = $temporaryDirectory.'/.composer';
        $failingEntry = $composerHome.'/cache-entry';
        $filesystem->mkdir($composerHome, 0770);
        chmod($composerHome, 0770);
        file_put_contents($failingEntry, "prepared\n");
        chmod($failingEntry, 0600);

        $process = new Process([
            'bash',
            $this->repoPath('.devcontainer/docker/verify-prepared-permissions'),
            $composerHome,
        ]);

        try {
            $process->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString(
                'mode=600 owner='.(string) fileowner($failingEntry).' group='.(string) filegroup($failingEntry)." path={$failingEntry}",
                $process->getErrorOutput(),
            );

            chmod($failingEntry, 0660);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        } finally {
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_composer_state_runner_uses_and_removes_disposable_state(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-composer-verifier-'.bin2hex(random_bytes(8));
        $preparedComposerHome = $temporaryDirectory.'/prepared-composer';
        $fakeBinaryDirectory = $temporaryDirectory.'/bin';
        $composerStateLog = $temporaryDirectory.'/composer-state';
        $preparedSentinel = $preparedComposerHome.'/prepared-state';
        $filesystem->mkdir([$preparedComposerHome, $fakeBinaryDirectory], 0770);
        file_put_contents($preparedSentinel, "prepared\n");
        file_put_contents($fakeBinaryDirectory.'/composer', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n%s\n' "$COMPOSER_HOME" "$COMPOSER_CACHE_DIR" > "$FAKE_COMPOSER_STATE_LOG"
mkdir -p "$COMPOSER_HOME" "$COMPOSER_CACHE_DIR"
printf 'Composer version 2.9.0 2026-08-20 00:00:00\n'
BASH);
        chmod($fakeBinaryDirectory.'/composer', 0700);

        $process = new Process(
            [
                'bash',
                $this->repoPath('.devcontainer/docker/with-disposable-composer-state'),
                'composer',
                '--no-ansi',
                '--version',
            ],
            env: [
                'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
                'COMPOSER_HOME' => $preparedComposerHome,
                'FAKE_COMPOSER_STATE_LOG' => $composerStateLog,
            ],
        );

        try {
            $process->mustRun();
            $verificationComposerState = file($composerStateLog, FILE_IGNORE_NEW_LINES);
            $this->assertIsArray($verificationComposerState);
            [$verificationComposerHome, $verificationComposerCache] = $verificationComposerState;
            $this->assertNotSame($preparedComposerHome, $verificationComposerHome);
            $this->assertSame($verificationComposerHome.'/cache', $verificationComposerCache);
            $this->assertNotSame($preparedComposerHome.'/cache', $verificationComposerCache);
            $this->assertDirectoryDoesNotExist($verificationComposerHome);
            $this->assertDirectoryDoesNotExist($verificationComposerCache);
            $this->assertDirectoryDoesNotExist(dirname($verificationComposerHome));
            $this->assertDirectoryDoesNotExist($preparedComposerHome.'/cache');
            $this->assertFileExists($preparedSentinel);
        } finally {
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_startup_composer_operations_cannot_mutate_the_prepared_home(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-startup-composer-'.bin2hex(random_bytes(8));
        $projectDirectory = $temporaryDirectory.'/project';
        $preparedComposerHome = $temporaryDirectory.'/prepared-composer';
        $fakeBinaryDirectory = $temporaryDirectory.'/bin';
        $composerStateLog = $temporaryDirectory.'/composer-state';
        $preparedSentinel = $preparedComposerHome.'/prepared-state';
        $filesystem->mkdir([
            $projectDirectory.'/vendor',
            $preparedComposerHome,
            $fakeBinaryDirectory,
        ], 0770);
        file_put_contents($projectDirectory.'/composer.json', "{}\n");
        file_put_contents($projectDirectory.'/vendor/autoload.php', "<?php\n");
        file_put_contents($preparedSentinel, "prepared\n");
        symlink(
            $this->repoPath('.devcontainer/docker/with-disposable-composer-state'),
            $fakeBinaryDirectory.'/with-disposable-composer-state',
        );
        file_put_contents($fakeBinaryDirectory.'/composer', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\t%s\t%s\n' "$1" "$COMPOSER_HOME" "$COMPOSER_CACHE_DIR" >> "$FAKE_COMPOSER_STATE_LOG"
mkdir -p "$COMPOSER_CACHE_DIR/files"
BASH);
        file_put_contents($fakeBinaryDirectory.'/gosu', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
shift
exec "$@"
BASH);
        chmod($fakeBinaryDirectory.'/composer', 0700);
        chmod($fakeBinaryDirectory.'/gosu', 0700);

        try {
            foreach (['non-root' => '', 'root-remapped' => 'gosu laravel'] as $prefix) {
                $process = new Process(
                    [
                        'bash',
                        '-euc',
                        <<<'BASH'
cd "$1"
source "$2"
read -r -a command_prefix <<< "$3"
install_locked_composer_dependencies "${command_prefix[@]}"
BASH,
                        'bash',
                        $projectDirectory,
                        $this->repoPath('.devcontainer/docker/start-container'),
                        $prefix,
                    ],
                    env: [
                        'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
                        'COMPOSER_HOME' => $preparedComposerHome,
                        'FAKE_COMPOSER_STATE_LOG' => $composerStateLog,
                    ],
                );
                $process->mustRun();
            }

            $operations = file($composerStateLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertIsArray($operations);
            $this->assertCount(4, $operations);
            $this->assertSame(['validate', 'install', 'validate', 'install'], array_map(
                static fn (string $operation): string => explode("\t", $operation)[0],
                $operations,
            ));
            foreach ($operations as $operation) {
                [, $composerHome, $composerCache] = explode("\t", $operation);
                $this->assertNotSame($preparedComposerHome, $composerHome);
                $this->assertSame($composerHome.'/cache', $composerCache);
                $this->assertDirectoryDoesNotExist($composerHome);
            }
            $this->assertDirectoryDoesNotExist($preparedComposerHome.'/cache');
            $this->assertFileExists($preparedSentinel);
        } finally {
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_dependency_verification_respects_each_service_mount_boundary(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-service-dependencies-'.bin2hex(random_bytes(8));
        $laravelVendor = $temporaryDirectory.'/laravel-vendor';
        $microserviceVendor = $temporaryDirectory.'/microservice-vendor';
        $fakeBinaryDirectory = $temporaryDirectory.'/bin';
        $filesystem->mkdir([$laravelVendor, $microserviceVendor, $fakeBinaryDirectory], 0775);
        chmod($laravelVendor, 0775);
        chmod($microserviceVendor, 0775);
        file_put_contents($laravelVendor.'/autoload.php', "<?php\n");
        file_put_contents($microserviceVendor.'/autoload.php', "<?php\n");
        chmod($laravelVendor.'/autoload.php', 0664);
        chmod($microserviceVendor.'/autoload.php', 0644);
        symlink(
            $this->repoPath('.devcontainer/docker/verify-prepared-permissions'),
            $fakeBinaryDirectory.'/verify-prepared-permissions',
        );

        $verification = static fn (string $scope): Process => new Process(
            [
                'bash',
                dirname(__DIR__, 2).'/.devcontainer/docker/verify-dependencies',
                $scope,
            ],
            env: [
                'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
                'SAMPLE_APP_LARAVEL_VENDOR' => $laravelVendor,
                'SAMPLE_APP_MICROSERVICE_VENDOR' => $microserviceVendor,
            ],
        );

        try {
            $laravelRuntime = $verification('laravel');
            $laravelRuntime->mustRun();

            foreach (['baked', 'microservice'] as $scope) {
                $failingVerification = $verification($scope);
                $failingVerification->run();
                $this->assertSame(1, $failingVerification->getExitCode());
                $this->assertStringContainsString(
                    "path={$microserviceVendor}/autoload.php",
                    $failingVerification->getErrorOutput(),
                );
            }

            chmod($microserviceVendor.'/autoload.php', 0664);
            $verification('baked')->mustRun();
            $verification('microservice')->mustRun();
        } finally {
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_group_shared_runner_makes_new_cache_entries_group_writable(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-group-shared-'.bin2hex(random_bytes(8));
        $createdFile = $temporaryDirectory.'/cargo-output.d';
        $filesystem->mkdir($temporaryDirectory, 0775);

        $process = new Process([
            'bash',
            $this->repoPath('.devcontainer/docker/with-group-shared-umask'),
            'bash',
            '-euc',
            'printf "dependency-info\n" > "$1"',
            'write-cache-entry',
            $createdFile,
        ]);

        try {
            $process->mustRun();
            $this->assertSame(0664, fileperms($createdFile) & 0777);
        } finally {
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_codespaces_schema_dump_records_every_current_migration(): void
    {
        $schema = $this->contents('.devcontainer/schema/mysql-schema.sql');
        preg_match_all(
            '/INSERT INTO `migrations` .*? VALUES \([0-9]+,\'([^\']+)\',[0-9]+\);/',
            $schema,
            $matches,
        );

        $recorded = $matches[1];
        sort($recorded);

        $expected = [];
        foreach ([
            'database/migrations',
            'vendor/durable-workflow/waterline/database/migrations',
            'vendor/durable-workflow/workflow/src/migrations',
        ] as $directory) {
            foreach (glob($this->repoPath($directory).'/*_*.php') ?: [] as $migration) {
                $expected[pathinfo($migration, PATHINFO_FILENAME)] = true;
            }
        }

        $expected = array_keys($expected);
        sort($expected);

        $this->assertSame($expected, $recorded);
        $this->assertFileDoesNotExist($this->repoPath('database/schema/mysql-schema.sql'));
    }

    public function test_publication_separates_untrusted_builds_from_protected_registry_jobs(): void
    {
        $candidateWorkflow = $this->contents('.github/workflows/devcontainer-image-pr.yml');
        $publicationWorkflow = $this->contents('.github/workflows/devcontainer-image.yml');
        $combinedWorkflows = $candidateWorkflow."\n".$publicationWorkflow;
        $candidateHeader = explode("\njobs:", $candidateWorkflow, 2)[0];
        $publicationHeader = explode("\njobs:", $publicationWorkflow, 2)[0];
        $artifactIdentity = $this->jobBlock($publicationWorkflow, 'artifact-identity');
        $validate = $this->jobBlock($candidateWorkflow, 'validate');
        $candidateEvidence = $this->jobBlock($candidateWorkflow, 'candidate-evidence');
        $publish = $this->jobBlock($publicationWorkflow, 'publish-architecture');
        $assembly = $this->jobBlock($publicationWorkflow, 'assemble-indexes');
        $qualification = $this->jobBlock($publicationWorkflow, 'qualify-published');
        $promotion = $this->jobBlock($publicationWorkflow, 'promote-main');
        $recovery = $this->jobBlock($publicationWorkflow, 'recover-main');
        $movingChannel = $this->jobBlock($publicationWorkflow, 'verify-main');
        $publicationEvidence = $this->jobBlock($publicationWorkflow, 'publication-evidence');
        $matrixRunner = "runs-on: \${{ github.server_url == 'https://github.com' && matrix.runner || 'ubuntu-latest' }}";
        $aggregationRunner = "runs-on: \${{ github.server_url == 'https://github.com' && 'ubuntu-24.04' || 'ubuntu-latest' }}";

        $this->assertStringContainsString("  pull_request:\n    branches: [ main ]", $candidateHeader);
        $this->assertStringNotContainsString('  push:', $candidateHeader);
        $this->assertStringNotContainsString('  schedule:', $candidateHeader);
        $this->assertStringNotContainsString('  workflow_dispatch:', $candidateHeader);
        $this->assertStringContainsString("permissions:\n  contents: read", $candidateHeader);
        foreach ([$candidateHeader, $publicationHeader] as $header) {
            $this->assertStringContainsString('.github/workflows/devcontainer-image.yml', $header);
            $this->assertStringContainsString('.github/workflows/devcontainer-image-pr.yml', $header);
        }
        $this->assertStringNotContainsString('  pull_request:', $publicationHeader);
        $this->assertStringContainsString("  push:\n    branches: [ main ]", $publicationHeader);
        $this->assertStringContainsString('  schedule:', $publicationHeader);
        $this->assertStringContainsString('  workflow_dispatch:', $publicationHeader);
        $this->assertStringContainsString("permissions:\n  contents: read", $publicationHeader);
        $this->assertStringContainsString('devcontainer-image-${{ github.event.pull_request.number }}', $candidateHeader);
        $this->assertStringContainsString('group: devcontainer-image-protected-main', $publicationHeader);
        $this->assertStringNotContainsString("\n  publish-architecture:", $candidateWorkflow);
        $this->assertStringNotContainsString("\n  validate:", $publicationWorkflow);
        $this->assertStringNotContainsString('pull_request_target', $combinedWorkflows);
        $this->assertStringNotContainsString('setup-qemu-action', $combinedWorkflows);
        $this->assertStringNotContainsString('QEMU', $combinedWorkflows);
        foreach (['packages: write', 'secrets.', 'docker/login-action', 'cache-from', 'cache-to', 'environment:'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $candidateWorkflow);
        }
        $this->assertStringContainsString('runner: ubuntu-24.04', $validate);
        $this->assertStringContainsString('runner: ubuntu-24.04-arm', $validate);
        foreach ([$validate, $publish, $qualification] as $job) {
            $this->assertStringContainsString($matrixRunner, $job);
        }
        foreach ([$artifactIdentity, $candidateEvidence, $assembly, $promotion, $recovery, $movingChannel, $publicationEvidence] as $job) {
            $this->assertStringContainsString($aggregationRunner, $job);
        }
        $this->assertStringContainsString('revision_tag: ${{ steps.identity.outputs.revision_tag }}', $artifactIdentity);
        $this->assertStringContainsString(
            'revision_tag="sha-${GITHUB_SHA}-run-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"',
            $artifactIdentity,
        );
        $this->assertStringContainsString('echo "revision_tag=$revision_tag" >> "$GITHUB_OUTPUT"', $artifactIdentity);
        $this->assertStringNotContainsString(
            'REVISION_TAG: sha-${{ github.sha }}-run-${{ github.run_id }}-${{ github.run_attempt }}',
            $publicationWorkflow,
        );
        $this->assertStringContainsString('platforms: ${{ matrix.platform }}', $validate);
        $this->assertStringContainsString('contents: read', $validate);
        $this->assertStringNotContainsString('packages: write', $validate);
        $this->assertStringNotContainsString('secrets.', $validate);
        $this->assertStringNotContainsString('docker/login-action', $validate);
        $this->assertStringNotContainsString('cache-from', $validate);
        $this->assertStringNotContainsString('cache-to', $validate);
        $this->assertStringContainsString('no-cache: true', $validate);
        $this->assertStringContainsString('push: false', $validate);
        $this->assertStringContainsString('needs: [validate]', $candidateEvidence);
        $this->assertStringContainsString('summarize-devcontainer-evidence.py', $candidateEvidence);
        $this->assertStringContainsString('900', $candidateEvidence);

        $this->assertStringContainsString("github.repository == 'durable-workflow/sample-app'", $publish);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $publish);
        $this->assertStringContainsString('needs: [artifact-identity]', $publish);
        $this->assertStringContainsString(
            'REVISION_TAG: ${{ needs.artifact-identity.outputs.revision_tag }}',
            $publish,
        );
        $this->assertStringContainsString('runner: ubuntu-24.04', $publish);
        $this->assertStringContainsString('runner: ubuntu-24.04-arm', $publish);
        $this->assertStringContainsString('packages: write', $publish);
        $this->assertStringContainsString('secrets.DOCKERHUB_TOKEN', $publish);
        $this->assertStringContainsString('platforms: ${{ matrix.platform }}', $publish);
        $this->assertStringContainsString('${{ env.REVISION_TAG }}-${{ matrix.suffix }}', $publish);
        $this->assertStringContainsString('provenance: mode=max', $publish);
        $this->assertStringContainsString('sbom: true', $publish);
        $this->assertStringContainsString('no-cache: true', $publish);
        $this->assertStringNotContainsString('cache-from', $publish);
        $this->assertStringNotContainsString('cache-to', $publish);

        $this->assertStringContainsString('needs: [artifact-identity, publish-architecture]', $assembly);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $assembly);
        $this->assertStringContainsString('imagetools create', $assembly);
        $this->assertStringContainsString('cmp ghcr-index.json dockerhub-index.json', $assembly);
        $this->assertStringContainsString('needs: [artifact-identity, assemble-indexes]', $qualification);
        $this->assertStringContainsString('runner: ubuntu-24.04', $qualification);
        $this->assertStringContainsString('runner: ubuntu-24.04-arm', $qualification);
        $this->assertStringContainsString('DEVCONTAINER_REQUIRE_ANONYMOUS_PULL: 1', $qualification);
        $this->assertStringContainsString('linux/amd64', $qualification);
        $this->assertStringContainsString('linux/arm64', $qualification);
        $this->assertStringNotContainsString('secrets.', $qualification);
        $this->assertStringNotContainsString('docker/login-action', $qualification);
        $this->assertStringContainsString('needs: [artifact-identity, assemble-indexes, qualify-published]', $promotion);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $promotion);
        $this->assertStringContainsString("inputs.recover_revision_tag != ''", $recovery);
        $this->assertStringContainsString("github.repository == 'durable-workflow/sample-app'", $recovery);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $recovery);
        $this->assertStringContainsString('packages: write', $recovery);
        $this->assertStringContainsString('secrets.DOCKERHUB_TOKEN', $recovery);
        $this->assertStringContainsString('^sha-[0-9a-f]{40}-run-[0-9]+-[0-9]+$', $recovery);
        $this->assertStringContainsString('cmp ghcr-source.json dockerhub-source.json', $recovery);
        $this->assertStringContainsString('architectures != {"amd64", "arm64"}', $recovery);
        $this->assertStringContainsString('cmp ghcr-main.json dockerhub-main.json', $recovery);
        $this->assertStringContainsString('needs: [artifact-identity, promote-main]', $movingChannel);
        $this->assertStringContainsString('anonymous-docker-config', $movingChannel);
        $this->assertStringContainsString('needs: [verify-main]', $publicationEvidence);
        $this->assertStringContainsString('summarize-devcontainer-evidence.py', $publicationEvidence);
        $this->assertStringContainsString('compressed_platform_bytes', $publish);
        $this->assertStringContainsString('largest_compressed_layer_bytes', $publish);
        $this->assertStringContainsString('compressed_layer_count', $publish);
        $this->assertStringContainsString('within_size_budget', $publish);
        $this->assertStringContainsString("always() && steps.build.outcome == 'success'", $publish);
        $this->assertStringContainsString("MAX_COMPRESSED_PLATFORM_BYTES: '1200000000'", $candidateWorkflow);
        $this->assertStringContainsString("MAX_COMPRESSED_LAYER_BYTES: '400000000'", $candidateWorkflow);
        $this->assertStringContainsString("MAX_COMPRESSED_PLATFORM_BYTES: '1200000000'", $publicationWorkflow);
        $this->assertStringContainsString("MAX_COMPRESSED_LAYER_BYTES: '400000000'", $publicationWorkflow);
        $this->assertStringContainsString('900', $publicationEvidence);

        preg_match_all('/^\s*uses:\s+[^@\s]+@([^\s#]+)/m', $combinedWorkflows, $actionRefs);
        $this->assertNotEmpty($actionRefs[1]);
        foreach ($actionRefs[1] as $ref) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $ref);
        }
    }

    public function test_qualification_never_builds_and_records_fresh_and_warm_phases(): void
    {
        $script = $this->contents('scripts/ci/qualify-devcontainer-image.sh');
        $databaseOverrides = $this->contents('scripts/ci/qualify-devcontainer-database-overrides.sh');
        $entrypoint = $this->contents('.devcontainer/docker/start-container');
        $identityWaiter = $this->contents('.devcontainer/docker/wait-for-identity-ready');
        $identityCompose = $this->contents('scripts/ci/devcontainer-identity.sh');
        $imageVerifier = $this->contents('.devcontainer/docker/verify-image.sh');
        $dockerfile = $this->contents('.devcontainer/docker/Dockerfile');
        $postCreate = $this->contents('.devcontainer/post-create.sh');

        $this->assertStringNotContainsString('docker compose build', $script);
        $this->assertStringContainsString('up --detach --no-build --wait', $script);
        $this->assertStringContainsString('up --detach --no-build --force-recreate --wait', $script);
        $this->assertStringContainsString('environment_builds', $script);
        $this->assertStringContainsString('normalize_architecture', $script);
        $this->assertStringContainsString('anonymous_pull_verification', $script);
        $this->assertStringContainsString('runner', $script);
        $this->assertStringContainsString('export SAMPLE_APP_UID="$(id -u)"', $script);
        $this->assertStringContainsString('prepare_qualification_checkout', $script);
        $this->assertStringContainsString('sudo chown -R "${SAMPLE_APP_UID}:${qualification_gid}"', $script);
        $this->assertStringContainsString('stat --format=%u .env', $script);
        $this->assertStringContainsString('[[ "$(id -u)" == "$SAMPLE_APP_UID" ]]', $script);
        $this->assertStringContainsString('exec -T --user laravel laravel .devcontainer/post-create.sh', $script);
        $this->assertStringContainsString('php artisan migrate:status --no-interaction', $script);
        $this->assertStringContainsString('php artisan migrate:status --pending=1 --no-interaction', $script);
        $this->assertStringContainsString('SELECT COUNT(*) FROM migrations', $script);
        $this->assertStringContainsString('information_schema.tables', $script);
        $this->assertStringContainsString('expected_migration_count=49', $script);
        $this->assertStringContainsString('expected_table_count=49', $script);
        preg_match_all('/^verify_database_schema$/m', $script, $schemaVerifications, PREG_OFFSET_CAPTURE);
        $this->assertCount(4, $schemaVerifications[0]);
        $this->assertLessThan(
            strpos($script, 'dependency_bootstrap_started_ms='),
            $schemaVerifications[0][0][1],
        );
        $this->assertStringContainsString('redis-cli -h redis --raw ping', $script);
        $this->assertStringContainsString('docker version >/dev/null', $script);
        $this->assertStringContainsString('docker compose version >/dev/null', $script);
        $this->assertStringContainsString('second_app_key', $script);
        $this->assertStringContainsString('qualify-devcontainer-database-overrides.sh', $script);
        $this->assertStringContainsString('database_override_ms', $script);
        $this->assertStringContainsString('DB_DATABASE=codespaces_override', $databaseOverrides);
        $this->assertStringContainsString('test ! -e /var/lib/mysql/.sample-app-codespaces-seed', $databaseOverrides);
        $this->assertStringContainsString('php artisan migrate:status --pending=1', $databaseOverrides);
        $this->assertStringContainsString('codespaces_testing_probe', $databaseOverrides);
        $this->assertStringContainsString('codespaces-override-probe@example.invalid', $databaseOverrides);
        $this->assertStringContainsString('run --rm --no-deps mysql-seed', $databaseOverrides);
        $this->assertStringContainsString('force-recreate --wait laravel microservice', $databaseOverrides);
        $this->assertTrue(is_executable($this->repoPath('scripts/ci/qualify-devcontainer-database-overrides.sh')));
        $this->assertStringContainsString('status --porcelain --untracked-files=no', $script);
        $this->assertStringContainsString('http://localhost/', $script);
        $this->assertStringContainsString('exec -T laravel sshd -t', $script);
        $this->assertStringContainsString('/dev/tcp/127.0.0.1/22', $script);
        $this->assertStringContainsString('laravel@127.0.0.1 id -u', $script);
        $this->assertStringContainsString('-o BatchMode=yes', $script);
        $this->assertStringContainsString('prepare_project_permissions', $entrypoint);
        $this->assertStringContainsString("ssh-keygen -A\n        sshd -t", $entrypoint);
        $this->assertStringContainsString('remap_laravel_uid', $entrypoint);
        $this->assertStringContainsString('prepare_docker_socket_access', $entrypoint);
        $this->assertStringContainsString('stat --format=%g "$docker_socket"', $entrypoint);
        $this->assertStringContainsString('usermod --append --groups "$socket_group" laravel', $entrypoint);
        $this->assertStringContainsString('gosu laravel test -w "$docker_socket"', $entrypoint);
        $this->assertStringContainsString('SAMPLE_APP_LOCAL_GROUP_FILE', $entrypoint);
        $this->assertStringContainsString('SAMPLE_APP_IDENTITY_OPERATION_TIMEOUT_SECONDS', $entrypoint);
        $this->assertStringContainsString('run_bounded_identity_operation', $entrypoint);
        $this->assertStringContainsString('adding-docker-socket-group', $entrypoint);
        $this->assertStringNotContainsString('getent group', $entrypoint);
        $this->assertStringContainsString('rm -f "$identity_readiness_marker"', $entrypoint);
        $this->assertStringContainsString('publish_identity_readiness', $entrypoint);
        $this->assertStringContainsString('chown 0:0 "$marker_temporary"', $entrypoint);
        $this->assertStringContainsString('chmod 0444 "$marker_temporary"', $entrypoint);
        $this->assertLessThan(
            strrpos($entrypoint, '    publish_identity_readiness'),
            strrpos($entrypoint, '    prepare_docker_socket_access'),
        );
        $this->assertStringContainsString('SAMPLE_APP_IDENTITY_READY_TIMEOUT_SECONDS', $identityWaiter);
        $this->assertStringContainsString('until identity_is_ready; do', $identityWaiter);
        $this->assertStringContainsString('Timed out waiting for development-container identity readiness', $identityWaiter);
        $this->assertStringContainsString('Active startup stage:', $identityWaiter);
        $this->assertStringContainsString(
            'exec -T --user root "$service" wait-for-devcontainer-identity',
            $identityCompose,
        );
        $this->assertStringContainsString('run_in_ready_devcontainer laravel bash -euc', $script);
        $this->assertStringContainsString('run_in_ready_devcontainer laravel .devcontainer/post-create.sh', $databaseOverrides);
        $this->assertStringContainsString('[[ " $(id -G) " == *" ${socket_gid} "* ]]', $script);
        $this->assertStringContainsString('/usr/local/bin/wait-for-devcontainer-identity', $postCreate);
        $this->assertStringContainsString('exec sg "$socket_group"', $postCreate);
        $this->assertStringNotContainsString('getent group', $postCreate);
        $this->assertStringContainsString('SAMPLE_APP_UID must be a positive, non-root decimal user ID.', $entrypoint);
        $this->assertStringContainsString('SAMPLE_APP_LOCAL_PASSWD_FILE', $entrypoint);
        $this->assertStringNotContainsString('getent passwd', $entrypoint);
        $this->assertStringContainsString('update_local_passwd_uid laravel "$requested_uid"', $entrypoint);
        $this->assertStringNotContainsString('usermod --uid "$requested_uid" laravel', $entrypoint);
        $this->assertStringContainsString('verifying-prepared-toolchain-access', $entrypoint);
        $this->assertStringContainsString('chmod -R g=u', $dockerfile);
        $this->assertStringContainsString('verify-prepared-permissions', $imageVerifier);
        $this->assertStringContainsString(
            'for prepared_home in "${COMPOSER_HOME:?}" "${CARGO_HOME:?}"',
            $script,
        );
        $this->assertStringContainsString('composer validate', $script);
        $this->assertStringContainsString(
            'with-disposable-composer-state composer validate',
            $script,
        );
        $this->assertStringContainsString(
            'with-disposable-composer-state composer check-platform-reqs --no-dev',
            $script,
        );
        $this->assertStringNotContainsString('cargo metadata', $script);
        $this->assertStringContainsString(
            <<<'SHELL'
with-group-shared-umask cargo check \
        --bins \
        --locked \
        --offline \
        --manifest-path=playground/templates/rust/Cargo.toml
SHELL,
            $script,
        );
        $this->assertStringContainsString('--env DEVCONTAINER_DEPENDENCY_SCOPE=laravel', $script);
        $this->assertStringContainsString('--env DEVCONTAINER_DEPENDENCY_SCOPE=microservice', $script);
        $this->assertStringContainsString('chown -R laravel:laravel "$generated_dir"', $entrypoint);
        $this->assertStringContainsString('for language in php python rust; do', $script);
        $this->assertStringContainsString(
            'fresh_total_ms=$(( image_pull_ms + container_readiness_ms + dependency_bootstrap_ms + application_readiness_ms + playground_journey_ms ))',
            $script,
        );

        foreach ([
            'image_pull',
            'container_readiness',
            'dependency_bootstrap',
            'application_readiness',
            'playground_journey_ms',
            'database_override_ms',
            'fresh_total_ms',
            'warm_rebuild_ms',
        ] as $timingKey) {
            $this->assertStringContainsString($timingKey, $script);
        }
    }

    public function test_identity_readiness_waits_for_a_non_default_uid_marker(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-identity-ready-'.bin2hex(random_bytes(8));
        $marker = $temporaryDirectory.'/identity-ready';
        $socketPath = $temporaryDirectory.'/docker.sock';
        $filesystem->mkdir($temporaryDirectory, 0700);
        $socket = stream_socket_server('unix://'.$socketPath, $errorCode, $errorMessage);
        $this->assertIsResource($socket, $errorMessage);

        $fakeStat = $temporaryDirectory.'/stat';
        file_put_contents($fakeStat, <<<'BASH'
#!/usr/bin/env bash
if [[ "${@: -1}" == "$FAKE_IDENTITY_MARKER" ]]; then
    case "$1" in
        --format=%u|--format=%g)
            printf '0\n'
            exit 0
            ;;
    esac
fi
exec /usr/bin/stat "$@"
BASH);
        chmod($fakeStat, 0700);

        $waiter = new Process(
            ['bash', $this->repoPath('.devcontainer/docker/wait-for-identity-ready')],
            env: [
                'PATH' => $temporaryDirectory.':'.getenv('PATH'),
                'FAKE_IDENTITY_MARKER' => $marker,
                'SAMPLE_APP_DOCKER_SOCKET' => $socketPath,
                'SAMPLE_APP_IDENTITY_READY_MARKER' => $marker,
                'SAMPLE_APP_IDENTITY_READY_POLL_INTERVAL_SECONDS' => '0.02',
                'SAMPLE_APP_IDENTITY_READY_TIMEOUT_SECONDS' => '2',
                'SAMPLE_APP_UID' => '12345',
            ],
        );

        try {
            $waiter->start();
            usleep(100_000);

            $this->assertTrue($waiter->isRunning(), $waiter->getErrorOutput());
            $this->assertFileDoesNotExist($marker);

            $socketGid = filegroup($socketPath);
            $this->assertIsInt($socketGid);
            file_put_contents($marker, "uid=12345\nsocket_gid={$socketGid}\n");
            chmod($marker, 0444);

            $waiter->wait();
            $this->assertSame(0, $waiter->getExitCode(), $waiter->getErrorOutput());
        } finally {
            fclose($socket);
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_identity_readiness_accepts_a_service_without_a_docker_socket(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-identity-ready-no-socket-'.bin2hex(random_bytes(8));
        $marker = $temporaryDirectory.'/identity-ready';
        $missingSocket = $temporaryDirectory.'/docker.sock';
        $filesystem->mkdir($temporaryDirectory, 0700);

        $fakeStat = $temporaryDirectory.'/stat';
        file_put_contents($fakeStat, <<<'BASH'
#!/usr/bin/env bash
if [[ "${@: -1}" == "$FAKE_IDENTITY_MARKER" ]]; then
    case "$1" in
        --format=%u|--format=%g)
            printf '0\n'
            exit 0
            ;;
    esac
fi
exec /usr/bin/stat "$@"
BASH);
        chmod($fakeStat, 0700);

        file_put_contents($marker, "uid=12345\nsocket_gid=absent\n");
        chmod($marker, 0444);

        $waiter = new Process(
            ['bash', $this->repoPath('.devcontainer/docker/wait-for-identity-ready')],
            env: [
                'PATH' => $temporaryDirectory.':'.getenv('PATH'),
                'FAKE_IDENTITY_MARKER' => $marker,
                'SAMPLE_APP_DOCKER_SOCKET' => $missingSocket,
                'SAMPLE_APP_IDENTITY_READY_MARKER' => $marker,
                'SAMPLE_APP_IDENTITY_READY_TIMEOUT_SECONDS' => '2',
                'SAMPLE_APP_UID' => '12345',
            ],
        );

        try {
            $waiter->run();
            $this->assertSame(0, $waiter->getExitCode(), $waiter->getErrorOutput());
        } finally {
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_identity_timeout_reports_the_active_startup_stage(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-identity-stage-'.bin2hex(random_bytes(8));
        $stage = $temporaryDirectory.'/identity-stage';
        $filesystem->mkdir($temporaryDirectory, 0700);
        file_put_contents($stage, "adding-docker-socket-group\n");

        $waiter = new Process(
            ['bash', $this->repoPath('.devcontainer/docker/wait-for-identity-ready')],
            env: [
                'SAMPLE_APP_DOCKER_SOCKET' => $temporaryDirectory.'/docker.sock',
                'SAMPLE_APP_IDENTITY_READY_MARKER' => $temporaryDirectory.'/identity-ready',
                'SAMPLE_APP_IDENTITY_STAGE_MARKER' => $stage,
                'SAMPLE_APP_IDENTITY_READY_POLL_INTERVAL_SECONDS' => '0.02',
                'SAMPLE_APP_IDENTITY_READY_TIMEOUT_SECONDS' => '1',
                'SAMPLE_APP_UID' => '12345',
            ],
        );

        try {
            $waiter->run();
            $this->assertNotSame(0, $waiter->getExitCode());
            $this->assertStringContainsString('Active startup stage:', $waiter->getErrorOutput());
            $this->assertStringContainsString('adding-docker-socket-group', $waiter->getErrorOutput());
        } finally {
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_non_default_uid_socket_group_mutation_times_out_with_actionable_stage(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-socket-group-ready-'.bin2hex(random_bytes(8));
        $stage = $temporaryDirectory.'/identity-stage';
        $events = $temporaryDirectory.'/events';
        $socketPath = $temporaryDirectory.'/docker.sock';
        $filesystem->mkdir($temporaryDirectory, 0700);
        $socket = stream_socket_server('unix://'.$socketPath, $errorCode, $errorMessage);
        $this->assertIsResource($socket, $errorMessage);
        $socketGid = filegroup($socketPath);
        $this->assertIsInt($socketGid);

        file_put_contents($temporaryDirectory.'/passwd', "laravel:x:1000:1000::/home/laravel:/bin/bash\n");
        file_put_contents($temporaryDirectory.'/group', "dockerlocal:x:{$socketGid}:\n");

        file_put_contents($temporaryDirectory.'/usermod', <<<'BASH'
#!/usr/bin/env bash
printf 'usermod %s\n' "$*" >> "$FAKE_EVENTS"
if [[ "$1" == "--uid" ]]; then
    awk -F: -v OFS=: -v uid="$2" '$1 == "laravel" { $3 = uid } { print }' \
        "$FAKE_PASSWD" > "${FAKE_PASSWD}.new"
    mv "${FAKE_PASSWD}.new" "$FAKE_PASSWD"
    exit 0
fi
if [[ "$1" == "--append" ]]; then
    while :; do :; done
fi
exit 64
BASH);
        file_put_contents($temporaryDirectory.'/chown', <<<'BASH'
#!/usr/bin/env bash
printf 'chown %s\n' "$*" >> "$FAKE_EVENTS"
BASH);
        file_put_contents($temporaryDirectory.'/install', <<<'BASH'
#!/usr/bin/env bash
target="${@: -1}"
mkdir -p "$target"
chmod 0755 "$target"
BASH);
        file_put_contents($temporaryDirectory.'/getent', <<<'BASH'
#!/usr/bin/env bash
printf 'getent %s\n' "$*" >> "$FAKE_EVENTS"
exit 90
BASH);
        foreach (['usermod', 'chown', 'install', 'getent'] as $executable) {
            chmod($temporaryDirectory.'/'.$executable, 0700);
        }

        $process = new Process(
            [
                'bash',
                '-euc',
                'source "$1"; remap_laravel_uid; prepare_docker_socket_access',
                'bash',
                $this->repoPath('.devcontainer/docker/start-container'),
            ],
            env: [
                'PATH' => $temporaryDirectory.':'.getenv('PATH'),
                'FAKE_EVENTS' => $events,
                'FAKE_PASSWD' => $temporaryDirectory.'/passwd',
                'SAMPLE_APP_DOCKER_SOCKET' => $socketPath,
                'SAMPLE_APP_IDENTITY_OPERATION_TIMEOUT_SECONDS' => '1',
                'SAMPLE_APP_IDENTITY_READY_MARKER' => $temporaryDirectory.'/identity-ready',
                'SAMPLE_APP_IDENTITY_STAGE_MARKER' => $stage,
                'SAMPLE_APP_LOCAL_GROUP_FILE' => $temporaryDirectory.'/group',
                'SAMPLE_APP_LOCAL_PASSWD_FILE' => $temporaryDirectory.'/passwd',
                'SAMPLE_APP_UID' => '12345',
            ],
        );
        $process->setTimeout(5);
        $startedAt = microtime(true);

        try {
            $process->run();
            $this->assertNotSame(0, $process->getExitCode());
            $this->assertLessThan(4.0, microtime(true) - $startedAt);
            $this->assertStringContainsString(
                "Adding laravel to Docker socket group dockerlocal (gid {$socketGid}) timed out after 1s.",
                $process->getErrorOutput(),
            );
            $this->assertSame("adding-docker-socket-group\n", file_get_contents($stage));

            $recordedEvents = file_get_contents($events);
            $this->assertIsString($recordedEvents);
            $this->assertStringContainsString('usermod --append --groups dockerlocal laravel', $recordedEvents);
            $this->assertStringNotContainsString('usermod --uid 12345 laravel', $recordedEvents);
            $this->assertStringNotContainsString('getent ', $recordedEvents);
        } finally {
            fclose($socket);
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_simultaneous_non_default_uid_remaps_do_not_invoke_recursive_usermod(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-parallel-uid-remap-'.bin2hex(random_bytes(8));
        $filesystem->mkdir($temporaryDirectory, 0700);

        file_put_contents($temporaryDirectory.'/usermod', <<<'BASH'
#!/usr/bin/env bash
sleep 10
BASH);
        file_put_contents($temporaryDirectory.'/chown', <<<'BASH'
#!/usr/bin/env bash
exit 0
BASH);
        file_put_contents($temporaryDirectory.'/install', <<<'BASH'
#!/usr/bin/env bash
target="${@: -1}"
mkdir -p "$target"
chmod 0755 "$target"
BASH);
        file_put_contents($temporaryDirectory.'/gosu', <<<'BASH'
#!/usr/bin/env bash
shift
exec "$@"
BASH);
        chmod($temporaryDirectory.'/usermod', 0700);
        chmod($temporaryDirectory.'/chown', 0700);
        chmod($temporaryDirectory.'/install', 0700);
        chmod($temporaryDirectory.'/gosu', 0700);

        $processes = [];
        foreach ([12345, 12346] as $index => $requestedUid) {
            $runtimeDirectory = $temporaryDirectory.'/runtime-'.$index;
            $homeDirectory = $temporaryDirectory.'/home-'.$index;
            $playgroundDirectory = $temporaryDirectory.'/playground-'.$index;
            $passwd = $temporaryDirectory.'/passwd-'.$index;
            $filesystem->mkdir([$runtimeDirectory, $homeDirectory, $playgroundDirectory], 0775);
            file_put_contents($passwd, "laravel:x:1000:1000::{$homeDirectory}:/bin/bash\n");
            chmod($passwd, 0644);

            $processes[] = new Process(
                [
                    'bash',
                    '-euc',
                    'source "$1"; remap_laravel_uid',
                    'bash',
                    $this->repoPath('.devcontainer/docker/start-container'),
                ],
                env: [
                    'PATH' => $temporaryDirectory.':'.getenv('PATH'),
                    'SAMPLE_APP_IDENTITY_OPERATION_TIMEOUT_SECONDS' => '1',
                    'SAMPLE_APP_IDENTITY_READY_MARKER' => $runtimeDirectory.'/identity-ready',
                    'SAMPLE_APP_IDENTITY_STAGE_MARKER' => $runtimeDirectory.'/identity-stage',
                    'SAMPLE_APP_LARAVEL_HOME' => $homeDirectory,
                    'SAMPLE_APP_LOCAL_PASSWD_FILE' => $passwd,
                    'SAMPLE_APP_PREPARED_PLAYGROUND_ROOT' => $playgroundDirectory,
                    'SAMPLE_APP_UID' => (string) $requestedUid,
                ],
            );
        }

        try {
            $startedAt = microtime(true);
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
            $this->assertLessThan(4.0, microtime(true) - $startedAt);

            foreach ([12345, 12346] as $index => $requestedUid) {
                $passwd = file_get_contents($temporaryDirectory.'/passwd-'.$index);
                $this->assertIsString($passwd);
                $this->assertStringContainsString("laravel:x:{$requestedUid}:1000:", $passwd);
            }
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_database_override_cold_start_waits_for_non_default_uid_before_post_create(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-database-override-ready-'.bin2hex(random_bytes(8));
        $marker = $temporaryDirectory.'/identity-ready';
        $events = $temporaryDirectory.'/events';
        $filesystem->mkdir($temporaryDirectory, 0700);

        $fakeDocker = $temporaryDirectory.'/docker';
        file_put_contents($fakeDocker, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

arguments=" $* "
case "$arguments" in
    *" up --detach --no-build laravel microservice ")
        printf 'containers-started\n' >> "$FAKE_EVENTS"
        (
            sleep 0.15
            printf 'uid=%s\nsocket_gid=9876\n' "$SAMPLE_APP_UID" > "$FAKE_IDENTITY_MARKER"
            printf 'identity-ready\n' >> "$FAKE_EVENTS"
        ) &
        ;;
    *" exec -T --user root laravel wait-for-devcontainer-identity ")
        printf 'wait-started\n' >> "$FAKE_EVENTS"
        for ((attempt = 0; attempt < 100; attempt++)); do
            if [[ -r "$FAKE_IDENTITY_MARKER" ]] \
                && grep -Fx "uid=$SAMPLE_APP_UID" "$FAKE_IDENTITY_MARKER" >/dev/null; then
                printf 'wait-completed\n' >> "$FAKE_EVENTS"
                exit 0
            fi
            sleep 0.02
        done
        exit 1
        ;;
    *" exec -T --user laravel laravel .devcontainer/post-create.sh ")
        grep -Fx "uid=$SAMPLE_APP_UID" "$FAKE_IDENTITY_MARKER" >/dev/null
        printf 'post-create-started\n' >> "$FAKE_EVENTS"
        ;;
    *" up --detach --no-build --wait ")
        printf 'services-healthy\n' >> "$FAKE_EVENTS"
        ;;
    *)
        printf 'Unexpected docker command: %s\n' "$*" >&2
        exit 64
        ;;
esac
BASH);
        chmod($fakeDocker, 0700);

        $process = new Process(
            [
                'bash',
                '-euc',
                'source "$1"; bootstrap_devcontainer_application',
                'bash',
                $this->repoPath('scripts/ci/qualify-devcontainer-database-overrides.sh'),
            ],
            env: [
                'PATH' => $temporaryDirectory.':'.getenv('PATH'),
                'FAKE_EVENTS' => $events,
                'FAKE_IDENTITY_MARKER' => $marker,
                'SAMPLE_APP_UID' => '12345',
            ],
        );

        try {
            $process->mustRun();
            $this->assertSame(
                [
                    'containers-started',
                    'wait-started',
                    'identity-ready',
                    'wait-completed',
                    'post-create-started',
                    'services-healthy',
                ],
                file($events, FILE_IGNORE_NEW_LINES),
            );
        } finally {
            $filesystem->remove($temporaryDirectory);
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
