<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Workflows\Ai\AiWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\Enums\MessageDirection;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;

class AiWorkflowMessageStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_assistant_response_is_delivered_through_the_message_stream(): void
    {
        $workflow = new AiWorkflow($run = $this->createRun());

        $this->publishAssistantMessage($workflow, 'I can help with that itinerary.');

        $this->assertSame('I can help with that itinerary.', $workflow->receive());

        $this->assertDatabaseHas('ai_workflow_messages', [
            'reference' => 'ai:ai-stream-test:1',
            'workflow_id' => 'ai-stream-test',
            'run_id' => $run->id,
            'role' => 'assistant',
            'content' => 'I can help with that itinerary.',
        ]);

        $this->assertSame(2, $run->refresh()->message_cursor_position);
        $this->assertSame(
            MessageConsumeState::Consumed,
            WorkflowMessage::query()
                ->where('workflow_run_id', $run->id)
                ->where('direction', MessageDirection::Inbound)
                ->where('stream_key', 'ai.assistant')
                ->firstOrFail()
                ->consume_state,
        );

        $this->assertSame(
            1,
            WorkflowMessage::query()
                ->where('workflow_run_id', $run->id)
                ->where('direction', MessageDirection::Outbound)
                ->where('stream_key', 'ai.assistant')
                ->where('payload_reference', 'ai:ai-stream-test:1')
                ->count(),
        );
    }

    public function test_repeated_receives_advance_the_stream_without_replaying_old_replies(): void
    {
        $workflow = new AiWorkflow($run = $this->createRun());

        $this->publishAssistantMessage($workflow, 'First response.');
        $this->publishAssistantMessage($workflow, 'Second response.');

        $this->assertSame('First response.', $workflow->receive());
        $this->assertSame('Second response.', $workflow->receive());
        $this->assertNull($workflow->receive());

        $this->assertSame(4, $run->refresh()->message_cursor_position);
        $this->assertSame(
            2,
            WorkflowMessage::query()
                ->where('workflow_run_id', $run->id)
                ->where('direction', MessageDirection::Inbound)
                ->where('stream_key', 'ai.assistant')
                ->where('consume_state', MessageConsumeState::Consumed)
                ->count(),
        );
    }

    public function test_ai_workflow_uses_the_first_class_message_stream_facade(): void
    {
        $source = file_get_contents(app_path('Workflows/Ai/AiWorkflow.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('->inbox(self::ASSISTANT_STREAM)->receiveOne()', $source);
        $this->assertStringContainsString('->outbox(self::ASSISTANT_STREAM)->sendReference(', $source);
        $this->assertStringNotContainsString('Workflow\\V2\\Support\\MessageService', $source);
        $this->assertStringNotContainsString('Workflow\\V2\\Models\\WorkflowMessage', $source);
        $this->assertStringNotContainsString('Workflow\\V2\\Support\\MessageStreamCursor', $source);
        $this->assertStringNotContainsString('WorkflowMessage::query()->create', $source);
    }

    private function publishAssistantMessage(AiWorkflow $workflow, string $content): void
    {
        $method = new ReflectionMethod(AiWorkflow::class, 'publishAssistantMessage');
        $method->invoke($workflow, $content);
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::create([
            'id' => 'ai-stream-test',
            'workflow_type' => AiWorkflow::class,
            'workflow_class' => AiWorkflow::class,
        ]);

        $run = WorkflowRun::create([
            'id' => 'run-'.uniqid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => AiWorkflow::class,
            'workflow_type' => AiWorkflow::class,
            'status' => 'running',
            'message_cursor_position' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
