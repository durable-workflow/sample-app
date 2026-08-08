<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows\Microservice;

use App\Models\StoredWorkflow;
use App\Workflows\Microservice\MicroserviceActivity;
use App\Workflows\Microservice\MicroserviceWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\WorkflowStub;

class MicroserviceActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity(): void
    {
        $workflow = WorkflowStub::make(MicroserviceWorkflow::class);

        $activity = new MicroserviceActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::findOrFail($workflow->id()),
        );

        $result = $activity->handle();

        $this->assertSame('activity', $result);
    }
}
