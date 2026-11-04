<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows\ServiceMode;

use App\Models\ConversationRecord;
use App\Workflows\Conversation\ConversationWorkflow as EmbeddedConversationWorkflow;
use App\Workflows\ServiceMode\Conversation\ConversationActivities;
use App\Workflows\ServiceMode\Conversation\ConversationWorkflow;
use App\Workflows\ServiceMode\WelcomeWorkflow;
use DateTimeImmutable;
use DurableWorkflow\Bridge\Laravel\WorkerFactory;
use DurableWorkflow\Client;
use DurableWorkflow\Codec\AvroPayloadCodec;
use DurableWorkflow\Testing\WorkerTestHarness;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Drives the service-mode conversation through the PHP SDK replayer.
 *
 * The test plays the server: it turns each emitted command into history,
 * runs activities through the registered handlers, and replays every
 * workflow task from the full history, as a fresh worker would.
 */
final class ConversationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const WORKFLOW_ID = 'service-conversation-test';

    private const RUN_ID = 'service-conversation-run';

    private Worker $worker;

    private WorkerTestHarness $harness;

    private AvroPayloadCodec $codec;

    /** @var list<array<string, mixed>> */
    private array $history = [];

    /** @var list<mixed> */
    private array $input = [];

    private int $nextSequence = 1;

    private DateTimeImmutable $clock;

    /** @var array{type: string, value: mixed}|null */
    private ?array $closed = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(LoggerInterface::class, new NullLogger);
        $this->worker = $this->app->make(WorkerFactory::class)->make(WelcomeWorkflow::PHP_TASK_QUEUE);
        $this->harness = new WorkerTestHarness($this->worker);
        $this->codec = new AvroPayloadCodec;
    }

    public function test_embedded_and_service_mode_use_the_same_durations(): void
    {
        $this->assertSame(
            $this->defaults(EmbeddedConversationWorkflow::class, 'handle'),
            $this->defaults(ConversationWorkflow::class, 'run'),
        );
        $this->assertSame(
            ['turnsPerRun' => 3, 'inactivityTimeout' => '1 hour', 'approvalTimeout' => '5 minutes'],
            $this->defaults(ConversationWorkflow::class, 'run'),
        );
    }

    public function test_handlers_are_registered_with_the_laravel_bridge(): void
    {
        $this->harness->assertRegistered('workflow', ConversationWorkflow::TYPE);
        $this->harness->assertRegistered('signal', 'message', ConversationWorkflow::TYPE);
        $this->harness->assertRegistered('signal', 'decision', ConversationWorkflow::TYPE);
        $this->harness->assertRegistered('query', 'status', ConversationWorkflow::TYPE);

        foreach ([ConversationActivities::MODEL, ConversationActivities::RECORD_REPLY, ConversationActivities::APPLY_APPROVED_ACTION] as $activity) {
            $this->assertContains($activity, $this->worker->contracts()['activities']);
        }
    }

    public function test_friendly_durations_are_recorded_as_whole_seconds(): void
    {
        $this->start([]);

        $wait = $this->lastEvent('ConditionWaitOpened');
        $this->assertSame('message-1', $wait['condition_key']);
        $this->assertSame(3600, $wait['timeout_seconds']);

        $this->signal('message', 'Please refund my order');

        $this->assertSame(30, $this->lastScheduledActivity(ConversationActivities::MODEL)['start_to_close_timeout']);
        $wait = $this->lastEvent('ConditionWaitOpened');
        $this->assertSame('decision-1', $wait['condition_key']);
        $this->assertSame(300, $wait['timeout_seconds']);
    }

    public function test_ordered_input_human_approval_and_status_query(): void
    {
        $this->start([[], 10]);

        $this->signal('message', 'Hello there');
        $this->assertSame(['Turn 1: you said "Hello there".'], $this->replies());

        $this->signal('message', 'Please refund my order');
        $status = $this->queryStatus();
        $this->assertSame('awaiting_approval', $status['phase']);
        $this->assertSame(2, $status['messages_received']);
        $this->assertSame(1, $status['replies_this_run']);
        $this->assertSame('refund', $status['pending_proposal']['action']);
        $this->assertSame(2, $status['pending_proposal']['turn']);

        // A decision for another proposal is ignored; the named one counts.
        $this->signal('decision', 'not-this-proposal', true, 'Stale');
        $this->assertSame('awaiting_approval', $this->queryStatus()['phase']);
        $this->signal('decision', $status['pending_proposal']['id'], true, 'Taylor');

        $this->assertSame([
            'Turn 1: you said "Hello there".',
            'Turn 2: I can refund the most recent order once a reviewer approves. Taylor approved it. Receipt: conversation:'.self::WORKFLOW_ID.':2:action.',
        ], $this->replies());
        $this->assertStringContainsString('"approved_by":"Taylor"', ConversationRecord::query()->where('kind', 'action')->sole()->content);
        $this->assertSame('running', $this->queryStatus()['phase']);
        $this->assertSame('conversation:'.self::WORKFLOW_ID.':2:reply', $this->queryStatus()['last_reply_reference']);

        $this->signal('message', ConversationWorkflow::END_COMMAND);

        $this->assertSame([
            'type' => 'complete_workflow',
            'value' => [
                'outcome' => 'ended_by_user',
                'generation' => 1,
                'turns' => 2,
                'last_reply_reference' => 'conversation:'.self::WORKFLOW_ID.':2:reply',
            ],
        ], $this->closed);
    }

    public function test_undecided_proposal_changes_nothing_after_its_timeout(): void
    {
        $this->start([]);
        $this->signal('message', 'Refund it please');

        $this->timeOutOpenWait();

        $this->assertSame(
            ['Turn 1: I can refund the most recent order once a reviewer approves. No decision arrived in time, so nothing was changed.'],
            $this->replies(),
        );
        $this->assertSame(0, ConversationRecord::query()->where('kind', 'action')->count());
        $this->assertNull($this->queryStatus()['pending_proposal']);

        // A late decision for the expired proposal changes nothing either.
        $this->signal('decision', $this->proposalIds()[0], true, 'Late');
        $this->assertSame(0, ConversationRecord::query()->where('kind', 'action')->count());
    }

    public function test_a_wrong_decision_keeps_the_five_minute_approval_deadline(): void
    {
        $this->start([]);
        $this->signal('message', 'Please refund my order');
        $this->assertSame(300, $this->lastEvent('ConditionWaitOpened')['timeout_seconds']);

        // Any signal wakes the open wait. The predicate is still false, so the
        // worker reopens the wait with the time left, not another five minutes.
        $this->advance(120);
        $this->signal('decision', 'not-this-proposal', true, 'Stale');
        $reopened = $this->lastEvent('ConditionWaitOpened');
        $this->assertSame('decision-1', $reopened['condition_key']);
        $this->assertSame(180, $reopened['timeout_seconds']);

        $this->advance(150);
        $this->signal('decision', 'still-not-this-proposal', true, 'Stale');
        $this->assertSame(30, $this->lastEvent('ConditionWaitOpened')['timeout_seconds']);

        $this->advance(30);
        $this->timeOutOpenWait();

        $this->assertSame(
            ['Turn 1: I can refund the most recent order once a reviewer approves. No decision arrived in time, so nothing was changed.'],
            $this->replies(),
        );
    }

    public function test_conversation_ends_after_the_inactivity_timeout(): void
    {
        $this->start([]);

        $this->timeOutOpenWait();

        $this->assertSame('complete_workflow', $this->closed['type']);
        $this->assertSame('idle_timeout', $this->closed['value']['outcome']);
    }

    public function test_messages_sent_together_are_applied_in_order(): void
    {
        $this->start([[], 10]);

        $this->record('SignalReceived', ['signal_name' => 'message', 'value' => $this->codec->envelope(['first'])]);
        $this->signal('message', 'second');

        $this->assertSame(['Turn 1: you said "first".', 'Turn 2: you said "second".'], $this->replies());
    }

    public function test_continue_as_new_carries_explicit_state_and_unapplied_input(): void
    {
        $this->start([[], 1]);

        // Both messages are accepted before the first turn's reply is recorded.
        $this->record('SignalReceived', ['signal_name' => 'message', 'value' => $this->codec->envelope(['one'])]);
        $this->signal('message', 'two');

        $this->assertSame('continue_as_new', $this->closed['type']);
        [$state, $turnsPerRun, $inactivityTimeout, $approvalTimeout] = $this->closed['value'];
        $this->assertSame(2, $state['generation']);
        $this->assertSame(1, $state['turns']);
        $this->assertSame('one', $state['summary']);
        $this->assertSame(['two'], $state['queued_messages']);
        $this->assertSame([1, '1 hour', '5 minutes'], [$turnsPerRun, $inactivityTimeout, $approvalTimeout]);

        // The next run applies the carried message without waiting for a signal.
        $this->start([$state, 1, $inactivityTimeout, $approvalTimeout]);

        $this->assertSame(['Turn 1: you said "one".', 'Turn 2: you said "two".'], $this->replies());
        $this->assertSame(2, $this->closed['value'][0]['turns']);
        $this->assertSame([], $this->closed['value'][0]['queued_messages']);
    }

    public function test_activities_are_idempotent_per_workflow_and_turn(): void
    {
        $context = $this->activityContext(ConversationActivities::RECORD_REPLY);
        $record = $this->worker->registeredHandler('activity', ConversationActivities::RECORD_REPLY);

        $first = $record($context, 1, 'Hello');
        $second = $record($context, 1, 'Hello');

        $this->assertSame('conversation:'.self::WORKFLOW_ID.':1:reply', $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, ConversationRecord::query()->count());
    }

    /**
     * @param  list<mixed>  $input
     */
    private function start(array $input): void
    {
        $this->history = [];
        $this->nextSequence = 1;
        $this->clock = new DateTimeImmutable('2026-11-04T02:36:05.295+00:00');
        $this->closed = null;
        $this->input = $input;
        $this->process();
    }

    private function signal(string $name, mixed ...$arguments): void
    {
        $this->record('SignalReceived', ['signal_name' => $name, 'value' => $this->codec->envelope($arguments)]);
        $this->process(woken: true);
    }

    private function advance(int $seconds): void
    {
        $this->clock = $this->clock->modify("+{$seconds} seconds");
    }

    private function timeOutOpenWait(): void
    {
        $wait = $this->openWait();
        $this->assertNotNull($wait, 'No condition wait is open.');
        $this->record('ConditionWaitTimedOut', ['sequence' => $wait['sequence'], 'condition_wait_id' => $wait['condition_wait_id']]);
        $this->process();
    }

    /**
     * Runs workflow tasks until the workflow waits or closes.
     *
     * After a signal, a worker whose predicate is still false sends the open
     * wait again. Native Server then records the old wait satisfied and opens
     * a new one with the timeout the worker sent.
     */
    private function process(bool $woken = false): void
    {
        for ($task = 0; $task < 50 && $this->closed === null; $task++) {
            $commands = $this->harness->runWorkflow(
                ConversationWorkflow::TYPE,
                $this->input,
                $this->history,
                ['workflow_id' => self::WORKFLOW_ID, 'run_id' => self::RUN_ID],
            )->commands;
            $wait = $this->openWait();

            if ($wait !== null) {
                $stillWaiting = count($commands) === 1
                    && $commands[0]['type'] === 'open_condition_wait'
                    && $commands[0]['condition_key'] === $wait['condition_key'];

                if ($stillWaiting) {
                    if ($woken) {
                        $this->record('ConditionWaitSatisfied', ['sequence' => $wait['sequence'], 'condition_wait_id' => $wait['condition_wait_id']]);
                        $this->apply($commands[0]);
                    }

                    return;
                }

                $this->record('ConditionWaitSatisfied', ['sequence' => $wait['sequence'], 'condition_wait_id' => $wait['condition_wait_id']]);
            }

            if ($commands === []) {
                return;
            }

            foreach ($commands as $command) {
                $this->apply($command);
            }
        }

        $this->assertNotNull($this->closed ?? $this->openWait(), 'The workflow neither waited nor closed.');
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function apply(array $command): void
    {
        $sequence = $this->nextSequence++;

        switch ($command['type']) {
            case 'schedule_activity':
                $this->record('ActivityScheduled', [
                    'sequence' => $sequence,
                    'activity_type' => $command['activity_type'],
                    'start_to_close_timeout' => $command['start_to_close_timeout'] ?? null,
                ]);
                $handler = $this->worker->registeredHandler('activity', $command['activity_type']);
                $result = $handler(
                    $this->activityContext($command['activity_type']),
                    ...$this->codec->decodeEnvelope($command['arguments']),
                );
                $this->record('ActivityCompleted', ['sequence' => $sequence, 'result' => $this->codec->envelope($result)]);
                break;
            case 'record_side_effect':
                $this->record('SideEffectRecorded', ['sequence' => $sequence, 'result' => $command['result']]);
                break;
            case 'open_condition_wait':
                $this->record('ConditionWaitOpened', [
                    'sequence' => $sequence,
                    'condition_key' => $command['condition_key'] ?? null,
                    'condition_definition_fingerprint' => $command['condition_definition_fingerprint'] ?? null,
                    'timeout_seconds' => $command['timeout_seconds'] ?? null,
                    'condition_wait_id' => 'wait-'.$sequence,
                ]);
                break;
            case 'complete_workflow':
                $this->closed = ['type' => 'complete_workflow', 'value' => $this->codec->decodeEnvelope($command['result'])];
                break;
            case 'continue_as_new':
                $this->closed = ['type' => 'continue_as_new', 'value' => $this->codec->decodeEnvelope($command['arguments'])];
                break;
            default:
                $this->fail('Unexpected workflow command: '.json_encode($command));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(string $type, array $payload): void
    {
        $this->history[] = [
            'event_type' => $type,
            'timestamp' => $this->clock->format('Y-m-d\\TH:i:s.uP'),
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function openWait(): ?array
    {
        $open = null;

        foreach ($this->history as $event) {
            if ($event['event_type'] === 'ConditionWaitOpened') {
                $open = $event['payload'];
            } elseif (in_array($event['event_type'], ['ConditionWaitSatisfied', 'ConditionWaitTimedOut'], true)) {
                $open = null;
            }
        }

        return $open;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastEvent(string $type): array
    {
        $events = array_values(array_filter($this->history, static fn (array $event): bool => $event['event_type'] === $type));
        $this->assertNotSame([], $events, "No {$type} event was recorded.");

        return $events[array_key_last($events)]['payload'];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastScheduledActivity(string $activityType): array
    {
        $events = array_values(array_filter(
            $this->history,
            static fn (array $event): bool => $event['event_type'] === 'ActivityScheduled'
                && $event['payload']['activity_type'] === $activityType,
        ));

        return $events[array_key_last($events)]['payload'];
    }

    /**
     * @return array<string, mixed>
     */
    private function queryStatus(): array
    {
        return $this->harness->runQuery(ConversationWorkflow::TYPE, 'status', [], $this->history);
    }

    /**
     * @return list<string>
     */
    private function proposalIds(): array
    {
        return array_map(
            fn (array $event): string => $this->codec->decodeEnvelope($event['payload']['result'])['id'],
            array_values(array_filter($this->history, static fn (array $event): bool => $event['event_type'] === 'SideEffectRecorded')),
        );
    }

    /**
     * @return list<string>
     */
    private function replies(): array
    {
        return ConversationRecord::query()
            ->where('workflow_id', self::WORKFLOW_ID)
            ->where('kind', 'reply')
            ->orderBy('turn')
            ->pluck('content')
            ->all();
    }

    private function activityContext(string $activityType): ActivityContext
    {
        return new ActivityContext(
            new Client('http://127.0.0.1:8080'),
            'test-task',
            'test-attempt',
            'test-worker',
            $activityType,
            1,
            taskMetadata: ['workflow_id' => self::WORKFLOW_ID, 'run_id' => self::RUN_ID],
        );
    }

    /**
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function defaults(string $class, string $method): array
    {
        $defaults = [];

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable() && $parameter->getName() !== 'state') {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        return $defaults;
    }
}
