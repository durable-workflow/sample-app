<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Ai;
use App\Models\AiWorkflowMessage;
use App\Workflows\Ai\AiWorkflow;
use App\Workflows\Ai\CancelHotelActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\Enums\MessageDirection;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

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

    public function test_ai_workflow_uses_the_public_message_stream_facade(): void
    {
        $source = file_get_contents(app_path('Workflows/Ai/AiWorkflow.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('->inbox(self::ASSISTANT_STREAM)', $source);
        $this->assertStringContainsString('->receiveOne()', $source);
        $this->assertStringContainsString('->outbox(self::ASSISTANT_STREAM)', $source);
        $this->assertStringContainsString('->sendReference(', $source);
        $this->assertStringNotContainsString('new MessageService', $source);
        $this->assertStringNotContainsString('MessageStreamCursor::reserveNextSequence', $source);
        $this->assertStringNotContainsString('WorkflowMessage::query()->create', $source);
    }

    public function test_ai_command_can_read_latest_assistant_message_after_terminal_run(): void
    {
        $run = $this->createRun(status: 'completed');

        AiWorkflowMessage::query()->create([
            'reference' => 'ai:ai-stream-test:2',
            'workflow_id' => 'ai-stream-test',
            'run_id' => $run->id,
            'role' => 'assistant',
            'content' => 'Earlier update result.',
        ]);
        AiWorkflowMessage::query()->create([
            'reference' => 'ai:ai-stream-test:10',
            'workflow_id' => 'ai-stream-test',
            'run_id' => $run->id,
            'role' => 'assistant',
            'content' => 'Final cancellation output.',
        ]);

        $method = new ReflectionMethod(Ai::class, 'latestAssistantMessage');

        $this->assertSame(
            'Final cancellation output.',
            $method->invoke(new Ai(), WorkflowStub::loadRun($run->id)),
        );
    }

    public function test_ai_command_polling_keeps_a_terminal_message_fallback(): void
    {
        $source = file_get_contents(app_path('Console/Commands/Ai.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('if ($workflow->completed()) {', $source);
        $this->assertStringContainsString('return $this->printLatestAssistantMessage($workflow, onlyNew: true);', $source);
        $this->assertStringContainsString('} elseif ($result->failed()) {', $source);
        $this->assertStringContainsString('$workflow->completed() && $this->printLatestAssistantMessage($workflow, onlyNew: true)', $source);
        $this->assertStringContainsString('printLatestAssistantMessage($workflow, onlyNew: true)', $source);
        $this->assertStringContainsString('printedAssistantMessageSequences', $source);
        $this->assertStringContainsString('assistantMessageAlreadyPrinted', $source);
        $this->assertStringContainsString('latestAssistantMessageRecord', $source);
        $this->assertStringContainsString('pollReceiveUpdate: false', $source);
    }

    public function test_scripted_success_timeout_completes_without_cancelling_bookings(): void
    {
        Queue::fake();
        config()->set('workflows.v2.task_dispatch_mode', 'poll');
        Carbon::setTestNow(Carbon::parse('2026-05-25 12:00:00'));

        try {
            $bookingText = 'Booked the San Francisco hotel, round trip flight, and rental car from the scripted request.';
            $workflow = WorkflowStub::make(AiWorkflow::class, 'ai-scripted-success-timeout');

            $workflow->start(null, 1, [
                'text' => $bookingText,
                'bookings' => [
                    [
                        'type' => 'book_hotel',
                        'hotel_name' => 'San Francisco Demo Hotel',
                        'check_in_date' => '2026-06-15',
                        'check_out_date' => '2026-06-20',
                        'guests' => 1,
                    ],
                    [
                        'type' => 'book_flight',
                        'origin' => 'New York',
                        'destination' => 'San Francisco',
                        'departure_date' => '2026-06-15',
                        'return_date' => '2026-06-20',
                    ],
                    [
                        'type' => 'book_rental_car',
                        'pickup_location' => 'SFO',
                        'pickup_date' => '2026-06-15',
                        'return_date' => '2026-06-20',
                    ],
                ],
            ]);

            $this->drainReadyWorkflowTasks();

            $workflow->signal('send', 'Book the scripted San Francisco trip.');
            $this->drainReadyWorkflowTasks();

            $this->assertDatabaseHas('ai_workflow_messages', [
                'workflow_id' => 'ai-scripted-success-timeout',
                'role' => 'assistant',
                'content' => $bookingText,
            ]);

            Carbon::setTestNow(now()->addSecond());
            $this->drainReadyWorkflowTasks();

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame(1, AiWorkflowMessage::query()
                ->where('workflow_id', 'ai-scripted-success-timeout')
                ->count());
            $this->assertSame(0, AiWorkflowMessage::query()
                ->where('workflow_id', 'ai-scripted-success-timeout')
                ->where('content', 'like', '%Any previous bookings have been cancelled.%')
                ->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_scripted_flight_failure_publishes_cancellation_after_compensation(): void
    {
        Queue::fake();
        config()->set('workflows.v2.task_dispatch_mode', 'poll');
        Carbon::setTestNow(Carbon::parse('2026-05-25 12:00:00'));

        try {
            $workflow = WorkflowStub::make(AiWorkflow::class, 'ai-scripted-flight-failure');

            $workflow->start('flight', 1, [
                'text' => 'Booked the San Francisco hotel, round trip flight, and rental car from the scripted request.',
                'bookings' => [
                    [
                        'type' => 'book_hotel',
                        'hotel_name' => 'San Francisco Demo Hotel',
                        'check_in_date' => '2026-06-15',
                        'check_out_date' => '2026-06-20',
                        'guests' => 1,
                    ],
                    [
                        'type' => 'book_flight',
                        'origin' => 'New York',
                        'destination' => 'San Francisco',
                        'departure_date' => '2026-06-15',
                        'return_date' => '2026-06-20',
                    ],
                    [
                        'type' => 'book_rental_car',
                        'pickup_location' => 'SFO',
                        'pickup_date' => '2026-06-15',
                        'return_date' => '2026-06-20',
                    ],
                ],
            ]);

            $this->drainReadyWorkflowTasks();

            $workflow->signal('send', 'Book the scripted San Francisco trip.');
            $this->drainReadyWorkflowTasks();

            $this->assertTrue($workflow->refresh()->completed());

            /** @var AiWorkflowMessage|null $message */
            $message = AiWorkflowMessage::query()
                ->where('workflow_id', 'ai-scripted-flight-failure')
                ->where('role', 'assistant')
                ->first();

            $this->assertNotNull($message);
            $this->assertStringContainsString('Flight booking failed: New York to San Francisco.', $message->content);
            $this->assertStringContainsString('Any previous bookings have been cancelled.', $message->content);

            /** @var ActivityExecution|null $cancelActivity */
            $cancelActivity = ActivityExecution::query()
                ->where('workflow_run_id', $workflow->runId())
                ->where('activity_class', CancelHotelActivity::class)
                ->first();

            $this->assertNotNull($cancelActivity);
            $this->assertSame(ActivityStatus::Completed, $cancelActivity->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_readme_teaches_the_public_message_stream_authoring_api(): void
    {
        $source = file_get_contents(base_path('README.md'));

        $this->assertIsString($source);
        $this->assertStringContainsString('#### Message Streams', $source);
        $this->assertStringContainsString('Workflow::inbox()', $source);
        $this->assertStringContainsString('Workflow::outbox()', $source);
        $this->assertStringContainsString('outbox(self::ASSISTANT_STREAM)', $source);
        $this->assertStringContainsString('inbox(self::ASSISTANT_STREAM)', $source);
        $this->assertStringNotContainsString('Workflow\\V2\\Support\\MessageService', $source);
        $this->assertStringNotContainsString('MessageStreamCursor::reserveNextSequence', $source);
        $this->assertStringContainsString('App\\Workflows\\Ai\\AiWorkflow', $source);
    }

    private function publishAssistantMessage(AiWorkflow $workflow, string $content): void
    {
        $method = new ReflectionMethod(AiWorkflow::class, 'publishAssistantMessage');
        $method->invoke($workflow, $content);
    }

    private function createRun(string $status = 'running'): WorkflowRun
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
            'status' => $status,
            'message_cursor_position' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function drainReadyWorkflowTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->where(function ($query): void {
                    $query->whereNull('available_at')
                        ->orWhere('available_at', '<=', now());
                })
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }
}
