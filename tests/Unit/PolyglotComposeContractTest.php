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

        foreach ($tuple['artifacts'] as $artifact => $version) {
            $pattern = match ($artifact) {
                'sdk-rust' => '/^[234]\.\d+\.\d+$/',
                'sdk-php' => '/^[23]\.\d+\.\d+$/',
                'server' => '/^3\.\d+\.\d+$/',
                default => '/^2\.\d+\.\d+$/',
            };
            $this->assertMatchesRegularExpression($pattern, $version);
        }

        // Native Server 3.x embeds no Workflow package; the tuple pins its
        // multi-architecture image index by digest instead.
        $this->assertArrayNotHasKey('serverComponents', $tuple);
        $this->assertMatchesRegularExpression(
            '/^durableworkflow\/server:'.preg_quote($tuple['artifacts']['server'], '/').'@sha256:[0-9a-f]{64}$/',
            $tuple['serverImage'],
        );

        $assignments = $this->resolveArtifacts();
        $this->assertSame($tuple['serverImage'], $assignments['DURABLE_SERVER_IMAGE']);
        $this->assertSame($tuple['artifacts']['server'], $assignments['DURABLE_SERVER_VERSION']);
        $this->assertArrayNotHasKey('DURABLE_SERVER_WORKFLOW_VERSION', $assignments);
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
            'SAMPLE_APP_RUST_SDK_VERSION' => '3.2.0',
            'SAMPLE_APP_PHP_SDK_PIN' => 'durable-workflow/sdk:2.4.5',
        ]);

        $this->assertSame('3.2.0', $assignments['DURABLE_WORKFLOW_RUST_SDK_VERSION']);
        $this->assertSame('2.4.5', $assignments['DURABLE_WORKFLOW_PHP_SDK_VERSION']);
        $this->assertSame('durable-workflow/sdk:2.4.5', $assignments['DURABLE_WORKFLOW_PHP_SDK_PIN']);

        $process = new Process(
            [$this->path('scripts/resolve-current-artifacts.sh')],
            env: ['SAMPLE_APP_RUST_SDK_VERSION' => '2.0.0-rc.99'],
        );
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('must be a stable 2.x, 3.x or 4.x version', $process->getErrorOutput());
    }

    public function test_current_sdk_majors_resolve_without_changing_frozen_selections(): void
    {
        $current = $this->resolveArtifacts([
            'SAMPLE_APP_RUST_SDK_VERSION' => '4.0.0',
            'SAMPLE_APP_PHP_SDK_PIN' => 'durable-workflow/sdk:3.0.0',
        ]);

        $this->assertSame('4.0.0', $current['DURABLE_WORKFLOW_RUST_SDK_VERSION']);
        $this->assertSame('3.0.0', $current['DURABLE_WORKFLOW_PHP_SDK_VERSION']);
        $this->assertSame('durable-workflow/sdk:3.0.0', $current['DURABLE_WORKFLOW_PHP_SDK_PIN']);

        $tuple = json_decode((string) file_get_contents($this->path('polyglot/qualified-artifact-tuple.json')), true, flags: JSON_THROW_ON_ERROR);
        $tuple['artifacts']['sdk-php'] = '3.0.0';
        $tuple['artifacts']['sdk-rust'] = '4.0.0';
        $tuplePath = tempnam(sys_get_temp_dir(), 'dw-current-sdk-tuple-');
        try {
            file_put_contents($tuplePath, json_encode($tuple, JSON_THROW_ON_ERROR));
            $resolved = $this->resolveArtifacts(['DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE' => $tuplePath]);
            $this->assertSame('3.0.0', $resolved['DURABLE_WORKFLOW_PHP_SDK_VERSION']);
            $this->assertSame('4.0.0', $resolved['DURABLE_WORKFLOW_RUST_SDK_VERSION']);
        } finally {
            unlink($tuplePath);
        }

        foreach ([
            ['SAMPLE_APP_RUST_SDK_VERSION' => '5.0.0'],
            ['SAMPLE_APP_PHP_SDK_VERSION' => '4.0.0'],
            ['SAMPLE_APP_PHP_SDK_VERSION' => '3.0.0-rc.1'],
        ] as $environment) {
            $process = new Process([$this->path('scripts/resolve-current-artifacts.sh')], env: $environment);
            $process->run();
            $this->assertFalse($process->isSuccessful());
        }
    }

    public function test_server_selection_requires_the_native_server(): void
    {
        $tag = $this->resolveArtifacts(['SAMPLE_APP_SERVER_VERSION' => '3.0.9']);
        $this->assertSame('durableworkflow/server:3.0.9', $tag['DURABLE_SERVER_IMAGE']);
        $this->assertSame('3.0.9', $tag['DURABLE_SERVER_VERSION']);

        $image = $this->resolveArtifacts(['SAMPLE_APP_SERVER_IMAGE' => 'ghcr.io/durable-workflow/server:3.1.0']);
        $this->assertSame('ghcr.io/durable-workflow/server:3.1.0', $image['DURABLE_SERVER_IMAGE']);
        $this->assertSame('3.1.0', $image['DURABLE_SERVER_VERSION']);

        foreach ([
            ['SAMPLE_APP_SERVER_VERSION' => '2.11.8'],
            ['SAMPLE_APP_SERVER_IMAGE' => 'durableworkflow/server:2.11.8'],
        ] as $environment) {
            $process = new Process([$this->path('scripts/resolve-current-artifacts.sh')], env: $environment);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('must be a stable native 3.x version', $process->getErrorOutput());
        }

        $tuple = json_decode((string) file_get_contents($this->path('polyglot/qualified-artifact-tuple.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ([
            fn (array $tuple): array => [...$tuple, 'serverComponents' => ['workflow' => '2.17.1']],
            fn (array $tuple): array => [...$tuple, 'serverImage' => 'durableworkflow/server:3.0.2'],
            fn (array $tuple): array => [...$tuple, 'serverImage' => 'durableworkflow/server:3.0.1@sha256:'.str_repeat('a', 64)],
        ] as $mutate) {
            $path = tempnam(sys_get_temp_dir(), 'dw-native-tuple-');
            try {
                file_put_contents($path, json_encode($mutate($tuple), JSON_THROW_ON_ERROR));
                $process = new Process(
                    [$this->path('scripts/resolve-current-artifacts.sh')],
                    env: ['DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE' => $path],
                );
                $process->run();
                $this->assertFalse($process->isSuccessful());
            } finally {
                unlink($path);
            }
        }
    }

    public function test_polyglot_compose_uses_isolated_runtime_services_and_resolved_artifacts(): void
    {
        $compose = Yaml::parseFile($this->path('polyglot/docker-compose.yml'));
        $services = $compose['services'];

        $this->assertArrayNotHasKey('name', $compose);
        $this->assertArrayNotHasKey('ports', $services['server']);
        $this->assertSame(['8080'], $services['server']['expose']);

        foreach (['mysql', 'server', 'polyglot-workflow-worker', 'python-activity-worker', 'rust-activity-worker', 'demo'] as $service) {
            $this->assertArrayHasKey($service, $services);
        }

        // Native Server 3.x is one process with in-process maintenance.
        foreach (['bootstrap', 'scheduler', 'timer-queue'] as $service) {
            $this->assertArrayNotHasKey($service, $services);
        }
        $this->assertArrayNotHasKey('command', $services['server']);
        $this->assertSame(['mysql'], array_keys($services['server']['depends_on']));
        $this->assertSame('token', $services['server']['environment']['DW_AUTH_DRIVER']);
        $this->assertSame('test-token', $services['server']['environment']['DW_AUTH_TOKEN']);
        $this->assertSame('bash', $services['server']['healthcheck']['test'][1]);
        $this->assertStringContainsString('/api/ready', $services['server']['healthcheck']['test'][3]);
        $this->assertSame('service', $services['waterline']['environment']['WATERLINE_BACKEND']);

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
        $this->assertStringContainsString('pull --policy always server', $script);
        $this->assertStringNotContainsString('bootstrap', $script);
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
