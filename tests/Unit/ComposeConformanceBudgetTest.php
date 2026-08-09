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

    public function test_explicit_ai_skip_ignores_an_ancestor_dotenv_credential(): void
    {
        $credential = 'synthetic-provider-credential-'.bin2hex(random_bytes(24));
        $result = $this->runHarness('success', [
            'SAMPLE_APP_CONFORMANCE_SKIP_AI' => '1',
        ], $credential, []);

        $this->assertSkippedAiConformance($result, $credential);
    }

    public function test_an_ambient_ancestor_dotenv_credential_does_not_opt_in_to_ai(): void
    {
        $credential = 'synthetic-provider-credential-'.bin2hex(random_bytes(24));
        $result = $this->runHarness('success', [], $credential, []);

        $this->assertSkippedAiConformance($result, $credential);
    }

    public function test_provider_conformance_remains_available_through_explicit_opt_in(): void
    {
        $credential = 'synthetic-provider-credential-'.bin2hex(random_bytes(24));
        $result = $this->runHarness('success', [
            'SAMPLE_APP_CONFORMANCE_SKIP_AI' => '0',
        ], $credential, []);

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertStringContainsString('app:conformance', $result['commands']);
        $this->assertStringNotContainsString('--skip-ai', $result['commands']);
        $this->assertStringContainsString('-e OPENAI_API_KEY', $result['commands']);
        $this->assertStringContainsString('OPENAI_API_KEY_STATE=matched', $result['commands']);
        $this->assertStringContainsString('provider-command app:prism', $result['commands']);
        $this->assertStringNotContainsString($credential, $result['commands']);
        $this->assertStringNotContainsString($credential, $result['output']);
    }

    /**
     * @param  array<string, string>  $overrides
     * @param  list<string>  $arguments
     * @return array{exit_code: int, output: string, commands: string, metadata: string}
     */
    private function runHarness(
        string $mode,
        array $overrides = [],
        ?string $ancestorCredential = null,
        array $arguments = ['--strict'],
    ): array {
        $temporaryDirectory = sys_get_temp_dir().'/compose-conformance-budget-'.bin2hex(random_bytes(6));
        $dockerPath = $temporaryDirectory.'/docker';
        $logPath = $temporaryDirectory.'/docker.log';
        $metadataPath = $temporaryDirectory.'/metadata.json';
        $configuredEnvPath = $temporaryDirectory.'/configured.env';
        $workingDirectory = $this->repoPath();

        mkdir($temporaryDirectory, 0700, true);
        if ($ancestorCredential !== null) {
            $ancestorDirectory = $temporaryDirectory.'/workspace';
            $workingDirectory = $ancestorDirectory.'/sample-app';
            mkdir($workingDirectory, 0700, true);
            file_put_contents($ancestorDirectory.'/.env', "OPENAI_API_KEY={$ancestorCredential}\n");
            file_put_contents($configuredEnvPath, "OPENAI_API_KEY={$ancestorCredential}\n");
            symlink($this->repoPath('scripts'), $workingDirectory.'/scripts');
        }

        file_put_contents($dockerPath, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

credential_state="absent"
if [[ -n "${OPENAI_API_KEY:-}" ]]; then
  credential_state="present"
  if [[ -n "${SAMPLE_APP_FAKE_EXPECTED_CREDENTIAL:-}" && "$OPENAI_API_KEY" == "$SAMPLE_APP_FAKE_EXPECTED_CREDENTIAL" ]]; then
    credential_state="matched"
  fi
fi
printf 'OPENAI_API_KEY_STATE=%s ' "$credential_state" >> "$SAMPLE_APP_FAKE_DOCKER_LOG"
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

if [[ "$*" == *"app:conformance"* ]]; then
  if [[ "$*" == *"--skip-ai"* ]]; then
    printf '%s\n' '{"surfaces":{"deterministic_simple":{"status":"passed"},"mcp_workflow_api":{"status":"passed"},"prism_ai":{"status":"skipped","reason":"AI-backed samples were explicitly skipped."},"ai_agent_scripted":{"status":"skipped","reason":"AI-backed samples were explicitly skipped."},"ai_failure_hotel":{"status":"skipped","reason":"AI-backed samples were explicitly skipped."},"ai_failure_flight":{"status":"skipped","reason":"AI-backed samples were explicitly skipped."},"ai_failure_car":{"status":"skipped","reason":"AI-backed samples were explicitly skipped."}},"summary":{"skipped_surfaces":["prism_ai","ai_agent_scripted","ai_failure_hotel","ai_failure_flight","ai_failure_car"]}}' > "$SAMPLE_APP_FAKE_METADATA_PATH"
  else
    printf 'provider-command app:prism\n' >> "$SAMPLE_APP_FAKE_DOCKER_LOG"
  fi
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
            'SAMPLE_APP_CONFORMANCE_ENV_FILE' => $ancestorCredential === null ? '' : $configuredEnvPath,
            'SAMPLE_APP_CONFORMANCE_METADATA_PATH' => $metadataPath,
            'SAMPLE_APP_CONFORMANCE_TIMEOUT_SECONDS' => '3',
            'SAMPLE_APP_DB_PROBE_TIMEOUT_SECONDS' => '1',
            'SAMPLE_APP_FAKE_DOCKER_LOG' => $logPath,
            'SAMPLE_APP_FAKE_METADATA_PATH' => $metadataPath,
            'SAMPLE_APP_FAKE_DOCKER_MODE' => $mode,
            'SAMPLE_APP_FAKE_EXPECTED_CREDENTIAL' => $ancestorCredential ?? '',
            'SAMPLE_APP_METADATA_COPY_TIMEOUT_SECONDS' => '2',
            'SAMPLE_APP_MIGRATION_TIMEOUT_SECONDS' => '2',
            'SAMPLE_APP_RUNTIME_BUILD_TIMEOUT_SECONDS' => '3',
            'SAMPLE_APP_SERVICE_READINESS_TIMEOUT_SECONDS' => '2',
            'SAMPLE_APP_SETUP_CACHE_STATE' => 'clean-cache',
            'SAMPLE_APP_WORKER_RESTART_TIMEOUT_SECONDS' => '2',
            ...$overrides,
        ];
        $process = new Process(
            ['bash', $this->repoPath('scripts/compose-conformance.sh'), ...$arguments],
            $workingDirectory,
            $environment,
        );
        $process->setTimeout(15);

        try {
            $process->run();

            return [
                'exit_code' => $process->getExitCode() ?? -1,
                'output' => $process->getOutput().$process->getErrorOutput(),
                'commands' => (string) @file_get_contents($logPath),
                'metadata' => (string) @file_get_contents($metadataPath),
            ];
        } finally {
            @unlink($dockerPath);
            @unlink($logPath);
            @unlink($metadataPath);
            if ($ancestorCredential !== null) {
                @unlink($workingDirectory.'/scripts');
                @unlink($configuredEnvPath);
                @unlink($temporaryDirectory.'/workspace/.env');
                @rmdir($workingDirectory);
                @rmdir($temporaryDirectory.'/workspace');
            }
            @rmdir($temporaryDirectory);
        }
    }

    /**
     * @param  array{exit_code: int, output: string, commands: string, metadata: string}  $result
     */
    private function assertSkippedAiConformance(array $result, string $credential): void
    {
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertStringContainsString('app:conformance', $result['commands']);
        $this->assertStringContainsString('--skip-ai', $result['commands']);
        $this->assertStringNotContainsString('-e OPENAI_API_KEY', $result['commands']);
        $this->assertStringNotContainsString('OPENAI_API_KEY_STATE=matched', $result['commands']);
        $this->assertStringNotContainsString('OPENAI_API_KEY_STATE=present', $result['commands']);
        $this->assertStringNotContainsString('provider-command', $result['commands']);
        $this->assertStringNotContainsString($credential, $result['commands']);
        $this->assertStringNotContainsString($credential, $result['output']);
        $this->assertStringNotContainsString($credential, $result['metadata']);

        $metadata = json_decode($result['metadata'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('passed', $metadata['surfaces']['deterministic_simple']['status'] ?? null);
        $this->assertSame('passed', $metadata['surfaces']['mcp_workflow_api']['status'] ?? null);
        $this->assertSame([
            'prism_ai',
            'ai_agent_scripted',
            'ai_failure_hotel',
            'ai_failure_flight',
            'ai_failure_car',
        ], $metadata['summary']['skipped_surfaces'] ?? null);

        foreach ($metadata['summary']['skipped_surfaces'] as $surface) {
            $this->assertSame('skipped', $metadata['surfaces'][$surface]['status'] ?? null);
            $this->assertSame(
                'AI-backed samples were explicitly skipped.',
                $metadata['surfaces'][$surface]['reason'] ?? null,
            );
        }
    }

    private function repoPath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }
}
