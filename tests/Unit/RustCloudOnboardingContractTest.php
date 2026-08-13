<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class RustCloudOnboardingContractTest extends TestCase
{
    public function test_cloud_worker_resolves_the_exact_supported_sdk_release(): void
    {
        $manifest = (string) file_get_contents($this->repoPath('rust-cloud/Cargo.toml'));
        $lock = (string) file_get_contents($this->repoPath('rust-cloud/Cargo.lock'));
        $runner = (string) file_get_contents($this->repoPath('scripts/rust-cloud.sh'));

        preg_match('/^durable-workflow = "=(?<version>[^"]+)"$/m', $manifest, $match);

        $this->assertSame($this->qualifiedArtifactVersion('sdk-rust'), $match['version'] ?? null);
        $this->assertMatchesRegularExpression(
            '/name = "durable-workflow"\nversion = "'.preg_quote($match['version'], '/').'"/',
            $lock,
        );
        $this->assertStringContainsString('the supported dependency lock is missing', $runner);
        $this->assertStringContainsString('cargo build --locked', $runner);
    }

    public function test_cloud_worker_implements_the_published_execution_contract(): void
    {
        $worker = (string) file_get_contents($this->repoPath('rust-cloud/src/main.rs'));

        foreach ([
            'DURABLE_WORKFLOW_RUNTIME_URL',
            'DURABLE_WORKFLOW_RUNTIME_NAMESPACE',
            'DURABLE_WORKFLOW_WORKER_TOKEN',
            'DURABLE_WORKFLOW_TASK_QUEUE',
            'sample.rust-cloud.greeter',
            'sample.rust-cloud.greet',
            '.worker_token(Some(worker_token))',
            'run_until(shutdown_signal())',
        ] as $contractIdentifier) {
            $this->assertStringContainsString($contractIdentifier, $worker);
        }
    }

    public function test_runner_uses_only_the_client_execution_command_for_cloud_readiness(): void
    {
        $runner = (string) file_get_contents($this->repoPath('scripts/rust-cloud.sh'));

        $this->assertStringContainsString('dw workflow:start', $runner);
        $this->assertStringContainsString('--wait --json', $runner);
        $this->assertStringNotContainsString('dw server:info', $runner);
        $this->assertStringNotContainsString('dw worker:list', $runner);
    }

    public function test_runner_rejects_a_runtime_url_with_a_terminal_api_segment(): void
    {
        $process = new Process(
            ['bash', $this->repoPath('scripts/rust-cloud.sh'), 'worker'],
            $this->repoPath(''),
            [
                'DURABLE_WORKFLOW_RUNTIME_URL' => 'https://cloud.example/api/runtime/v1/namespaces/example/api',
                'DURABLE_WORKFLOW_RUNTIME_NAMESPACE' => 'example',
                'DURABLE_WORKFLOW_WORKER_TOKEN' => 'worker-token',
            ],
        );
        $process->run();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('without a terminal /api', $process->getErrorOutput());
    }

    public function test_run_scopes_credentials_to_the_resolver_build_worker_and_cli_roles(): void
    {
        $result = $this->runCredentialProbe('run');

        foreach (['resolver', 'cargo-tree', 'cargo-build', 'dw-version'] as $role) {
            $this->assertCredentialScope($result['events'], $role, '<unset>', '<unset>');
            $this->assertRuntimeScopeAbsent($result['events'], $role);
        }

        $this->assertCredentialScope($result['events'], 'worker', '<unset>', 'worker-secret');
        $this->assertCredentialScope($result['events'], 'dw-workflow', 'client-secret', '<unset>');
        $this->assertCredentialScope($result['events'], 'worker-signal', '<unset>', 'worker-secret');
        $this->assertRuntimeScope($result['events'], 'worker');
        $this->assertRuntimeScopeAbsent($result['events'], 'dw-workflow');
        $this->assertSame(0, $result['exitCode'], $result['output'].$result['errorOutput']);
        $this->assertStringContainsString('Rust Cloud completed workflow_id=', $result['output']);
    }

    public function test_worker_mode_builds_without_credentials_then_scopes_only_the_worker_role(): void
    {
        $result = $this->runCredentialProbe('worker');

        $this->assertCredentialScope($result['events'], 'resolver', '<unset>', '<unset>');
        $this->assertCredentialScope($result['events'], 'cargo-build', '<unset>', '<unset>');
        $this->assertCredentialScope($result['events'], 'worker', '<unset>', 'worker-secret');
        $this->assertRuntimeScopeAbsent($result['events'], 'resolver');
        $this->assertRuntimeScopeAbsent($result['events'], 'cargo-build');
        $this->assertRuntimeScope($result['events'], 'worker');
        $this->assertSame(0, $result['exitCode'], $result['output'].$result['errorOutput']);
    }

    public function test_run_propagates_workflow_failure_and_still_stops_the_worker_cleanly(): void
    {
        $result = $this->runCredentialProbe('run', failWorkflow: true);

        $this->assertCredentialScope($result['events'], 'dw-workflow', 'client-secret', '<unset>');
        $this->assertCredentialScope($result['events'], 'worker-signal', '<unset>', 'worker-secret');
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('workflow did not complete', $result['errorOutput']);
        $this->assertStringNotContainsString('Rust Cloud completed workflow_id=', $result['output']);
    }

    /**
     * @return array{
     *     exitCode: int,
     *     output: string,
     *     errorOutput: string,
     *     events: list<array{role: string, clientToken: string, workerToken: string, runtimeUrl: string, namespace: string, taskQueue: string}>
     * }
     */
    private function runCredentialProbe(string $mode, bool $failWorkflow = false): array
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/rust-cloud-credentials-'.bin2hex(random_bytes(6));
        $binaryDirectory = $temporaryDirectory.'/bin';
        $targetDirectory = $temporaryDirectory.'/target';
        $workerBinary = $targetDirectory.'/debug/durable-workflow-rust-cloud-quickstart';
        $environmentLog = $temporaryDirectory.'/environment.log';
        $evidenceRoot = $this->repoPath('storage/app/rust-cloud');
        $existingEvidence = glob($evidenceRoot.'/rust-cloud-*') ?: [];

        $filesystem->mkdir([$binaryDirectory, dirname($workerBinary)], 0700);
        $this->writeExecutable($binaryDirectory.'/node', <<<'BASH'
#!/usr/bin/env bash
printf 'resolver|%s|%s|%s|%s|%s\n' \
  "${DURABLE_WORKFLOW_CLIENT_TOKEN-<unset>}" \
  "${DURABLE_WORKFLOW_WORKER_TOKEN-<unset>}" \
  "${DURABLE_WORKFLOW_RUNTIME_URL-<unset>}" \
  "${DURABLE_WORKFLOW_RUNTIME_NAMESPACE-<unset>}" \
  "${DURABLE_WORKFLOW_TASK_QUEUE-<unset>}" >> "$RUST_CLOUD_ENV_LOG"
exec "$RUST_CLOUD_REAL_NODE" "$@"
BASH);
        $this->writeExecutable($binaryDirectory.'/cargo', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf 'cargo-%s|%s|%s|%s|%s|%s\n' \
  "${1:-missing}" \
  "${DURABLE_WORKFLOW_CLIENT_TOKEN-<unset>}" \
  "${DURABLE_WORKFLOW_WORKER_TOKEN-<unset>}" \
  "${DURABLE_WORKFLOW_RUNTIME_URL-<unset>}" \
  "${DURABLE_WORKFLOW_RUNTIME_NAMESPACE-<unset>}" \
  "${DURABLE_WORKFLOW_TASK_QUEUE-<unset>}" >> "$RUST_CLOUD_ENV_LOG"
if [[ "${1:-}" == "tree" ]]; then
  printf 'durable-workflow v%s\n' "${DURABLE_WORKFLOW_RUST_SDK_VERSION:?}"
elif [[ "${1:-}" != "build" ]]; then
  printf 'unexpected cargo command: %s\n' "$*" >&2
  exit 64
fi
BASH);
        $this->writeExecutable($binaryDirectory.'/dw', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
role='dw-workflow'
if [[ "${1:-}" == "--version" ]]; then
  role='dw-version'
fi
printf '%s|%s|%s|%s|%s|%s\n' \
  "$role" \
  "${DURABLE_WORKFLOW_CLIENT_TOKEN-<unset>}" \
  "${DURABLE_WORKFLOW_WORKER_TOKEN-<unset>}" \
  "${DURABLE_WORKFLOW_RUNTIME_URL-<unset>}" \
  "${DURABLE_WORKFLOW_RUNTIME_NAMESPACE-<unset>}" \
  "${DURABLE_WORKFLOW_TASK_QUEUE-<unset>}" >> "$RUST_CLOUD_ENV_LOG"
if [[ "$role" == "dw-version" ]]; then
  printf 'dw %s\n' "${DURABLE_WORKFLOW_CLI_VERSION:?}"
elif [[ "${RUST_CLOUD_PROBE_CLI_FAILURE:-0}" == "1" ]]; then
  printf '%s\n' 'controlled workflow failure' >&2
  exit 23
else
  printf '%s\n' '{"status":"completed"}'
fi
BASH);
        $this->writeExecutable($workerBinary, <<<'PYTHON'
#!/usr/bin/env python3
import os
import signal
import sys
import time

def record(role):
    values = [
        role,
        os.environ.get("DURABLE_WORKFLOW_CLIENT_TOKEN", "<unset>"),
        os.environ.get("DURABLE_WORKFLOW_WORKER_TOKEN", "<unset>"),
        os.environ.get("DURABLE_WORKFLOW_RUNTIME_URL", "<unset>"),
        os.environ.get("DURABLE_WORKFLOW_RUNTIME_NAMESPACE", "<unset>"),
        os.environ.get("DURABLE_WORKFLOW_TASK_QUEUE", "<unset>"),
    ]
    with open(os.environ["RUST_CLOUD_ENV_LOG"], "a", encoding="utf-8") as stream:
        stream.write("|".join(values) + "\n")

def shutdown(_signal, _frame):
    record("worker-signal")
    print("shutdown=clean", flush=True)
    sys.exit(0)

record("worker")
signal.signal(signal.SIGINT, shutdown)
signal.signal(signal.SIGTERM, shutdown)
if os.environ.get("RUST_CLOUD_PROBE_AUTO_EXIT") == "1":
    print("shutdown=clean", flush=True)
    sys.exit(0)
while True:
    time.sleep(0.1)
PYTHON);

        $realNode = (new Process(['bash', '-lc', 'command -v node']))->mustRun()->getOutput();
        $process = new Process(
            ['bash', $this->repoPath('scripts/rust-cloud.sh'), $mode],
            $this->repoPath(''),
            [
                'PATH' => $binaryDirectory.PATH_SEPARATOR.getenv('PATH'),
                'CARGO_TARGET_DIR' => $targetDirectory,
                'DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE' => $this->repoPath('tests/Fixtures/release-candidate-artifact-tuple.json'),
                'DURABLE_WORKFLOW_CLIENT_TOKEN' => 'client-secret',
                'DURABLE_WORKFLOW_CLI_VERSION' => $this->qualifiedArtifactVersion('cli'),
                'DURABLE_WORKFLOW_RUNTIME_NAMESPACE' => 'example',
                'DURABLE_WORKFLOW_RUNTIME_URL' => 'https://cloud.example/api/runtime/v1/namespaces/example',
                'DURABLE_WORKFLOW_RUST_SDK_VERSION' => $this->qualifiedArtifactVersion('sdk-rust'),
                'DURABLE_WORKFLOW_TASK_QUEUE' => 'credential-probe',
                'DURABLE_WORKFLOW_WORKER_TOKEN' => 'worker-secret',
                'RUST_CLOUD_ENV_LOG' => $environmentLog,
                'RUST_CLOUD_PROBE_AUTO_EXIT' => $mode === 'worker' ? '1' : '0',
                'RUST_CLOUD_PROBE_CLI_FAILURE' => $failWorkflow ? '1' : '0',
                'RUST_CLOUD_REAL_NODE' => trim($realNode),
            ],
        );
        $process->setTimeout(10);

        try {
            $process->run();
            $events = array_map(
                static function (string $line): array {
                    [$role, $clientToken, $workerToken, $runtimeUrl, $namespace, $taskQueue] = explode('|', $line);

                    return compact('role', 'clientToken', 'workerToken', 'runtimeUrl', 'namespace', 'taskQueue');
                },
                file($environmentLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            );

            return [
                'exitCode' => $process->getExitCode() ?? -1,
                'output' => $process->getOutput(),
                'errorOutput' => $process->getErrorOutput(),
                'events' => $events,
            ];
        } finally {
            $currentEvidence = glob($evidenceRoot.'/rust-cloud-*') ?: [];
            $filesystem->remove(array_values(array_diff($currentEvidence, $existingEvidence)));
            $filesystem->remove($temporaryDirectory);
        }
    }

    /**
     * @param  list<array{role: string, clientToken: string, workerToken: string, runtimeUrl: string, namespace: string, taskQueue: string}>  $events
     */
    private function assertCredentialScope(
        array $events,
        string $role,
        string $clientToken,
        string $workerToken,
    ): void {
        $event = $this->eventForRole($events, $role);

        $this->assertSame($clientToken, $event['clientToken'], $role.' client credential scope');
        $this->assertSame($workerToken, $event['workerToken'], $role.' worker credential scope');
    }

    /**
     * @param  list<array{role: string, clientToken: string, workerToken: string, runtimeUrl: string, namespace: string, taskQueue: string}>  $events
     */
    private function assertRuntimeScope(array $events, string $role): void
    {
        $event = $this->eventForRole($events, $role);

        $this->assertSame('https://cloud.example/api/runtime/v1/namespaces/example', $event['runtimeUrl']);
        $this->assertSame('example', $event['namespace']);
        $this->assertSame('credential-probe', $event['taskQueue']);
    }

    /**
     * @param  list<array{role: string, clientToken: string, workerToken: string, runtimeUrl: string, namespace: string, taskQueue: string}>  $events
     */
    private function assertRuntimeScopeAbsent(array $events, string $role): void
    {
        $event = $this->eventForRole($events, $role);

        $this->assertSame('<unset>', $event['runtimeUrl'], $role.' runtime URL scope');
        $this->assertSame('<unset>', $event['namespace'], $role.' namespace scope');
        $this->assertSame('<unset>', $event['taskQueue'], $role.' task queue scope');
    }

    /**
     * @param  list<array{role: string, clientToken: string, workerToken: string, runtimeUrl: string, namespace: string, taskQueue: string}>  $events
     * @return array{role: string, clientToken: string, workerToken: string, runtimeUrl: string, namespace: string, taskQueue: string}
     */
    private function eventForRole(array $events, string $role): array
    {
        foreach ($events as $event) {
            if ($event['role'] === $role) {
                return $event;
            }
        }

        $this->fail(sprintf('Did not observe child process role [%s].', $role));
    }

    private function writeExecutable(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        chmod($path, 0700);
    }

    private function qualifiedArtifactVersion(string $artifact): string
    {
        $tuple = json_decode(
            (string) file_get_contents($this->repoPath('polyglot/qualified-artifact-tuple.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $version = $tuple['artifacts'][$artifact] ?? null;
        $this->assertIsString($version);

        return $version;
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
