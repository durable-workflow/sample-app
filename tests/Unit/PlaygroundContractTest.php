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
        $this->assertSame(4, $contract['schema_version'] ?? null);
        $this->assertSame(['php', 'python', 'rust'], $contract['choices'] ?? null);
        $this->assertSame('caller', $contract['source_ownership'] ?? null);
        $this->assertSame('polyglot/qualified-artifact-tuple.json', $contract['artifact_source'] ?? null);
        $this->assertSame('local', $contract['runtime']['default'] ?? null);
        $this->assertSame(['local', 'managed'], array_keys($contract['runtime']['targets'] ?? []));
        $this->assertTrue($contract['runtime']['targets']['local']['isolated_state_per_journey'] ?? false);
        $this->assertFalse($contract['runtime']['targets']['local']['requires_external_access'] ?? true);
        $this->assertSame(
            ['--runtime-url', '--namespace', '--task-queue'],
            $contract['runtime']['targets']['managed']['required_arguments'] ?? null,
        );
        $this->assertSame(
            [
                'worker' => 'DURABLE_WORKFLOW_WORKER_TOKEN',
                'client' => 'DURABLE_WORKFLOW_CLIENT_TOKEN',
            ],
            $contract['runtime']['targets']['managed']['credential_roles'] ?? null,
        );
        $this->assertTrue($contract['proof']['requires_worker_registration'] ?? false);
        $this->assertSame(
            ['requirement' => 'required'],
            $contract['proof']['runtime']['local']['selected_waterline_run'] ?? null,
        );
        $this->assertSame(
            [
                'requirement' => 'omitted',
                'reason' => 'managed-runtime-waterline-not-provisioned',
            ],
            $contract['proof']['runtime']['managed']['selected_waterline_run'] ?? null,
        );

        foreach ($contract['choices'] as $language) {
            $scenario = $contract['scenarios'][$language] ?? [];
            $this->assertStringStartsWith("sample-app.playground.{$language}.", $scenario['workflow_type'] ?? '');
            $this->assertStringStartsWith("sample-app.playground.{$language}.", $scenario['activity_type'] ?? '');
            $this->assertStringStartsWith("sample-app-playground-{$language}-", $scenario['task_queue'] ?? '');
            $this->assertNotEmpty($scenario['worker_command'] ?? []);
            $this->assertNotEmpty($scenario['start_command'] ?? []);
            $this->assertIsArray($scenario['input'] ?? null);
            $this->assertSame($scenario['input'], $scenario['expected_result']['input'] ?? null);
            $this->assertSame($language, $scenario['expected_result']['workflow_runtime'] ?? null);
            $this->assertSame($language, $scenario['expected_result']['activity_runtime'] ?? null);
        }

        $this->assertSame('laravel-bridge', $contract['scenarios']['php']['integration'] ?? null);
        $phpWorker = file_get_contents($this->path('playground/templates/php/worker.php')) ?: '';
        $this->assertStringContainsString('ProcessCredentialResolver::workerClient', $phpWorker);
        $this->assertStringContainsString("getenv('DURABLE_WORKFLOW_WORKER_ID')", $phpWorker);
        $this->assertStringContainsString('workerId: $workerId', $phpWorker);
        $services = $compose['services'] ?? [];
        $this->assertSame(
            '${DURABLE_SERVER_IMAGE:?resolve the published artifact tuple first}',
            $services['server']['image'] ?? null,
        );
        $this->assertArrayNotHasKey('build', $services['server'] ?? []);
        $this->assertArrayNotHasKey('build', $services['waterline'] ?? []);

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
        $this->assertSame(['/var/lib/mysql'], $services['mysql']['tmpfs'] ?? null);
        $this->assertArrayNotHasKey('mysql-data', $compose['volumes'] ?? []);
        $this->assertSame(
            [
                'CMD-SHELL',
                'healthcheck.sh --connect --innodb_initialized && mariadb --protocol=tcp --host=127.0.0.1 --user="$${MYSQL_USER}" --password="$${MYSQL_PASSWORD}" --database="$${MYSQL_DATABASE}" --batch --skip-column-names --execute=\'SELECT 1\' >/dev/null',
            ],
            $services['mysql']['healthcheck']['test'] ?? null,
        );
        $this->assertSame('10s', $services['mysql']['healthcheck']['start_period'] ?? null);
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

    public function test_generated_php_worker_resolves_handlers_and_registers_the_invocation_identity(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-playground-php-worker-'.bin2hex(random_bytes(8));
        $sourceDirectory = $temporaryDirectory.'/source';
        $registrationLog = $temporaryDirectory.'/registrations.jsonl';
        $router = $temporaryDirectory.'/runtime.php';
        $server = null;
        $filesystem->mkdir($sourceDirectory);

        try {
            (new Process([
                $this->path('scripts/playground'),
                'scaffold',
                'php',
                '--source',
                $sourceDirectory,
            ]))->mustRun();

            file_put_contents($router, <<<'PHP'
<?php

declare(strict_types=1);

$request = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'body' => json_decode(file_get_contents('php://input') ?: 'null', true),
];
file_put_contents(
    (string) getenv('PLAYGROUND_REGISTRATION_LOG'),
    json_encode($request, JSON_THROW_ON_ERROR).PHP_EOL,
    FILE_APPEND,
);

header('Content-Type: application/json');
if ($request['path'] === '/api/worker/register') {
    echo json_encode(['registered' => true, 'heartbeat_interval_seconds' => 30], JSON_THROW_ON_ERROR);

    return;
}
if ($request['path'] === '/api/worker/workflow-tasks/poll') {
    echo json_encode(
        ['task' => null, 'poll_status' => 'stopped', 'reason' => 'worker_stopped'],
        JSON_THROW_ON_ERROR,
    );

    return;
}

http_response_code(404);
echo json_encode(['message' => 'unexpected test runtime request'], JSON_THROW_ON_ERROR);
PHP);

            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
            if (! is_resource($socket)) {
                self::fail("Unable to reserve the PHP test runtime port: {$errorCode} {$errorMessage}");
            }
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            self::assertIsString($address);

            $server = new Process(
                [PHP_BINARY, '-S', $address, $router],
                $temporaryDirectory,
                env: [
                    ...getenv(),
                    'PLAYGROUND_REGISTRATION_LOG' => $registrationLog,
                ],
            );
            $server->start();
            $ready = false;
            $port = (int) substr(strrchr($address, ':') ?: '', 1);
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $connection = @fsockopen('127.0.0.1', $port, timeout: 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(10_000);
            }
            self::assertTrue($ready, $server->getErrorOutput());

            $scenario = $this->contract()['scenarios']['php'];
            $worker = new Process(
                [PHP_BINARY, $sourceDirectory.'/worker.php'],
                $sourceDirectory,
                env: [
                    ...getenv(),
                    'DURABLE_WORKFLOW_NAMESPACE' => 'generated-worker-test',
                    'DURABLE_WORKFLOW_RUNTIME_URL' => 'http://'.$address,
                    'DURABLE_WORKFLOW_TASK_QUEUE' => 'generated-php-worker',
                    'DURABLE_WORKFLOW_WORKER_ID' => 'generated-php-worker-current-invocation',
                    'DURABLE_WORKFLOW_WORKER_TOKEN' => 'worker-role-secret',
                    'SAMPLE_APP_PLAYGROUND_SCENARIO' => json_encode($scenario, JSON_THROW_ON_ERROR),
                    'SAMPLE_APP_ROOT' => $this->path(''),
                ],
            );
            $worker->setTimeout(20);
            $worker->mustRun();

            self::assertFileExists(
                $registrationLog,
                'Generated PHP worker never reached registration: '
                    .$worker->getOutput().$worker->getErrorOutput(),
            );

            $requests = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($registrationLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            );
            $registration = array_values(array_filter(
                $requests,
                static fn (array $request): bool => $request['path'] === '/api/worker/register',
            ));
            self::assertCount(1, $registration, json_encode($requests, JSON_THROW_ON_ERROR));
            self::assertSame(
                'generated-php-worker-current-invocation',
                $registration[0]['body']['worker_id'] ?? null,
            );
            self::assertSame(
                [$scenario['workflow_type']],
                $registration[0]['body']['supported_workflow_types'] ?? null,
            );
            self::assertSame(
                [$scenario['activity_type']],
                $registration[0]['body']['supported_activity_types'] ?? null,
            );
        } finally {
            $server?->stop();
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

    public function test_worker_readiness_requires_the_current_invocation_registration(): void
    {
        $harness = <<<'PYTHON'
import runpy
import sys

playground = runpy.run_path(sys.argv[1])
wait_for_registration = playground["wait_for_registration"]
PlaygroundError = playground["PlaygroundError"]
globals = wait_for_registration.__globals__

class FakeWorker:
    returncode = None

    def poll(self):
        return None

stale = {
    "worker_id": "stale-worker",
    "supported_workflow_types": ["authored-workflow"],
    "supported_activity_types": ["authored-activity"],
}
current = {**stale, "worker_id": "current-worker"}

def wait_with(workers, *, expire):
    clock = {"now": 0}
    globals["json_command"] = lambda command, *, env, timeout=30: {"workers": workers}
    globals["time"].monotonic = lambda: clock["now"]
    globals["time"].sleep = lambda seconds: clock.update(now=61 if expire else seconds)
    return wait_for_registration(
        "shared-queue",
        "current-worker",
        "authored-workflow",
        "authored-activity",
        "https://runtime.example/namespaces/example",
        "example",
        "client-secret",
        FakeWorker(),
    )

try:
    wait_with([stale], expire=True)
except PlaygroundError as error:
    assert "current-worker" in str(error)
else:
    raise AssertionError("A stale matching registration satisfied readiness")

roster = wait_with([stale, current], expire=False)
assert [worker["worker_id"] for worker in roster["workers"]] == [
    "stale-worker",
    "current-worker",
]
print("invocation-registration-proof=ready")
PYTHON;

        $process = new Process([
            'python3',
            '-c',
            $harness,
            $this->path('scripts/playground'),
        ]);
        $process->mustRun();

        $this->assertStringContainsString(
            'invocation-registration-proof=ready',
            $process->getOutput(),
        );
    }

    public function test_managed_runtime_keeps_worker_and_client_roles_isolated_for_every_sdk(): void
    {
        $filesystem = new Filesystem;
        $temporaryDirectory = sys_get_temp_dir().'/sample-app-playground-managed-'.bin2hex(random_bytes(8));
        $filesystem->mkdir($temporaryDirectory);
        $harness = <<<'PYTHON'
import json
import runpy
import sys
from pathlib import Path
from types import SimpleNamespace

playground = runpy.run_path(sys.argv[1])
journey = playground["journey"]
events = []
active = {}

class FakeWorker:
    returncode = None

    def poll(self):
        return None

def fake_popen(command, **kwargs):
    environment = kwargs["env"]
    active.update(
        worker_id=environment["DURABLE_WORKFLOW_WORKER_ID"],
        scenario=json.loads(environment["SAMPLE_APP_PLAYGROUND_SCENARIO"]),
    )
    events.append({"role": "worker", "env": environment})
    return FakeWorker()

def fake_run(command, *, env=None, cwd=None, capture=False, timeout=None):
    assert env is not None
    if "DURABLE_WORKFLOW_CLIENT_TOKEN" in env:
        events.append({"role": "client", "env": env})
        scenario = json.loads(env["SAMPLE_APP_PLAYGROUND_SCENARIO"])
        output = json.dumps({
            "workflow_id": scenario["workflow_id_prefix"] + "-managed-input-proof",
            "run_id": "managed-run",
            "result": scenario["expected_result"],
        })
    else:
        events.append({"role": "worker-test", "env": env})
        output = ""
    return SimpleNamespace(stdout=output, stderr="", returncode=0)

def fake_json_command(command, *, env, timeout=30):
    events.append({"role": "control", "env": env})
    if command[1] == "worker:list":
        scenario = active["scenario"]
        registration = {
            "supported_workflow_types": [scenario["workflow_type"]],
            "supported_activity_types": [scenario["activity_type"]],
        }
        return {"workers": [
            {**registration, "worker_id": "stale-matching-worker"},
            {**registration, "worker_id": active["worker_id"]},
        ]}
    if command[1] == "workflow:describe":
        return {"status": "completed", "run_id": "managed-run"}
    if command[1] == "workflow:history":
        return {"events": [
            {"event_type": "WorkflowStarted"},
            {"event_type": "ActivityScheduled"},
            {"event_type": "ActivityCompleted"},
            {"event_type": "WorkflowCompleted"},
        ]}
    raise AssertionError(command)

globals = journey.__globals__
globals["doctor"] = lambda *, require_docker=True: (
    (_ for _ in ()).throw(AssertionError("managed mode required Docker"))
    if require_docker else {"dw": "prepared"}
)
globals["resolve_artifacts"] = lambda: {
    "DURABLE_SERVER_IMAGE": "unused-local-server",
    "DURABLE_WORKFLOW_PHP_SDK_VERSION": "prepared",
    "DURABLE_WORKFLOW_PYTHON_SDK_VERSION": "prepared",
    "DURABLE_WORKFLOW_RUST_SDK_VERSION": "prepared",
    "DURABLE_WORKFLOW_WATERLINE_IMAGE": "unused-local-waterline",
}
def fake_scaffold(language, source, scenario=None):
    authored = source / "authored-source"
    authored.write_text(language)
    return [authored]

globals["scaffold"] = fake_scaffold
globals["start_services"] = lambda *args, **kwargs: (_ for _ in ()).throw(
    AssertionError("managed mode started local services")
)
globals["fetch_json"] = lambda *args, **kwargs: (_ for _ in ()).throw(
    AssertionError("managed mode fetched local Waterline")
)
globals["run"] = fake_run
globals["json_command"] = fake_json_command
globals["terminate_worker"] = lambda worker: None
globals["subprocess"].Popen = fake_popen

root = Path(sys.argv[2])
summaries = []
evidence_paths = []
for language in ("php", "python", "rust"):
    active.clear()
    source = root / language
    source.mkdir()
    evidence_path = root / f"{language}.json"
    payload = journey(
        language,
        source,
        evidence_path,
        runtime="managed",
        runtime_url="https://runtime.example/namespaces/example",
        namespace="example",
        task_queue=f"sample-app-playground-{language}-managed",
    )
    assert payload["runtime"]["target"] == "managed"
    assert payload["workflow"]["result"]["input"] == payload["effective_contract"]["input"]
    assert payload["workflow"]["worker_id"] == active["worker_id"]
    assert payload["waterline"] == {
        "status": "omitted",
        "reason": "managed-runtime-waterline-not-provisioned",
    }
    summaries.append(payload["language"])
    evidence_paths.append(evidence_path)

validator = runpy.run_path(sys.argv[3])
contract = json.loads(Path(sys.argv[4]).read_text())
for evidence_path in evidence_paths:
    validator["validate"](evidence_path, contract)

ambiguous_path = root / "ambiguous-managed.json"
ambiguous = json.loads(evidence_paths[0].read_text())
ambiguous["waterline"] = {
    "url": None,
    "selection": {"instance_id": None, "selected_run_id": None},
}
ambiguous_path.write_text(json.dumps(ambiguous))
try:
    validator["validate"](ambiguous_path, contract)
except SystemExit as error:
    assert "authoritatively explain omitted Waterline proof" in str(error)
else:
    raise AssertionError("Ambiguous managed Waterline proof passed validation")

for event in events:
    environment = event["env"]
    for bridge_variable in (
        "DURABLE_WORKFLOW_TOKEN",
        "DURABLE_WORKFLOW_PROCESS_ROLE",
        "DURABLE_WORKFLOW_PROCESS_TOKEN",
    ):
        assert bridge_variable not in environment
    if event["role"] in {"worker", "worker-test"}:
        assert environment["DURABLE_WORKFLOW_WORKER_TOKEN"] == "worker-secret"
        assert "DURABLE_WORKFLOW_CLIENT_TOKEN" not in environment
        assert "DURABLE_WORKFLOW_CONTROL_TOKEN" not in environment
    else:
        assert environment["DURABLE_WORKFLOW_CLIENT_TOKEN"] == "client-secret"
        assert "DURABLE_WORKFLOW_WORKER_TOKEN" not in environment

print("managed-harness=" + json.dumps(summaries))
PYTHON;

        try {
            $process = new Process(
                [
                    'python3',
                    '-c',
                    $harness,
                    $this->path('scripts/playground'),
                    $temporaryDirectory,
                    $this->path('scripts/ci/validate-playground-evidence.py'),
                    $this->path('playground/contract.json'),
                ],
                env: [
                    ...getenv(),
                    'DURABLE_WORKFLOW_CLIENT_TOKEN' => 'client-secret',
                    'DURABLE_WORKFLOW_PROCESS_ROLE' => 'shared',
                    'DURABLE_WORKFLOW_PROCESS_TOKEN' => 'ambient-process-secret',
                    'DURABLE_WORKFLOW_TOKEN' => 'ambient-shared-secret',
                    'DURABLE_WORKFLOW_WORKER_TOKEN' => 'worker-secret',
                ],
            );
            $process->mustRun();

            $output = $process->getOutput();
            $this->assertStringContainsString('managed-harness=["php", "python", "rust"]', $output);
            $this->assertSame(3, substr_count($output, 'Worker ready: target=managed'));
            $this->assertSame(3, substr_count($output, '"runtime_target":"managed"'));
            $this->assertStringNotContainsString('worker-secret', $output);
            $this->assertStringNotContainsString('client-secret', $output);
        } finally {
            $filesystem->remove($temporaryDirectory);
        }
    }

    public function test_managed_runtime_requires_the_complete_explicit_contract(): void
    {
        $process = new Process(
            [$this->path('scripts/playground'), 'rust', '--runtime', 'managed'],
            env: [
                ...getenv(),
                'DURABLE_WORKFLOW_CLIENT_TOKEN' => 'client-secret',
                'DURABLE_WORKFLOW_WORKER_TOKEN' => 'worker-secret',
            ],
        );
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            'Managed runtime mode requires explicit --runtime-url, --namespace, --task-queue.',
            $process->getErrorOutput(),
        );
    }

    public function test_retired_top_level_scaffold_and_entry_point_cannot_return(): void
    {
        $retiredName = 'rust'.'-cloud';
        $retiredScript = 'scripts/'.$retiredName.'.sh';

        $this->assertDirectoryDoesNotExist($this->path($retiredName));
        $this->assertFileDoesNotExist($this->path($retiredScript));

        $tracked = (new Process(['git', 'ls-files', '-z'], $this->path('')))->mustRun()->getOutput();
        $staleReferences = [];
        foreach (array_filter(explode("\0", $tracked)) as $relativePath) {
            $path = $this->path($relativePath);
            if (! is_file($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if (is_string($contents) && str_contains($contents, $retiredName)) {
                $staleReferences[] = $relativePath;
            }
        }

        $this->assertSame([], $staleReferences, 'Retired scaffold references: '.implode(', ', $staleReferences));
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
                'package' => ['published_version' => '2.0.0-rc.47'],
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
    printf '{"versions":["2.0.0-rc.47"]}\n'
fi
BASH);

        $fakeCommands = [
            'php' => 'printf "8.4.0\\n"',
            'python' => <<<'BASH'
if [[ " $* " == *" --version "* ]]; then
    printf 'Python 3.11.0\n'
elif [[ " $* " == *" importlib.metadata "* ]]; then
    printf '2.0.0rc36\n'
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
    "DURABLE_SERVER_IMAGE": "durableworkflow/server:2.0.0-rc.50",
    "DURABLE_WORKFLOW_PHP_SDK_VERSION": "2.0.0-rc.47",
    "DURABLE_WORKFLOW_PYTHON_SDK_VERSION": "2.0.0-rc.36",
    "DURABLE_WORKFLOW_RUST_SDK_VERSION": "2.0.0-rc.34",
}
doctor.__globals__["php_runtime"] = lambda: Path(sys.argv[2])
versions = doctor()
assert versions["sdk_php_laravel"] == "2.0.0-rc.47", json.dumps(versions)
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
