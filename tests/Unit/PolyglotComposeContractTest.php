<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class PolyglotComposeContractTest extends TestCase
{
    public function test_stable_artifact_tuple_drives_every_public_runtime(): void
    {
        $tuple = json_decode(
            (string) file_get_contents($this->path('polyglot/qualified-artifact-tuple.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('durable-workflow.sample-app.polyglot-qualified-artifact-tuple', $tuple['schema']);
        $this->assertSame(1, $tuple['schemaVersion']);
        $this->assertSame(
            ['cli', 'sdk-php', 'sdk-python', 'sdk-rust', 'server', 'waterline', 'workflow'],
            array_keys($tuple['artifacts']),
        );

        foreach ($tuple['artifacts'] as $version) {
            $this->assertMatchesRegularExpression('/^2\.\d+\.\d+$/', $version);
        }

        $assignments = $this->resolveArtifacts();
        $this->assertSame('durableworkflow/server:'.$tuple['artifacts']['server'], $assignments['DURABLE_SERVER_IMAGE']);
        $this->assertSame($tuple['artifacts']['cli'], $assignments['DURABLE_WORKFLOW_CLI_VERSION']);
        $this->assertSame($tuple['artifacts']['sdk-php'], $assignments['DURABLE_WORKFLOW_PHP_SDK_VERSION']);
        $this->assertSame($tuple['artifacts']['sdk-python'], $assignments['DURABLE_WORKFLOW_PYTHON_SDK_VERSION']);
        $this->assertSame($tuple['artifacts']['sdk-rust'], $assignments['DURABLE_WORKFLOW_RUST_SDK_VERSION']);
        $this->assertSame($tuple['artifacts']['workflow'], $assignments['DURABLE_WORKFLOW_WORKFLOW_VERSION']);
        $this->assertSame($tuple['artifacts']['waterline'], $assignments['DURABLE_WORKFLOW_WATERLINE_VERSION']);
        $this->assertSame(
            'durable-workflow/sdk:'.$tuple['artifacts']['sdk-php'],
            $assignments['DURABLE_WORKFLOW_PHP_SDK_PIN'],
        );
    }

    public function test_artifact_resolver_accepts_stable_overrides_and_rejects_prereleases(): void
    {
        $assignments = $this->resolveArtifacts([
            'SAMPLE_APP_RUST_SDK_VERSION' => '2.3.4',
            'SAMPLE_APP_PHP_SDK_PIN' => 'durable-workflow/sdk:2.4.5',
        ]);

        $this->assertSame('2.3.4', $assignments['DURABLE_WORKFLOW_RUST_SDK_VERSION']);
        $this->assertSame('2.4.5', $assignments['DURABLE_WORKFLOW_PHP_SDK_VERSION']);
        $this->assertSame('durable-workflow/sdk:2.4.5', $assignments['DURABLE_WORKFLOW_PHP_SDK_PIN']);

        $process = new Process(
            [$this->path('scripts/resolve-current-artifacts.sh')],
            env: ['SAMPLE_APP_RUST_SDK_VERSION' => '2.0.0-rc.99'],
        );
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('must be a stable 2.x version', $process->getErrorOutput());
    }

    public function test_polyglot_compose_uses_isolated_runtime_services_and_resolved_artifacts(): void
    {
        $compose = Yaml::parseFile($this->path('polyglot/docker-compose.yml'));
        $services = $compose['services'];

        $this->assertArrayNotHasKey('name', $compose);
        $this->assertArrayNotHasKey('ports', $services['server']);
        $this->assertSame(['8080'], $services['server']['expose']);

        foreach (['mysql', 'redis', 'bootstrap', 'server', 'polyglot-workflow-worker', 'python-activity-worker', 'rust-activity-worker', 'demo'] as $service) {
            $this->assertArrayHasKey($service, $services);
        }

        $this->assertSame(
            '${DURABLE_SERVER_IMAGE:?run ../scripts/resolve-current-artifacts.sh before starting polyglot compose}',
            $services['server']['image'],
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_PYTHON_SDK_VERSION:?run ../scripts/resolve-current-artifacts.sh before starting polyglot compose}',
            $services['python-activity-worker']['build']['args']['DURABLE_WORKFLOW_PYTHON_SDK_VERSION'],
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_RUST_SDK_VERSION:?run ../scripts/resolve-current-artifacts.sh before starting polyglot compose}',
            $services['rust-activity-worker']['build']['args']['DURABLE_WORKFLOW_RUST_SDK_VERSION'],
        );
        $this->assertSame(
            '${DURABLE_WORKFLOW_PHP_SDK_VERSION:?run ../scripts/resolve-current-artifacts.sh before starting polyglot compose}',
            $services['polyglot-workflow-worker']['build']['args']['DURABLE_WORKFLOW_PHP_SDK_VERSION'],
        );
    }

    public function test_featured_workflow_routes_php_to_python_and_rust(): void
    {
        $worker = (string) file_get_contents($this->path('polyglot/php_worker/worker.php'));
        $compose = Yaml::parseFile($this->path('polyglot/docker-compose.yml'));
        $environment = $compose['services']['polyglot-workflow-worker']['environment'];

        $this->assertStringContainsString("'polyglot.PolyglotWorkflow'", $worker);
        $this->assertStringContainsString("'polyglot.php-to-python.tally'", $worker);
        $this->assertStringContainsString("'polyglot.php-to-rust.receipt'", $worker);
        $this->assertSame('polyglot-workflow', $environment['POLYGLOT_WORKFLOW_TASK_QUEUE']);
        $this->assertSame('polyglot-php-to-python', $environment['POLYGLOT_PHP2PY_TASK_QUEUE']);
        $this->assertSame('polyglot-to-rust', $environment['POLYGLOT_TO_RUST_TASK_QUEUE']);
    }

    public function test_documented_polyglot_command_runs_one_service_mode_journey(): void
    {
        $script = (string) file_get_contents($this->path('scripts/polyglot.sh'));
        $workflow = Yaml::parseFile($this->path('.github/workflows/polyglot-validation.yml'));
        $steps = $workflow['jobs']['smoke']['steps'];

        $this->assertStringContainsString('scripts/resolve-current-artifacts.sh', $script);
        $this->assertStringContainsString('polyglot-workflow-worker', $script);
        $this->assertStringContainsString('python-activity-worker', $script);
        $this->assertStringContainsString('rust-activity-worker', $script);
        $this->assertStringContainsString('pull --policy always bootstrap server', $script);
        $this->assertStringContainsString('down --volumes --remove-orphans', $script);
        $this->assertStringContainsString('run --rm --no-deps demo', $script);
        $this->assertStringNotContainsString('docs-page-release-audit', $script);

        $commands = array_column($steps, 'run');
        $this->assertContains('scripts/polyglot.sh', $commands);
        $this->assertContains('scripts/polyglot.sh down', $commands);
        $this->assertArrayNotHasKey('strategy', $workflow['jobs']['smoke']);
    }

    /** @param array<string, string> $environment */
    private function resolveArtifacts(array $environment = []): array
    {
        $process = new Process(
            [$this->path('scripts/resolve-current-artifacts.sh')],
            env: $environment,
        );
        $process->mustRun();

        $assignments = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            [$name, $value] = explode('=', $line, 2);
            $assignments[$name] = $value;
        }

        return $assignments;
    }

    private function path(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
