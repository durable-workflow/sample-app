<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows\Microservice;

use App\Workflows\Microservice\MicroserviceWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\WorkflowStub;

class MicroserviceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_uses_the_shared_connection(): void
    {
        WorkflowStub::make(MicroserviceWorkflow::class);

        $this->assertSame('shared', WorkflowStub::connection());
    }
}
