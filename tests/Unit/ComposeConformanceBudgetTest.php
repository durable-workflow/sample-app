<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ComposeConformanceBudgetTest extends TestCase
{
    public function test_slow_clean_build_and_healthy_startup_have_independent_deadlines(): void
    {
        $result = $this->runHarness('slow-success', [
            'SAMPLE_APP_SERVICE_REBUILD_TIMEOUT_SECONDS' => '1',
            'SAMPLE_APP_RUNTIME_BUILD_TIMEOUT_SECONDS' => '3',
            'SAMPLE_APP_SERVICE_READINESS_TIMEOUT_SECONDS' => '2',
        ]);

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertStringNotContainsString('timed out after', $result['output']);
        $this->assertMatchesRegularExpression(
            '/setup metrics .*build_duration_ms=\d+ readiness_duration_ms=\d+.*build_invocations=1/',
            $result['output'],
        );

        preg_match(
            '/build_duration_ms=(?<build>\d+) readiness_duration_ms=(?<readiness>\d+)/',
            $result['output'],
            $matches,
        );
        $buildDuration = (int) ($matches['build'] ?? 0);
        $readinessDuration = (int) ($matches['readiness'] ?? 0);

        $this->assertGreaterThanOrEqual(1000, $buildDuration);
        $this->assertGreaterThanOrEqual(400, $readinessDuration);
        $this->assertGreaterThan(1000, $buildDuration + $readinessDuration);

        $this->assertSame(1, substr_count($result['commands'], 'compose build app'));
        $this->assertStringNotContainsString('compose build worker', $result['commands']);
        $this->assertStringContainsString(
            'compose up -d --no-build --wait app worker',
            $result['commands'],
        );
        $this->assertStringNotContainsString('compose up -d --build', $result['commands']);
        $this->assertLessThan(
            strpos($result['commands'], 'compose up -d --no-build --wait app worker'),
            strpos($result['commands'], 'compose build app'),
        );
    }

    public function test_runtime_build_timeout_fails_closed_with_build_diagnostics(): void
    {
        $result = $this->runHarness('build-timeout', [
            'SAMPLE_APP_RUNTIME_BUILD_TIMEOUT_SECONDS' => '1',
        ]);

        $this->assertSame(124, $result['exit_code'], $result['output']);
        $this->assertStringContainsString(
            'building shared app and worker runtime image with resolved artifact tuple timed out after 1s',
            $result['output'],
        );
        $this->assertStringNotContainsString(
            'compose up -d --no-build --wait app worker',
            $result['commands'],
        );
    }

    public function test_service_readiness_timeout_fails_closed_with_readiness_diagnostics(): void
    {
        $result = $this->runHarness('readiness-timeout', [
            'SAMPLE_APP_SERVICE_READINESS_TIMEOUT_SECONDS' => '1',
        ]);

        $this->assertSame(124, $result['exit_code'], $result['output']);
        $this->assertStringContainsString(
            'starting app and worker services and waiting for readiness timed out after 1s',
            $result['output'],
        );
        $this->assertStringContainsString('compose build app', $result['commands']);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array{exit_code: int, output: string, commands: string}
     */
    private function runHarness(string $mode, array $overrides = []): array
    {
        $temporaryDirectory = sys_get_temp_dir().'/compose-conformance-budget-'.bin2hex(random_bytes(6));
        $dockerPath = $temporaryDirectory.'/docker';
        $logPath = $temporaryDirectory.'/docker.log';

        mkdir($temporaryDirectory, 0700, true);
        file_put_contents($dockerPath, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

printf '%q ' "$@" >> "$SAMPLE_APP_FAKE_DOCKER_LOG"
printf '\n' >> "$SAMPLE_APP_FAKE_DOCKER_LOG"

if [[ "${1:-}" == "compose" && "${2:-}" == "build" && "${3:-}" == "app" ]]; then
  case "$SAMPLE_APP_FAKE_DOCKER_MODE" in
    slow-success)
      sleep 1.2
      ;;
    build-timeout)
      sleep 2
      ;;
  esac
elif [[ "${1:-}" == "compose" && "${2:-}" == "up" && "${3:-}" == "-d" && "${4:-}" == "--no-build" ]]; then
  case "$SAMPLE_APP_FAKE_DOCKER_MODE" in
    slow-success)
      sleep 0.6
      ;;
    readiness-timeout)
      sleep 2
      ;;
  esac
elif [[ "$*" == "compose up -d --build --wait app worker" ]]; then
  sleep 2
fi
BASH);
        chmod($dockerPath, 0700);

        $environment = [
            'PATH' => $temporaryDirectory.PATH_SEPARATOR.getenv('PATH'),
            'COMPOSE_PROJECT_NAME' => 'compose-budget-test',
            'DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE' => $this->repoPath('tests/Fixtures/release-candidate-artifact-tuple.json'),
            'OPENAI_API_KEY' => '',
            'SAMPLE_APP_COMMIT' => 'compose-budget-test-revision',
            'SAMPLE_APP_CONFORMANCE_ALLOW_SKIPS' => '1',
            'SAMPLE_APP_CONFORMANCE_METADATA_PATH' => $temporaryDirectory.'/metadata.json',
            'SAMPLE_APP_CONFORMANCE_TIMEOUT_SECONDS' => '3',
            'SAMPLE_APP_DB_PROBE_TIMEOUT_SECONDS' => '1',
            'SAMPLE_APP_FAKE_DOCKER_LOG' => $logPath,
            'SAMPLE_APP_FAKE_DOCKER_MODE' => $mode,
            'SAMPLE_APP_METADATA_COPY_TIMEOUT_SECONDS' => '2',
            'SAMPLE_APP_MIGRATION_TIMEOUT_SECONDS' => '2',
            'SAMPLE_APP_RUNTIME_BUILD_TIMEOUT_SECONDS' => '3',
            'SAMPLE_APP_SERVICE_READINESS_TIMEOUT_SECONDS' => '2',
            'SAMPLE_APP_SETUP_CACHE_STATE' => 'clean-cache',
            'SAMPLE_APP_WORKER_RESTART_TIMEOUT_SECONDS' => '2',
            ...$overrides,
        ];
        $process = new Process(
            ['bash', $this->repoPath('scripts/compose-conformance.sh'), '--strict'],
            $this->repoPath(),
            $environment,
        );
        $process->setTimeout(15);

        try {
            $process->run();

            return [
                'exit_code' => $process->getExitCode() ?? -1,
                'output' => $process->getOutput().$process->getErrorOutput(),
                'commands' => (string) @file_get_contents($logPath),
            ];
        } finally {
            @unlink($dockerPath);
            @unlink($logPath);
            @unlink($temporaryDirectory.'/metadata.json');
            @rmdir($temporaryDirectory);
        }
    }

    private function repoPath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }
}
