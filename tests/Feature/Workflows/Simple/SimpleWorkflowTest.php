<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows\Simple;

use App\Workflows\Simple\SimpleActivity;
use App\Workflows\Simple\SimpleOtherActivity;
use App\Workflows\Simple\SimpleWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\WorkflowStub;

class SimpleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(SimpleActivity::class, 'activity');

        WorkflowStub::mock(SimpleOtherActivity::class, function ($context, $string) {
            $this->assertSame('other', $string);

            return $string;
        });

        $workflow = WorkflowStub::make(SimpleWorkflow::class);
        $workflow->start();

        $this->assertSame('workflow_activity_other', $workflow->refresh()->output());
    }
}
