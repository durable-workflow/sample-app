<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\WorkflowServer;
use App\Mcp\Tools\StartWorkflowTool;
use DurableWorkflow\AI\Workflows\SandboxAgentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Opis\JsonSchema\Validator;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;

final class McpSandboxRuntimeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_workflow_rejects_arguments_beyond_the_configured_runtime_boundary(): void
    {
        $this->assertSuspendAttemptIsRejected('sandbox', 'mcp-sandbox-suspend-attempt');
    }

    public function test_fqcn_start_reuses_the_configured_runtime_boundary(): void
    {
        config(['workflow_mcp.allow_fqcn' => true]);

        $this->assertSuspendAttemptIsRejected(
            SandboxAgentWorkflow::class,
            'mcp-sandbox-fqcn-suspend-attempt',
        );
    }

    public function test_structured_sandbox_start_conforms_to_the_published_mcp_schema(): void
    {
        config(['queue.default' => 'database']);

        $initialize = $this->json('POST', '/mcp/workflows', [
            'jsonrpc' => '2.0',
            'id' => 'structured-sandbox-initialize',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass,
                'clientInfo' => [
                    'name' => 'structured-sandbox-schema-test',
                    'version' => '1',
                ],
            ],
        ], [
            'Accept' => 'application/json, text/event-stream',
        ])->assertOk();

        $headers = [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Session-Id' => $initialize->headers->get('MCP-Session-Id'),
        ];

        $this->json('POST', '/mcp/workflows', [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => new \stdClass,
        ], $headers)->assertAccepted();

        $tools = $this->json('POST', '/mcp/workflows', [
            'jsonrpc' => '2.0',
            'id' => 'structured-sandbox-tools',
            'method' => 'tools/list',
            'params' => new \stdClass,
        ], $headers)
            ->assertOk()
            ->json('result.tools');

        $startWorkflow = collect($tools)->firstWhere('name', 'start_workflow');
        $this->assertIsArray($startWorkflow);

        $inputSchema = json_decode(
            json_encode($startWorkflow['inputSchema'], JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        $validator = new Validator;

        foreach ([[], ['key' => 'value'], 'value', 1.5, true, null] as $representativeArgument) {
            $representativePayload = [
                'workflow' => 'sandbox',
                'arguments' => [$representativeArgument],
            ];
            $validation = $validator->validate(
                json_decode(json_encode($representativePayload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
                $inputSchema,
            );

            $this->assertTrue(
                $validation->isValid(),
                sprintf('The published schema rejected a %s workflow argument: %s', get_debug_type($representativeArgument), $validation),
            );
        }

        $instanceId = 'mcp-structured-sandbox-test';
        $arguments = [
            'workflow' => 'sandbox',
            'instance_id' => $instanceId,
            'arguments' => [
                [[
                    'type' => 'shell',
                    'args' => [
                        'command' => 'printf structured',
                        'capture_output' => true,
                        'timeout' => null,
                    ],
                ]],
                'local',
                0,
            ],
        ];

        $validation = $validator->validate(
            json_decode(json_encode($arguments, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            $inputSchema,
        );

        $this->assertTrue($validation->isValid(), (string) $validation);

        $this->json('POST', '/mcp/workflows', [
            'jsonrpc' => '2.0',
            'id' => 'structured-sandbox-start',
            'method' => 'tools/call',
            'params' => [
                'name' => 'start_workflow',
                'arguments' => $arguments,
            ],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.workflow_id', $instanceId)
            ->assertJsonPath('result.structuredContent.workflow', 'sandbox')
            ->assertJsonPath('result.structuredContent.status', 'pending');

        $this->assertTrue(
            WorkflowRun::query()->where('workflow_instance_id', $instanceId)->exists(),
        );
    }

    private function assertSuspendAttemptIsRejected(string $workflow, string $instanceId): void
    {

        WorkflowServer::tool(StartWorkflowTool::class, [
            'workflow' => $workflow,
            'instance_id' => $instanceId,
            'arguments' => [
                [['type' => 'shell', 'args' => ['command' => 'true']]],
                'e2b',
                0,
                true,
            ],
        ])->assertHasErrors(['accepts at most 3 ordered arguments']);

        $this->assertFalse(
            WorkflowRun::query()->where('workflow_instance_id', $instanceId)->exists(),
        );
    }
}
