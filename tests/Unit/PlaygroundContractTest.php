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

    public function test_doctor_composer_probes_cannot_mutate_the_prepared_home(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-playground-doctor-'.bin2hex(random_bytes(8));
        $fakeBinaryDirectory = $temporaryDirectory.'/bin';
        $phpRuntime = $temporaryDirectory.'/php-runtime';
        $preparedComposerHome = $temporaryDirectory.'/prepared-composer';
        $composerStateLog = $temporaryDirectory.'/composer-state';
        $preparedSentinel = $preparedComposerHome.'/prepared-state';
        $filesystem->mkdir([
            $fakeBinaryDirectory,
            $phpRuntime.'/vendor/durable-workflow/sdk/docs',
            $preparedComposerHome,
        ], 0770);
        file_put_contents(
            $phpRuntime.'/vendor/durable-workflow/sdk/docs/quickstart-contract.json',
            json_encode([
                'package' => ['published_version' => '2.0.0-rc.40'],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents($preparedSentinel, "prepared\n");
        symlink(
            $this->path('.devcontainer/docker/with-disposable-composer-state'),
            $fakeBinaryDirectory.'/with-disposable-composer-state',
        );
        file_put_contents($fakeBinaryDirectory.'/composer', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\t%s\t%s\n' "$1" "$COMPOSER_HOME" "$COMPOSER_CACHE_DIR" >> "$FAKE_COMPOSER_STATE_LOG"
mkdir -p "$COMPOSER_CACHE_DIR/files"
if [[ "$1" == "--version" ]]; then
    printf 'Composer version 2.10.2 2026-07-01 11:24:45\n'
else
    printf '{"versions":["2.0.0-rc.40"]}\n'
fi
BASH);

        $fakeCommands = [
            'php' => 'printf "8.4.0\\n"',
            'python' => <<<'BASH'
if [[ " $* " == *" --version "* ]]; then
    printf 'Python 3.11.0\n'
elif [[ " $* " == *" importlib.metadata "* ]]; then
    printf '2.0.0rc32\n'
fi
BASH,
            'pip' => 'printf "pip 26.0\\n"',
            'rustc' => 'printf "rustc 1.86.0 (test)\\n"',
            'cargo' => 'printf "cargo 1.86.0 (test)\\n"',
            'docker' => <<<'BASH'
if [[ "$1" == "compose" ]]; then
    printf '2.39.0\n'
else
    printf '28.3.0\n'
fi
BASH,
            'dw' => 'printf "dw 0.4.0\\n"',
            'with-group-shared-umask' => 'exec "$@"',
        ];
        foreach ($fakeCommands as $name => $body) {
            file_put_contents(
                $fakeBinaryDirectory.'/'.$name,
                "#!/usr/bin/env bash\nset -euo pipefail\n{$body}\n",
            );
        }
        foreach (glob($fakeBinaryDirectory.'/*') ?: [] as $fakeCommand) {
            if (! is_link($fakeCommand)) {
                chmod($fakeCommand, 0700);
            }
        }

        $harness = <<<'PYTHON'
import json
import runpy
import sys
from pathlib import Path

playground = runpy.run_path(sys.argv[1])
doctor = playground["doctor"]
doctor.__globals__["resolve_artifacts"] = lambda: {
    "DURABLE_SERVER_IMAGE": "durableworkflow/server:2.0.0-rc.20",
    "DURABLE_WORKFLOW_PHP_SDK_VERSION": "2.0.0-rc.40",
    "DURABLE_WORKFLOW_PYTHON_SDK_VERSION": "2.0.0-rc.32",
    "DURABLE_WORKFLOW_RUST_SDK_VERSION": "2.0.0-rc.32",
}
doctor.__globals__["php_runtime"] = lambda: Path(sys.argv[2])
versions = doctor()
assert versions["sdk_php_laravel"] == "2.0.0-rc.40", json.dumps(versions)
PYTHON;

        try {
            $process = new Process(
                ['python3', '-c', $harness, $this->path('scripts/playground'), $phpRuntime],
                env: [
                    ...getenv(),
                    'PATH' => $fakeBinaryDirectory.PATH_SEPARATOR.getenv('PATH'),
                    'COMPOSER_HOME' => $preparedComposerHome,
                    'FAKE_COMPOSER_STATE_LOG' => $composerStateLog,
                ],
            );
            $process->mustRun();

            $operations = file($composerStateLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertIsArray($operations);
            $this->assertSame(['--version', 'show'], array_map(
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
