<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RustCloudOnboardingContractTest extends TestCase
{
    public function test_cloud_worker_resolves_the_exact_supported_sdk_release(): void
    {
        $manifest = (string) file_get_contents($this->repoPath('rust-cloud/Cargo.toml'));
        $lock = (string) file_get_contents($this->repoPath('rust-cloud/Cargo.lock'));
        $runner = (string) file_get_contents($this->repoPath('scripts/rust-cloud.sh'));

        preg_match('/^durable-workflow = "=(?<version>[^"]+)"$/m', $manifest, $match);

        $this->assertSame('2.0.0-rc.12', $match['version'] ?? null);
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

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
