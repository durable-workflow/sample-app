<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows\Microservice;

use App\Models\StoredWorkflow;
use App\Workflows\Microservice\MicroserviceOtherActivity;
use App\Workflows\Microservice\MicroserviceWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\WorkflowStub;

class MicroserviceOtherActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity(): void
    {
        $workflow = WorkflowStub::make(MicroserviceWorkflow::class);

        $activity = new MicroserviceOtherActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::findOrFail($workflow->id()),
            'other',
        );

        $result = $activity->handle();

        $this->assertSame('other', $result);
    }
}
