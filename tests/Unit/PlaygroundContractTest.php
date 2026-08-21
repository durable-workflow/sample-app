<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class PlaygroundContractTest extends TestCase
{
    public function test_playground_exposes_symmetric_authored_scenarios(): void
    {
        $contract = $this->contract();
        $compose = Yaml::parseFile($this->path('playground/docker-compose.yml'));

        $this->assertSame('durable-workflow.sample-app.playground', $contract['schema'] ?? null);
        $this->assertSame(2, $contract['schema_version'] ?? null);
        $this->assertSame(['php', 'python', 'rust'], $contract['choices'] ?? null);
        $this->assertSame('caller', $contract['source_ownership'] ?? null);
        $this->assertSame('polyglot/qualified-artifact-tuple.json', $contract['artifact_source'] ?? null);
        $this->assertTrue($contract['runtime']['isolated_state_per_journey'] ?? false);
        $this->assertTrue($contract['proof']['requires_worker_registration'] ?? false);
        $this->assertTrue($contract['proof']['requires_selected_waterline_run'] ?? false);

        foreach ($contract['choices'] as $language) {
            $scenario = $contract['scenarios'][$language] ?? [];
            $this->assertStringStartsWith("sample-app.playground.{$language}.", $scenario['workflow_type'] ?? '');
            $this->assertStringStartsWith("sample-app.playground.{$language}.", $scenario['activity_type'] ?? '');
            $this->assertStringStartsWith("sample-app-playground-{$language}-", $scenario['task_queue'] ?? '');
            $this->assertNotEmpty($scenario['worker_command'] ?? []);
            $this->assertNotEmpty($scenario['start_command'] ?? []);
            $this->assertSame($language, $scenario['expected_result']['workflow_runtime'] ?? null);
            $this->assertSame($language, $scenario['expected_result']['activity_runtime'] ?? null);
        }

        $this->assertSame('laravel-bridge', $contract['scenarios']['php']['integration'] ?? null);
        $services = $compose['services'] ?? [];
        $this->assertSame(
            '${DURABLE_SERVER_IMAGE:?resolve the published artifact tuple first}',
            $services['server']['image'] ?? null,
        );
        $this->assertArrayNotHasKey('build', $services['server'] ?? []);
        $this->assertArrayNotHasKey('build', $services['waterline'] ?? []);
    }

    #[DataProvider('languageProvider')]
    public function test_scaffolds_fresh_caller_owned_source(string $language): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-playground-'.bin2hex(random_bytes(8));

        try {
            (new Process([
                $this->path('scripts/playground'),
                'scaffold',
                $language,
                '--source',
                $temporaryDirectory,
            ]))->mustRun();

            $this->assertDirectoryExists($temporaryDirectory);
            $this->assertNotEmpty(glob($temporaryDirectory.'/*') ?: []);
            if ($language === 'php') {
                foreach (['Scenario.php', 'PlaygroundWorkflow.php', 'PlaygroundActivity.php', 'worker.php', 'client.php', 'test.php'] as $file) {
                    $this->assertFileExists($temporaryDirectory.'/'.$file);
                }
                $scenario = file_get_contents($temporaryDirectory.'/Scenario.php') ?: '';
                $declared = $this->contract()['scenarios']['php'];
                $this->assertStringContainsString($declared['workflow_type'], $scenario);
                $this->assertStringContainsString($declared['activity_type'], $scenario);
            }
        } finally {
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_php_standalone_scaffold_uses_installed_package_examples(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-playground-standalone-'.bin2hex(random_bytes(8));

        try {
            (new Process(
                [
                    $this->path('scripts/playground'),
                    'scaffold',
                    'php',
                    '--standalone',
                    '--source',
                    $temporaryDirectory,
                ],
                env: [
                    ...getenv(),
                    'PLAYGROUND_PHP_RUNTIME' => $this->path('playground/php-runtime'),
                ],
            ))->mustRun();

            foreach (['bootstrap.php', 'worker.php', 'client.php'] as $source) {
                $this->assertFileEquals(
                    $this->path('playground/php-runtime/vendor/durable-workflow/sdk/examples/'.$source),
                    $temporaryDirectory.'/'.$source,
                );
            }
        } finally {
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_development_image_bakes_tools_and_sdk_caches_before_post_create(): void
    {
        $dockerfile = file_get_contents($this->path('.devcontainer/docker/Dockerfile')) ?: '';
        $verification = file_get_contents($this->path('.devcontainer/docker/verify-image.sh')) ?: '';
        $postCreate = file_get_contents($this->path('.devcontainer/post-create.sh')) ?: '';
        $startContainer = file_get_contents($this->path('.devcontainer/docker/start-container')) ?: '';
        $compose = Yaml::parseFile($this->path('.devcontainer/docker/docker-compose.yml'));

        $this->assertStringContainsString('FROM rust:1.86.0-slim-bookworm AS rust', $dockerfile);
        $this->assertStringContainsString('python3-venv', $dockerfile);
        $this->assertStringContainsString('polyglot/qualified-artifact-tuple.json', $dockerfile);
        $this->assertStringContainsString('playground/php-runtime/composer.json playground/php-runtime/composer.lock', $dockerfile);
        $this->assertStringContainsString('cargo build', $dockerfile);
        $this->assertStringContainsString('--manifest-path=/tmp/sample-app-rust-playground/Cargo.toml', $dockerfile);
        $this->assertStringContainsString('/var/run/docker.sock:/var/run/docker.sock', $compose['services']['laravel']['volumes'][2] ?? '');
        $this->assertStringContainsString('usermod --append --groups', $startContainer);

        foreach (['python', 'pip', 'rustc', 'cargo', 'docker', 'dw'] as $executable) {
            $this->assertStringContainsString($executable, $verification);
        }
        $this->assertStringContainsString('scripts/playground doctor', $postCreate);
        $this->assertStringNotContainsString('apt-get', $postCreate);
        $this->assertStringNotContainsString('rustup', $postCreate);
        $this->assertStringNotContainsString('cargo build', $postCreate);
    }

    public function test_prepared_playground_sdks_match_the_qualified_artifact_tuple(): void
    {
        $tuple = json_decode(
            file_get_contents($this->path('polyglot/qualified-artifact-tuple.json')) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $phpLock = json_decode(
            file_get_contents($this->path('playground/php-runtime/composer.lock')) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $phpPackages = array_column($phpLock['packages'] ?? [], null, 'name');
        $rustLock = file_get_contents($this->path('playground/templates/rust/Cargo.lock')) ?: '';

        preg_match(
            '/\[\[package\]\]\nname = "durable-workflow"\nversion = "(?<version>[^"]+)"/',
            $rustLock,
            $rustPackage,
        );

        $this->assertSame(
            $tuple['artifacts']['sdk-php'] ?? null,
            $phpPackages['durable-workflow/sdk']['version'] ?? null,
        );
        $this->assertSame(
            $tuple['artifacts']['sdk-rust'] ?? null,
            $rustPackage['version'] ?? null,
        );
    }

    public function test_rust_runtime_commands_use_the_group_shared_write_policy(): void
    {
        $harness = <<<'PYTHON'
import runpy
import sys

playground = runpy.run_path(sys.argv[1])
scenario_command = playground["scenario_command"]

assert scenario_command({"worker": ["php", "worker.php"]}, "worker") == ["php", "worker.php"]
assert scenario_command({"worker": ["cargo", "run", "--bin", "worker"]}, "worker") == [
    "with-group-shared-umask",
    "cargo",
    "run",
    "--bin",
    "worker",
]
PYTHON;

        $process = new Process([
            'python3',
            '-c',
            $harness,
            $this->path('scripts/playground'),
        ]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_service_health_stall_has_a_bounded_deadline_and_actionable_diagnostics(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-playground-health-stall-'.bin2hex(random_bytes(8));
        $filesystem->mkdir($temporaryDirectory);
        $fakeDocker = $temporaryDirectory.'/docker';
        $commandLog = $temporaryDirectory.'/docker-commands.log';
        file_put_contents($fakeDocker, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$PLAYGROUND_FAKE_DOCKER_COMMAND_LOG"
case " $* " in
    *" down "*|*" pull "*)
        exit 0
        ;;
    *" up "*)
        [[ " $* " == *" --wait --wait-timeout 1 "* ]] || exit 91
        printf 'mysql Waiting\n' >&2
        exit 1
        ;;
    *" ps --all --format json "*)
        printf '%s\n' '[{"Service":"mysql","State":"running","Health":"starting","Status":"Up 1 second (health: starting)"},{"Service":"redis","State":"running","Health":"healthy","Status":"Up 1 second (healthy)"}]'
        ;;
    *" logs --no-color --tail=100 "*)
        printf 'mysql | initializing database files\n'
        ;;
    *)
        exit 92
        ;;
esac
BASH);
        chmod($fakeDocker, 0700);

        $harness = <<<'PYTHON'
import os
import runpy
import subprocess
import sys

playground = runpy.run_path(sys.argv[1])
environment = os.environ.copy()
environment["PLAYGROUND_COMPOSE_WAIT_SECONDS"] = "1"
compose = playground["compose_command"]("health-stall-test")
try:
    playground["start_services"](
        compose,
        environment,
        cleanup_command=["scripts/playground", "down", "rust"],
        retry_command=["scripts/playground", "rust", "--source", "/tmp/caller rust"],
    )
except subprocess.CalledProcessError:
    pass
else:
    raise AssertionError("the simulated health stall unexpectedly succeeded")
PYTHON;

        try {
            $process = new Process(
                ['python3', '-c', $harness, $this->path('scripts/playground')],
                env: [
                    ...getenv(),
                    'PATH' => $temporaryDirectory.PATH_SEPARATOR.getenv('PATH'),
                    'PLAYGROUND_FAKE_DOCKER_COMMAND_LOG' => $commandLog,
                ],
            );
            $process->mustRun();
            $output = $process->getOutput().$process->getErrorOutput();
            $commands = file_get_contents($commandLog) ?: '';

            $this->assertStringContainsString('Waiting up to 1 second for playground services', $output);
            $this->assertStringContainsString('mysql: state=running health=starting', $output);
            $this->assertStringContainsString('server: state=not-created', $output);
            $this->assertStringContainsString('mysql | initializing database files', $output);
            $this->assertStringContainsString(
                "scripts/playground down rust && scripts/playground rust --source '/tmp/caller rust'",
                $output,
            );
            $this->assertStringContainsString('up --detach --no-build --wait --wait-timeout 1', $commands);
            $this->assertStringContainsString('logs --no-color --tail=100 mysql bootstrap server waterline', $commands);
        } finally {
            $filesystem->remove($temporaryDirectory);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function languageProvider(): iterable
    {
        yield 'PHP Laravel bridge' => ['php'];
        yield 'Python SDK' => ['python'];
        yield 'Rust SDK' => ['rust'];
    }

    /** @return array<string, mixed> */
    private function contract(): array
    {
        return json_decode(
            file_get_contents($this->path('playground/contract.json')) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function path(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
