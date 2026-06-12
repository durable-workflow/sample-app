<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SampleTeachingMaterialTest extends TestCase
{
    public function test_readme_contains_replay_safety_do_and_do_not_examples(): void
    {
        $readme = file_get_contents(__DIR__.'/../../README.md');

        $this->assertIsString($readme);
        $this->assertStringContainsString('#### Replay-Safety Teaching Notes', $readme);
        $this->assertStringContainsString('$startedAt = sideEffect(fn () => now()->getTimestamp());', $readme);
        $this->assertStringContainsString('$startedAt = now();', $readme);
        $this->assertStringContainsString('replay can run the method again later', $readme);
        $this->assertStringContainsString('Prefer scalar values inside `sideEffect()` callbacks', $readme);
    }

    public function test_readme_contains_short_v1_to_v2_migration_section(): void
    {
        $readme = file_get_contents(__DIR__.'/../../README.md');

        $this->assertIsString($readme);
        $this->assertStringContainsString('#### Migrating from Durable Workflow 1.x', $readme);
        $this->assertStringContainsString('Extend `Workflow\V2\Workflow` instead of `Workflow\Workflow`.', $readme);
        $this->assertStringContainsString('Replace `yield activity(...)` with a straight-line `activity(...)` call', $readme);
        $this->assertStringContainsString('Rename the entry method from `execute(...)` to `handle(...)`', $readme);
        $this->assertStringContainsString('Extend `Workflow\V2\Activity` and define `handle(...)`', $readme);
        $this->assertStringNotContainsString('Workflow\V2\Attributes\Activity', $readme);
        $this->assertStringContainsString('use Workflow\V2\Attributes\Signal;', $readme);
        $this->assertStringContainsString("#[Signal('name', [...])]", $readme);
        $this->assertStringNotContainsString("#[Workflow\V2\Attributes\Signal", $readme);
        $this->assertStringContainsString("await('name')", $readme);
    }

    public function test_workflow_entry_points_include_teaching_preambles(): void
    {
        $expectations = [
            'app/Workflows/Simple/SimpleWorkflow.php' => 'Smallest v2 shape',
            'app/Workflows/Elapsed/ElapsedTimeWorkflow.php' => 'Clock reads are non-deterministic',
            'app/Workflows/Microservice/MicroserviceWorkflow.php' => 'shares the queue/database contract',
            'app/Workflows/Playwright/CheckConsoleErrorsWorkflow.php' => 'Browser and FFmpeg work belongs in activities',
            'app/Workflows/Webhooks/WebhookWorkflow.php' => 'v2 signals are pull-style',
            'app/Workflows/Prism/PrismWorkflow.php' => 'workflow loop is replay-safe',
            'app/Workflows/Ai/AiWorkflow.php' => 'durable agent pattern',
            'app/Workflows/Sandbox/SandboxAgentWorkflow.php' => 'durable sandbox orchestration pattern',
        ];

        foreach ($expectations as $path => $needle) {
            $contents = file_get_contents(__DIR__.'/../../'.$path);

            $this->assertIsString($contents);
            $this->assertStringContainsString($needle, $contents, "{$path} is missing its teaching preamble.");
        }
    }

    public function test_readme_documents_sandbox_orchestration_pattern(): void
    {
        $readme = file_get_contents(__DIR__.'/../../README.md');

        $this->assertIsString($readme);
        $this->assertStringContainsString('#### Sandbox Orchestration', $readme);
        $this->assertStringContainsString('App\\Workflows\\Sandbox\\SandboxAgentWorkflow', $readme);
        $this->assertStringContainsString('App\\Sandbox\\SandboxProvider', $readme);
        $this->assertStringContainsString('php artisan app:sandbox', $readme);
        $this->assertStringContainsString('--inject-loss-after=2', $readme);
    }

    public function test_full_conformance_harness_is_public_and_names_required_surfaces(): void
    {
        $readme = file_get_contents(__DIR__.'/../../README.md');
        $script = file_get_contents(__DIR__.'/../../scripts/compose-conformance.sh');
        $artifactResolver = file_get_contents(__DIR__.'/../../scripts/resolve-current-artifacts.sh');
        $smokeScript = file_get_contents(__DIR__.'/../../scripts/compose-smoke.sh');
        $smokeWorkflow = file_get_contents(__DIR__.'/../../.github/workflows/smoke.yml');
        $command = file_get_contents(__DIR__.'/../../app/Console/Commands/Conformance.php');
        $aiCommand = file_get_contents(__DIR__.'/../../app/Console/Commands/Ai.php');
        $aiWorkflow = file_get_contents(__DIR__.'/../../app/Workflows/Ai/AiWorkflow.php');
        $travelAgentActivity = file_get_contents(__DIR__.'/../../app/Workflows/Ai/TravelAgentActivity.php');

        $this->assertIsString($readme);
        $this->assertIsString($script);
        $this->assertIsString($artifactResolver);
        $this->assertIsString($smokeScript);
        $this->assertIsString($smokeWorkflow);
        $this->assertIsString($command);
        $this->assertIsString($aiCommand);
        $this->assertIsString($aiWorkflow);
        $this->assertIsString($travelAgentActivity);

        $this->assertStringContainsString('scripts/compose-conformance.sh --strict', $readme);
        $this->assertStringContainsString('SAMPLE_APP_CONFORMANCE_ENV_FILE', $readme);
        $this->assertStringContainsString('SAMPLE_APP_SMOKE_ONLY=1', $readme);
        $this->assertStringContainsString('DURABLE_WORKFLOW_ARTIFACT_SOURCE=pinned', $readme);
        $this->assertStringContainsString('DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE=/path/to/tuple.json', $readme);
        $this->assertStringContainsString('API documentation check', $readme);
        $this->assertStringContainsString('Waterline/manual observation check', $readme);
        $this->assertStringContainsString('focused findings', $readme);
        $this->assertStringContainsString('--booking-plan-json', $readme);
        $this->assertStringContainsString('app:conformance', $script);
        $this->assertStringContainsString('SAMPLE_APP_CONFORMANCE_URL:-http://app:8000', $script);
        $this->assertStringContainsString('load_conformance_env', $script);
        $this->assertStringContainsString('while [[ -n "$dir" && "$dir" != "/" ]]', $script);
        $this->assertStringContainsString('refresh_services_for_conformance_env', $script);
        $this->assertStringContainsString('-e OPENAI_API_KEY', $script);
        $this->assertStringContainsString('SAMPLE_APP_SMOKE_ONLY', $smokeScript);
        $this->assertStringNotContainsString('has_conformance_key', $smokeScript);
        $this->assertStringNotContainsString('SAMPLE_APP_CONFORMANCE_AFTER_SMOKE', $smokeScript);
        $this->assertStringContainsString('scripts/compose-conformance.sh', $smokeScript);
        $this->assertStringContainsString('SAMPLE_APP_SMOKE_ONLY: 1', $smokeWorkflow);
        $this->assertStringContainsString('git rev-parse HEAD', $script);
        $this->assertStringContainsString('SAMPLE_APP_COMMIT="${sample_app_commit}"', $script);
        $this->assertStringContainsString('scripts/resolve-current-artifacts.sh', $script);
        $this->assertStringContainsString('pinned_server_image="durableworkflow/server:0.2.400"', $artifactResolver);
        $this->assertStringContainsString('DURABLE_WORKFLOW_ARTIFACT_SOURCE', $artifactResolver);
        $this->assertStringContainsString('DURABLE_WORKFLOW_RESOLVE_LATEST', $artifactResolver);
        $this->assertStringContainsString('DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE', $artifactResolver);
        $this->assertStringContainsString('latest_dockerhub_server_version', $artifactResolver);
        $this->assertStringContainsString('pinned_cli_version="0.1.80"', $artifactResolver);
        $this->assertStringContainsString('latest_github_release_version durable-workflow/cli', $artifactResolver);
        $this->assertStringContainsString('pinned_python_sdk_version="0.4.88"', $artifactResolver);
        $this->assertStringContainsString('latest_pypi_version durable-workflow', $artifactResolver);
        $this->assertStringContainsString('pinned_workflow_version="2.0.0-alpha.204"', $artifactResolver);
        $this->assertStringContainsString('pinned_waterline_version="2.0.0-alpha.87"', $artifactResolver);
        $this->assertStringContainsString('latest_packagist_prerelease_version durable-workflow/workflow', $artifactResolver);
        $this->assertStringContainsString('latest_packagist_prerelease_version durable-workflow/waterline', $artifactResolver);
        $this->assertStringContainsString('--allow-skips', $script);
        $this->assertStringContainsString('-e DURABLE_WORKFLOW_PYTHON_SDK_VERSION', $script);
        $this->assertStringContainsString('durable-workflow.sample-app.conformance.run', $command);
        $this->assertStringContainsString('{--allow-skips', $command);
        $this->assertStringContainsString("envString('SAMPLE_APP_COMMIT')", $command);
        $this->assertStringContainsString('active_payload_codec', $command);
        $this->assertStringContainsString('DOCUMENTED_MCP_TOOLS', $command);
        $this->assertStringContainsString('DOCUMENTED_WORKFLOW_KEYS', $command);
        $this->assertStringContainsString('required_surfaces', $command);
        $this->assertStringContainsString('missing_surfaces', $command);
        $this->assertStringContainsString('uncovered_surfaces', $command);
        $this->assertStringContainsString('focused findings', $command);
        $this->assertStringContainsString('failedSurfaceImpact', $command);
        $this->assertStringContainsString('api_documentation', $command);
        $this->assertStringContainsString('runApiDocumentationSurface', $command);
        $this->assertStringContainsString('get_workflow_history', $command);
        $this->assertStringContainsString('workflow_completed', $command);
        $this->assertStringContainsString('runWaterlineManualObservationSurface', $command);
        $this->assertStringContainsString('waterline_manual_observation', $command);
        $this->assertStringContainsString('workflow:v2:history-export', $command);
        $this->assertStringContainsString('durable-workflow.v2.history-export', $command);
        $this->assertStringContainsString('AI_CONFORMANCE_BOOKING_PLAN', $command);
        $this->assertStringContainsString('--booking-plan-json={$bookingPlanJson}', $command);
        $this->assertStringContainsString("'--inactivity-timeout=5'", $command);
        $this->assertStringContainsString("'--inactivity-timeout=1'", $command);
        $this->assertStringContainsString('AI_FAILURE_PROCESS_TIMEOUT_SECONDS = 180', $command);
        $this->assertStringContainsString('SANDBOX_PROCESS_TIMEOUT_SECONDS = 300', $command);
        $this->assertStringContainsString('--wait-seconds=180', $command);
        $this->assertStringContainsString('{--booking-plan-json=', $aiCommand);
        $this->assertStringContainsString('$workflow->start($injectFailure, $inactivityTimeout, $bookingPlan)', $aiCommand);
        $this->assertStringContainsString('bookingPlanOption', $aiCommand);
        $this->assertStringContainsString('printedAssistantMessageSequences', $aiCommand);
        $this->assertStringContainsString('printLatestAssistantMessage($workflow, onlyNew: true)', $aiCommand);
        $this->assertStringContainsString('latestAssistantMessageRecord', $aiCommand);
        $this->assertStringContainsString('?array $bookingPlan = null', $aiWorkflow);
        $this->assertStringContainsString('TravelAgentActivity::class, $messages, $bookingPlan', $aiWorkflow);
        $this->assertStringContainsString('public function handle(array $messages, ?array $bookingPlan = null)', $travelAgentActivity);
        $this->assertStringContainsString('json_encode($bookingPlan, JSON_THROW_ON_ERROR)', $travelAgentActivity);

        foreach ([
            'browser_welcome',
            'browser_waterline',
            'waterline_manual_observation',
            'mcp_workflow_api',
            'api_webhook',
            'prism_ai',
            'ai_agent_scripted',
            'ai_failure_hotel',
            'ai_failure_flight',
            'ai_failure_car',
            'sandbox_default',
            'sandbox_snapshot',
            'sandbox_suspend_resume',
            'sandbox_recovery_injection',
            'waterline_operator_dashboard',
            'artifactVersions',
            'skipped_surfaces',
        ] as $needle) {
            $this->assertStringContainsString($needle, $command);
        }

        $sandboxCommand = file_get_contents(__DIR__.'/../../app/Console/Commands/Sandbox.php');

        $this->assertIsString($sandboxCommand);
        $this->assertStringContainsString('{--wait-seconds=180', $sandboxCommand);
        $this->assertStringContainsString('Workflow still running after %d seconds', $sandboxCommand);
    }
}
