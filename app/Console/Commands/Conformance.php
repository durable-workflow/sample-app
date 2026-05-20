<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class Conformance extends Command
{
    private const SANDBOX_PROCESS_TIMEOUT_SECONDS = 300;

    private const DOCUMENTED_MCP_TOOLS = [
        'list_workflows',
        'start_workflow',
        'get_workflow_result',
        'get_workflow_history',
        'diagnose_workflow',
    ];

    private const DOCUMENTED_WORKFLOW_KEYS = [
        'simple',
        'elapsed',
        'microservice',
        'playwright',
        'webhook',
        'prism',
        'ai',
        'sandbox',
        'polyglot_php_to_python',
    ];

    private const REQUIRED_SURFACES = [
        'deterministic_simple',
        'deterministic_elapsed',
        'deterministic_microservice',
        'api_webhook',
        'laravel_health',
        'mcp_workflow_api',
        'api_documentation',
        'browser_welcome',
        'browser_waterline',
        'waterline_operator_dashboard',
        'sandbox_default',
        'sandbox_snapshot',
        'sandbox_suspend_resume',
        'sandbox_recovery_injection',
        'prism_ai',
        'ai_agent_scripted',
        'ai_failure_hotel',
        'ai_failure_flight',
        'ai_failure_car',
    ];

    protected $signature = 'app:conformance
        {--strict : Return non-zero when credential-dependent surfaces are skipped}
        {--allow-skips : Return zero when credential-dependent surfaces are skipped}
        {--skip-ai : Skip OpenAI-backed samples even when OPENAI_API_KEY is set}
        {--app-url= : Base URL for HTTP, MCP, Waterline, and browser samples}
        {--output= : Also write the JSON run metadata to this path}';

    protected $description = 'Run the public sample-app conformance harness across documented workflow surfaces';

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $surfaces = [];

    public function handle(): int
    {
        $startedAt = gmdate('c');
        $baseUrl = $this->baseUrl();
        $strict = (bool) $this->option('strict') || ! (bool) $this->option('allow-skips');

        $this->line('==> sample-app conformance: documented deterministic samples');
        $this->runProcessSurface('deterministic_simple', ['php', 'artisan', 'app:workflow'], '/workflow_activity_other/');
        $this->runProcessSurface('deterministic_elapsed', ['php', 'artisan', 'app:elapsed'], '/Elapsed Time: [0-9]+ seconds/');
        $this->runProcessSurface('deterministic_microservice', ['php', 'artisan', 'app:microservice'], '/workflow_activity_other/');
        $this->runProcessSurface('api_webhook', ['php', 'artisan', 'app:webhook'], '/Hello world/');

        $this->line('==> sample-app conformance: browser, MCP, and Waterline surfaces');
        $this->runHttpSurface('laravel_health', "{$baseUrl}/up");
        $this->runMcpSurface($baseUrl);
        $this->runApiDocumentationSurface($baseUrl);
        $this->runProcessSurface('browser_welcome', ['php', 'artisan', 'app:playwright', $baseUrl], '/\.mp4\b/');
        $this->runProcessSurface('browser_waterline', ['php', 'artisan', 'app:playwright', "{$baseUrl}/waterline/dashboard"], '/\.mp4\b/');
        $this->runHttpSurface('waterline_operator_dashboard', "{$baseUrl}/waterline/dashboard", '/Waterline|Workflow|Dashboard/i');

        $this->line('==> sample-app conformance: sandbox lifecycle variants');
        $this->runProcessSurface(
            'sandbox_default',
            ['php', 'artisan', 'app:sandbox', '--snapshot-every=0', '--wait-seconds=180'],
            '/Workflow complete\..*recoveries=0/s',
            self::SANDBOX_PROCESS_TIMEOUT_SECONDS,
        );
        $this->runProcessSurface(
            'sandbox_snapshot',
            ['php', 'artisan', 'app:sandbox', '--snapshot-every=2', '--wait-seconds=180'],
            '/Workflow complete\..*snap_/s',
            self::SANDBOX_PROCESS_TIMEOUT_SECONDS,
        );
        $this->runProcessSurface(
            'sandbox_suspend_resume',
            ['php', 'artisan', 'app:sandbox', '--suspend-between', '--wait-seconds=180'],
            '/Workflow complete\./',
            self::SANDBOX_PROCESS_TIMEOUT_SECONDS,
        );
        $this->runProcessSurface(
            'sandbox_recovery_injection',
            [
                'php',
                'artisan',
                'app:sandbox',
                '--snapshot-every=2',
                '--inject-loss-after=2',
                '--wait-seconds=180',
            ],
            '/Workflow complete\..*recoveries=1/s',
            self::SANDBOX_PROCESS_TIMEOUT_SECONDS,
        );

        $this->runAiSurfaces();

        $metadata = $this->metadata($startedAt, $baseUrl, $strict);
        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            $this->error('Failed to encode conformance metadata.');

            return self::FAILURE;
        }

        $this->line('==> sample-app conformance: run metadata');
        $this->line($json);

        $outputPath = $this->option('output');
        if (is_string($outputPath) && $outputPath !== '') {
            file_put_contents(base_path($outputPath), $json.PHP_EOL);
        }

        return $metadata['summary']['status'] === 'passed'
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function runAiSurfaces(): void
    {
        $hasOpenAiKey = trim((string) env('OPENAI_API_KEY', '')) !== '';

        if ((bool) $this->option('skip-ai') || ! $hasOpenAiKey) {
            $reason = (bool) $this->option('skip-ai')
                ? 'OpenAI-backed samples were explicitly skipped.'
                : 'OPENAI_API_KEY is not set; AI-backed samples are uncovered in this run.';

            foreach ([
                'prism_ai',
                'ai_agent_scripted',
                'ai_failure_hotel',
                'ai_failure_flight',
                'ai_failure_car',
            ] as $surface) {
                $this->skipSurface($surface, $reason);
            }

            return;
        }

        $message = 'Book a round trip flight from New York to San Francisco from 2026-06-15 to 2026-06-20, a hotel in San Francisco for one guest, and a rental car at SFO for the same dates.';

        $this->line('==> sample-app conformance: OpenAI-backed Prism and travel-agent samples');
        $this->runProcessSurface('prism_ai', ['php', 'artisan', 'app:prism'], '/Generated User:/', 300);
        $this->runProcessSurface('ai_agent_scripted', [
            'php', 'artisan', 'app:ai',
            "--message={$message}",
            '--inactivity-timeout=5',
        ], '/Agent:/', 300);

        foreach (['hotel', 'flight', 'car'] as $booking) {
            $this->runProcessSurface("ai_failure_{$booking}", [
                'php', 'artisan', 'app:ai',
                "--inject-failure={$booking}",
                "--message={$message}",
                '--inactivity-timeout=5',
            ], '/Any previous bookings have been cancelled|booking failed/i', 300);
        }
    }

    /**
     * @param list<string> $command
     */
    private function runProcessSurface(string $name, array $command, string $expected, int $timeoutSeconds = 180): void
    {
        $started = microtime(true);
        $process = new Process($command, base_path(), null, null, $timeoutSeconds);

        try {
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            $combined = $stdout."\n".$stderr;
            $passed = $process->isSuccessful() && preg_match($expected, $combined) === 1;

            $this->surfaces[$name] = [
                'surface' => $name,
                'status' => $passed ? 'passed' : 'failed',
                'driver' => 'artisan',
                'command' => $this->commandLine($command),
                'expected' => $expected,
                'exit_code' => $process->getExitCode(),
                'duration_ms' => $this->durationMs($started),
                'stdout_tail' => $this->tail($stdout),
                'stderr_tail' => $this->tail($stderr),
            ];
        } catch (Throwable $e) {
            $this->surfaces[$name] = [
                'surface' => $name,
                'status' => 'failed',
                'driver' => 'artisan',
                'command' => $this->commandLine($command),
                'expected' => $expected,
                'duration_ms' => $this->durationMs($started),
                'stdout_tail' => $this->tail($process->getOutput()),
                'stderr_tail' => $this->tail($process->getErrorOutput()),
                'error' => $e->getMessage(),
            ];
        }

        $this->line(sprintf(
            '%s %s',
            ($this->surfaces[$name]['status'] ?? null) === 'passed' ? '[pass]' : '[fail]',
            $name,
        ));
    }

    private function runHttpSurface(string $name, string $url, ?string $expected = null): void
    {
        $started = microtime(true);

        try {
            $response = Http::timeout(20)->get($url);
            $body = $response->body();
            $passed = $response->successful()
                && ($expected === null || preg_match($expected, $body) === 1);

            $this->surfaces[$name] = [
                'surface' => $name,
                'status' => $passed ? 'passed' : 'failed',
                'driver' => 'http',
                'url' => $url,
                'expected' => $expected,
                'http_status' => $response->status(),
                'duration_ms' => $this->durationMs($started),
                'body_tail' => $this->tail($body),
            ];
        } catch (Throwable $e) {
            $this->surfaces[$name] = [
                'surface' => $name,
                'status' => 'failed',
                'driver' => 'http',
                'url' => $url,
                'expected' => $expected,
                'duration_ms' => $this->durationMs($started),
                'error' => $e->getMessage(),
            ];
        }

        $this->line(sprintf(
            '%s %s',
            ($this->surfaces[$name]['status'] ?? null) === 'passed' ? '[pass]' : '[fail]',
            $name,
        ));
    }

    private function runApiDocumentationSurface(string $baseUrl): void
    {
        $started = microtime(true);
        $url = "{$baseUrl}/mcp/workflows";

        try {
            $readme = file_get_contents(base_path('README.md'));
            if (! is_string($readme)) {
                throw new RuntimeException('README.md could not be read.');
            }

            $initialize = Http::timeout(20)
                ->withHeaders(['Accept' => 'application/json, text/event-stream'])
                ->asJson()
                ->post($url, [
                    'jsonrpc' => '2.0',
                    'id' => 'sample-app-conformance-docs-initialize',
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => new \stdClass(),
                        'clientInfo' => [
                            'name' => 'sample-app-conformance-docs',
                            'version' => '1',
                        ],
                    ],
                ]);

            $session = $initialize->header('Mcp-Session-Id');
            $request = Http::timeout(20)
                ->withHeaders(['Accept' => 'application/json, text/event-stream'])
                ->asJson();

            if (is_string($session) && $session !== '') {
                $request = $request->withHeaders(['Mcp-Session-Id' => $session]);
            }

            $initialized = $request->post($url, [
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
                'params' => new \stdClass(),
            ]);

            $tools = $request->post($url, [
                'jsonrpc' => '2.0',
                'id' => 'sample-app-conformance-docs-tools',
                'method' => 'tools/list',
                'params' => new \stdClass(),
            ]);

            $workflows = $request->post($url, [
                'jsonrpc' => '2.0',
                'id' => 'sample-app-conformance-docs-workflows',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'list_workflows',
                    'arguments' => [
                        'show_recent' => false,
                    ],
                ],
            ]);

            $liveBody = implode("\n", [
                $initialize->body(),
                $initialized->body(),
                $tools->body(),
                $workflows->body(),
            ]);

            $documentationTokens = [
                '/mcp/workflows',
                'Available Tools',
                'Configuration',
                ...self::DOCUMENTED_MCP_TOOLS,
                ...self::DOCUMENTED_WORKFLOW_KEYS,
            ];
            $liveTokens = [
                ...self::DOCUMENTED_MCP_TOOLS,
                ...self::DOCUMENTED_WORKFLOW_KEYS,
            ];

            $missingDocumentation = $this->missingTokens($readme, $documentationTokens);
            $missingLive = $this->missingTokens($liveBody, $liveTokens);
            $passed = $initialize->successful()
                && $initialized->successful()
                && $tools->successful()
                && $workflows->successful()
                && $missingDocumentation === []
                && $missingLive === [];

            $this->surfaces['api_documentation'] = [
                'surface' => 'api_documentation',
                'status' => $passed ? 'passed' : 'failed',
                'driver' => 'readme-plus-mcp-json-rpc',
                'url' => $url,
                'initialize_status' => $initialize->status(),
                'initialized_status' => $initialized->status(),
                'tools_status' => $tools->status(),
                'workflows_status' => $workflows->status(),
                'documented_tools' => self::DOCUMENTED_MCP_TOOLS,
                'documented_workflows' => self::DOCUMENTED_WORKFLOW_KEYS,
                'missing_documentation_tokens' => $missingDocumentation,
                'missing_live_tokens' => $missingLive,
                'duration_ms' => $this->durationMs($started),
                'body_tail' => $this->tail($liveBody),
            ];
        } catch (Throwable $e) {
            $this->surfaces['api_documentation'] = [
                'surface' => 'api_documentation',
                'status' => 'failed',
                'driver' => 'readme-plus-mcp-json-rpc',
                'url' => $url,
                'duration_ms' => $this->durationMs($started),
                'error' => $e->getMessage(),
            ];
        }

        $this->line(sprintf(
            '%s %s',
            ($this->surfaces['api_documentation']['status'] ?? null) === 'passed' ? '[pass]' : '[fail]',
            'api_documentation',
        ));
    }

    private function runMcpSurface(string $baseUrl): void
    {
        $started = microtime(true);
        $url = "{$baseUrl}/mcp/workflows";
        $workflowId = 'sample-app-conformance-mcp-'.bin2hex(random_bytes(4));

        try {
            $initialize = Http::timeout(20)
                ->withHeaders(['Accept' => 'application/json, text/event-stream'])
                ->asJson()
                ->post($url, [
                    'jsonrpc' => '2.0',
                    'id' => 'sample-app-conformance-initialize',
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => new \stdClass(),
                        'clientInfo' => [
                            'name' => 'sample-app-conformance',
                            'version' => '1',
                        ],
                    ],
                ]);

            $session = $initialize->header('Mcp-Session-Id');
            $request = Http::timeout(20)
                ->withHeaders(['Accept' => 'application/json, text/event-stream'])
                ->asJson();

            if (is_string($session) && $session !== '') {
                $request = $request->withHeaders(['Mcp-Session-Id' => $session]);
            }

            $initialized = $request->post($url, [
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
                'params' => new \stdClass(),
            ]);

            $tools = $request->post($url, [
                'jsonrpc' => '2.0',
                'id' => 'sample-app-conformance-tools',
                'method' => 'tools/list',
                'params' => new \stdClass(),
            ]);

            $start = $request->post($url, [
                'jsonrpc' => '2.0',
                'id' => 'sample-app-conformance-start-simple',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'start_workflow',
                    'arguments' => [
                        'workflow' => 'simple',
                        'instance_id' => $workflowId,
                        'business_key' => 'sample-app-conformance',
                    ],
                ],
            ]);

            $result = null;
            $completed = false;
            $resultAttempts = 0;

            for ($attempt = 1; $attempt <= 20; $attempt++) {
                $resultAttempts = $attempt;

                if ($attempt > 1) {
                    sleep(1);
                }

                $result = $request->post($url, [
                    'jsonrpc' => '2.0',
                    'id' => 'sample-app-conformance-result',
                    'method' => 'tools/call',
                    'params' => [
                        'name' => 'get_workflow_result',
                        'arguments' => [
                            'workflow_id' => $workflowId,
                            'include_recent_history' => true,
                            'history_limit' => 10,
                        ],
                    ],
                ]);

                $resultBody = $result->body();
                if (
                    $result->successful()
                    && str_contains($resultBody, $workflowId)
                    && str_contains($resultBody, 'completed')
                    && str_contains($resultBody, 'workflow_activity_other')
                ) {
                    $completed = true;
                    break;
                }
            }

            $history = $request->post($url, [
                'jsonrpc' => '2.0',
                'id' => 'sample-app-conformance-history',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_workflow_history',
                    'arguments' => [
                        'workflow_id' => $workflowId,
                        'limit' => 25,
                    ],
                ],
            ]);

            $body = implode("\n", [
                $initialize->body(),
                $initialized->body(),
                $tools->body(),
                $start->body(),
                $result?->body() ?? '',
                $history->body(),
            ]);
            $passed = $initialize->successful()
                && $initialized->successful()
                && $tools->successful()
                && $start->successful()
                && ($result?->successful() ?? false)
                && $history->successful()
                && str_contains($body, 'list_workflows')
                && str_contains($body, 'start_workflow')
                && str_contains($body, 'get_workflow_result')
                && str_contains($body, 'get_workflow_history')
                && str_contains($start->body(), $workflowId)
                && $completed
                && str_contains($history->body(), 'WorkflowCompleted');

            $this->surfaces['mcp_workflow_api'] = [
                'surface' => 'mcp_workflow_api',
                'status' => $passed ? 'passed' : 'failed',
                'driver' => 'mcp-json-rpc',
                'url' => $url,
                'workflow_id' => $workflowId,
                'initialize_status' => $initialize->status(),
                'initialized_status' => $initialized->status(),
                'tools_status' => $tools->status(),
                'start_status' => $start->status(),
                'result_status' => $result?->status(),
                'history_status' => $history->status(),
                'result_attempts' => $resultAttempts,
                'workflow_completed' => $completed,
                'duration_ms' => $this->durationMs($started),
                'body_tail' => $this->tail($body),
            ];
        } catch (Throwable $e) {
            $this->surfaces['mcp_workflow_api'] = [
                'surface' => 'mcp_workflow_api',
                'status' => 'failed',
                'driver' => 'mcp-json-rpc',
                'url' => $url,
                'duration_ms' => $this->durationMs($started),
                'error' => $e->getMessage(),
            ];
        }

        $this->line(sprintf(
            '%s %s',
            ($this->surfaces['mcp_workflow_api']['status'] ?? null) === 'passed' ? '[pass]' : '[fail]',
            'mcp_workflow_api',
        ));
    }

    private function skipSurface(string $name, string $reason): void
    {
        $this->surfaces[$name] = [
            'surface' => $name,
            'status' => 'skipped',
            'reason' => $reason,
        ];

        $this->line("[skip] {$name}: {$reason}");
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $startedAt, string $baseUrl, bool $strict): array
    {
        $failed = array_values(array_filter(
            array_keys($this->surfaces),
            fn (string $name): bool => ($this->surfaces[$name]['status'] ?? null) === 'failed',
        ));
        $skipped = array_values(array_filter(
            array_keys($this->surfaces),
            fn (string $name): bool => ($this->surfaces[$name]['status'] ?? null) === 'skipped',
        ));
        $missing = array_values(array_diff(self::REQUIRED_SURFACES, array_keys($this->surfaces)));
        $uncovered = array_values(array_unique([...$skipped, ...$missing]));
        $status = $failed !== [] || $missing !== [] || ($strict && $skipped !== []) ? 'failed' : 'passed';

        return [
            'schema' => 'durable-workflow.sample-app.conformance.run',
            'version' => 1,
            'generated_at' => gmdate('c'),
            'started_at' => $startedAt,
            'completed_at' => gmdate('c'),
            'app_url' => $baseUrl,
            'artifactVersions' => $this->artifactVersions(),
            'active_payload_codec' => $this->activePayloadCodec(),
            'surfaces' => $this->surfaces,
            'summary' => [
                'status' => $status,
                'strict' => $strict,
                'surface_count' => count($this->surfaces),
                'required_surfaces' => self::REQUIRED_SURFACES,
                'failed_surfaces' => $failed,
                'skipped_surfaces' => $skipped,
                'missing_surfaces' => $missing,
                'uncovered_surfaces' => $uncovered,
            ],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function artifactVersions(): array
    {
        return [
            'server' => $this->versionFromEnvImage('DURABLE_SERVER_IMAGE'),
            'cli' => $this->envString('DURABLE_WORKFLOW_CLI_VERSION'),
            'sdk-python' => $this->envString('DURABLE_WORKFLOW_PYTHON_SDK_VERSION'),
            'workflow' => $this->installedVersion('durable-workflow/workflow'),
            'waterline' => $this->installedVersion('durable-workflow/waterline'),
            'sample-app' => $this->envString('SAMPLE_APP_COMMIT') ?? $this->gitSha(),
        ];
    }

    private function installedVersion(string $package): ?string
    {
        try {
            $version = InstalledVersions::getPrettyVersion($package);
        } catch (Throwable) {
            return null;
        }

        return is_string($version) && $version !== '' ? $version : null;
    }

    private function versionFromEnvImage(string $name): ?string
    {
        $value = $this->envString($name);

        if ($value === null) {
            return null;
        }

        $tag = strrchr($value, ':');

        return $tag === false ? $value : ltrim($tag, ':');
    }

    private function envString(string $name): ?string
    {
        $value = env($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function activePayloadCodec(): string
    {
        $codec = config('workflows.serializer', 'avro');

        return is_string($codec) && $codec !== '' ? $codec : 'avro';
    }

    private function gitSha(): ?string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], base_path(), null, null, 5);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $sha = trim($process->getOutput());

        return $sha === '' ? null : $sha;
    }

    private function baseUrl(): string
    {
        $option = $this->option('app-url');
        $url = is_string($option) && $option !== ''
            ? $option
            : (string) config('app.url', 'http://localhost:8000');

        $url = rtrim($url, '/');

        return $url === 'http://localhost' ? 'http://localhost:8000' : $url;
    }

    /**
     * @param list<string> $command
     */
    private function commandLine(array $command): string
    {
        return implode(' ', array_map('escapeshellarg', $command));
    }

    private function durationMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function missingTokens(string $haystack, array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => ! str_contains($haystack, $token),
        ));
    }

    private function tail(string $value, int $limit = 4000): string
    {
        $value = trim($value);

        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, -$limit);
    }
}
