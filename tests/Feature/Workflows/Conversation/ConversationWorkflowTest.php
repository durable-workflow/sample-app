<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows\Conversation;

use App\Models\ConversationRecord;
use App\Workflows\Conversation\ConversationWorkflow;
use App\Workflows\Conversation\FakeModelActivity;
use App\Workflows\Conversation\RecordReplyActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityTaskClaimer;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

/**
 * Drives the conversation the way queue workers would, one durable task at a
 * time, so each test can stop a "worker" at a chosen point and let another
 * one continue.
 */
class ConversationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('workflows.v2.task_dispatch_mode', 'poll');
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ordered_input_human_approval_and_status_query(): void
    {
        $workflow = $this->startConversation('conversation-approval', turnsPerRun: 10);

        $this->assertSame('waiting_for_input', $workflow->query('status')['phase']);

        // Two messages are accepted before any worker runs; they are applied in
        // acceptance order, one turn each.
        $workflow->signal('message', 'Hello there');
        $workflow->signal('message', 'Please refund my last order');
        $this->drain();

        $status = $workflow->query('status');
        $this->assertSame('awaiting_approval', $status['phase']);
        $this->assertSame(1, $status['turns']);
        $proposalId = $status['pending_proposal']['id'];
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $proposalId);
        $this->assertSame(
            ['id' => $proposalId, 'action' => 'refund', 'description' => 'Refund the most recent order', 'turn' => 2],
            $status['pending_proposal'],
        );
        $this->assertSame(0, ConversationRecord::query()->where('kind', 'action')->count());

        $workflow->signal('decision', $proposalId, true, 'Taylor');
        $this->drain();

        $status = $workflow->query('status');
        $this->assertSame('waiting_for_input', $status['phase']);
        $this->assertSame(2, $status['turns']);
        $this->assertNull($status['pending_proposal']);

        $this->assertSame([
            'Turn 1: you said "Hello there".',
            'Turn 2: I can refund the most recent order once a reviewer approves. Taylor approved it. Receipt: conversation:conversation-approval:2:action.',
        ], $this->replies('conversation-approval'));
        $this->assertSame(1, ConversationRecord::query()->where('kind', 'action')->count());

        // History carries the reference; the reply text lives in the record.
        $this->assertSame('conversation:conversation-approval:2:reply', $status['last_reply_reference']);

        $workflow->signal('message', ConversationWorkflow::END_COMMAND);
        $this->drain();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'outcome' => 'ended_by_user',
            'generation' => 1,
            'turns' => 2,
            'last_reply_reference' => 'conversation:conversation-approval:2:reply',
        ], $workflow->output());
    }

    public function test_declined_proposal_performs_no_action(): void
    {
        $workflow = $this->startConversation('conversation-declined', turnsPerRun: 10);

        $workflow->signal('message', 'Refund it please');
        $this->drain();
        $workflow->signal('decision', $this->pendingProposalId($workflow), false, 'Morgan');
        $this->drain();

        $this->assertSame(
            ['Turn 1: I can refund the most recent order once a reviewer approves. Morgan declined it, so nothing was changed.'],
            $this->replies('conversation-declined'),
        );
        $this->assertSame(0, ConversationRecord::query()->where('kind', 'action')->count());
    }

    public function test_decision_sent_with_no_pending_proposal_is_ignored(): void
    {
        $workflow = $this->startConversation('conversation-stale-decision', turnsPerRun: 10);

        // An approval arrives while nothing is proposed, e.g. a late retry of
        // an earlier decision. It must not approve the next proposal.
        $workflow->signal('decision', 'not-a-pending-proposal', true, 'Stale');
        $workflow->signal('message', 'Please refund my order');
        $this->drain();

        $status = $workflow->query('status');
        $this->assertSame('awaiting_approval', $status['phase']);
        $this->assertSame(1, $status['ignored_decisions']);
        $this->assertSame(0, ConversationRecord::query()->where('kind', 'action')->count());

        $workflow->signal('decision', $status['pending_proposal']['id'], true, 'Taylor');
        $this->drain();

        $this->assertSame(
            ['Turn 1: I can refund the most recent order once a reviewer approves. Taylor approved it. Receipt: conversation:conversation-stale-decision:1:action.'],
            $this->replies('conversation-stale-decision'),
        );
        $this->assertStringContainsString('"approved_by":"Taylor"', ConversationRecord::query()->where('kind', 'action')->sole()->content);
    }

    public function test_duplicate_decision_does_not_approve_the_next_proposal(): void
    {
        $workflow = $this->startConversation('conversation-duplicate-decision', turnsPerRun: 10);

        $workflow->signal('message', 'Refund the first order');
        $this->drain();
        $firstProposalId = $this->pendingProposalId($workflow);

        // The reviewer's approval is sent twice, e.g. retried after a lost response.
        $workflow->signal('decision', $firstProposalId, true, 'Taylor');
        $workflow->signal('decision', $firstProposalId, true, 'Taylor');
        $this->drain();

        $workflow->signal('message', 'Refund the second order too');
        $this->drain();

        $status = $workflow->query('status');
        $this->assertSame('awaiting_approval', $status['phase']);
        $this->assertSame(2, $status['pending_proposal']['turn']);
        $this->assertNotSame($firstProposalId, $status['pending_proposal']['id']);
        $this->assertSame(1, $status['ignored_decisions']);
        $this->assertSame(1, ConversationRecord::query()->where('kind', 'action')->count());
    }

    public function test_proposal_id_is_stable_across_replay(): void
    {
        $workflow = $this->startConversation('conversation-replayed-proposal', turnsPerRun: 10);

        $workflow->signal('message', 'Please refund my order');
        $this->drain();

        // Each query replays the workflow code from history.
        $proposalId = $this->pendingProposalId($workflow);
        $this->assertSame($proposalId, $this->pendingProposalId($workflow));

        // A non-matching decision makes a worker run another workflow task,
        // which replays the turn that created the proposal.
        $workflow->signal('decision', 'some-other-proposal', true, 'Stale');
        $this->drain();

        $this->assertSame($proposalId, $this->pendingProposalId($workflow));
        $this->assertSame(1, $workflow->query('status')['ignored_decisions']);
        // The ID was generated once and recorded in history.
        $this->assertSame(1, $this->historyCount($workflow->runId(), HistoryEventType::SideEffectRecorded));

        $workflow->signal('decision', $proposalId, true, 'Taylor');
        $this->drain();

        $this->assertSame(1, ConversationRecord::query()->where('kind', 'action')->count());
        $this->assertSame(1, $workflow->query('status')['turns']);
    }

    public function test_continue_as_new_carries_explicit_state_and_unapplied_input(): void
    {
        $workflow = $this->startConversation('conversation-handoff', turnsPerRun: 2);
        $firstRunId = $workflow->runId();

        // Three messages arrive while the first run can take only two turns.
        // The third was accepted but not applied, so it moves to the next run.
        $workflow->signal('message', 'one');
        $workflow->signal('message', 'two');
        $workflow->signal('message', 'three');
        $this->drain();

        $workflow->refresh();
        $this->assertNotSame($firstRunId, $workflow->runId());
        $this->assertSame(2, WorkflowRun::query()->where('workflow_instance_id', 'conversation-handoff')->count());

        $status = $workflow->query('status');
        $this->assertSame('waiting_for_input', $status['phase']);
        $this->assertSame(2, $status['generation']);
        $this->assertSame(3, $status['turns']);
        $this->assertSame(1, $status['turns_this_run']);
        $this->assertSame('one | two | three', $status['summary']);

        $this->assertSame([
            'Turn 1: you said "one".',
            'Turn 2: you said "two".',
            'Turn 3: you said "three".',
        ], $this->replies('conversation-handoff'));

        $workflow->signal('message', ConversationWorkflow::END_COMMAND);
        $this->drain();

        $this->assertSame('ended_by_user', $workflow->refresh()->output()['outcome']);
        $this->assertSame(2, $workflow->output()['generation']);
        $this->assertSame(3, $workflow->output()['turns']);
    }

    public function test_worker_lost_while_waiting_for_input(): void
    {
        $workflow = $this->startConversation('conversation-lost-wait', turnsPerRun: 10);
        $runId = $workflow->runId();

        $workflow->signal('message', 'Are you still there?');

        // A worker leases the task that applies the signal, then is killed
        // before it reports anything.
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased->value,
            'leased_at' => now(),
            'lease_owner' => 'worker-that-dies',
            'lease_expires_at' => now()->addMinute(),
        ])->save();
        $this->drain();
        $this->assertSame('waiting_for_input', $workflow->query('status')['phase']);
        $this->assertSame([], $this->replies('conversation-lost-wait'));

        // Once the lease expires, a repair pass hands the same task to a
        // replacement worker.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->repairPass();
        $this->drain();

        $this->assertSame(['Turn 1: you said "Are you still there?".'], $this->replies('conversation-lost-wait'));
        $this->assertSame(1, $workflow->query('status')['turns']);
    }

    public function test_worker_lost_while_model_activity_is_in_flight(): void
    {
        $workflow = $this->startConversation('conversation-lost-activity', turnsPerRun: 10);
        $runId = $workflow->runId();

        $workflow->signal('message', 'Hello');
        $this->runReadyTask($runId, TaskType::Workflow);

        // A worker leases the model activity and is killed before it reports.
        $task = $this->readyActivityTask($runId);
        [$claim] = ActivityTaskClaimer::claim($task->id, 'worker-that-dies');
        $this->assertNotNull($claim);
        $this->assertSame('thinking', $workflow->query('status')['phase']);

        // After the lease expires, the repair pass schedules a retry for
        // another worker.
        Carbon::setTestNow($claim->task->lease_expires_at->copy()->addSecond());
        $this->repairPass();
        Carbon::setTestNow(now()->addSeconds(10));
        $this->drain();

        $this->assertSame(['Turn 1: you said "Hello".'], $this->replies('conversation-lost-activity'));

        /** @var ActivityExecution $model */
        $model = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->where('activity_class', FakeModelActivity::class)
            ->sole();
        $this->assertSame(2, $model->attempt_count);
        $this->assertSame(1, $this->historyCount($runId, HistoryEventType::ActivityRetryScheduled));
        // Each activity execution completes once in history, even though the
        // model activity ran on two workers.
        $this->assertSame(2, $this->historyCount($runId, HistoryEventType::ActivityCompleted));
    }

    public function test_reply_written_by_a_lost_worker_is_not_duplicated(): void
    {
        $workflow = $this->startConversation('conversation-lost-reply', turnsPerRun: 10);
        $runId = $workflow->runId();

        $workflow->signal('message', 'Hello');
        $this->runReadyTask($runId, TaskType::Workflow);
        $this->runReadyTask($runId, TaskType::Activity);
        $this->runReadyTask($runId, TaskType::Workflow);

        // The reply activity's worker writes the row, then dies before it can
        // report completion: the at-least-once case.
        $task = $this->readyActivityTask($runId);
        [$claim] = ActivityTaskClaimer::claim($task->id, 'worker-that-dies');
        $this->assertNotNull($claim);
        $this->assertSame(RecordReplyActivity::class, $claim->execution->activity_class);
        ConversationRecord::query()->create([
            'reference' => ConversationWorkflow::replyReference('conversation-lost-reply', 1),
            'workflow_id' => 'conversation-lost-reply',
            'run_id' => $runId,
            'kind' => 'reply',
            'turn' => 1,
            'content' => 'Turn 1: you said "Hello".',
        ]);

        Carbon::setTestNow($claim->task->lease_expires_at->copy()->addSecond());
        $this->repairPass();
        Carbon::setTestNow(now()->addSeconds(10));
        $this->drain();

        // The retry ran the activity again; the reference keeps one reply.
        $this->assertSame(['Turn 1: you said "Hello".'], $this->replies('conversation-lost-reply'));
        $this->assertSame(1, $workflow->query('status')['turns']);
    }

    private function pendingProposalId(WorkflowStub $workflow): string
    {
        $proposal = $workflow->query('status')['pending_proposal'];
        $this->assertIsArray($proposal);

        return $proposal['id'];
    }

    private function startConversation(string $id, int $turnsPerRun): WorkflowStub
    {
        $workflow = WorkflowStub::make(ConversationWorkflow::class, $id);
        $workflow->start([], $turnsPerRun, 3600);
        $this->drain();

        return $workflow;
    }

    /**
     * @return list<string>
     */
    private function replies(string $workflowId): array
    {
        return ConversationRecord::query()
            ->where('workflow_id', $workflowId)
            ->where('kind', 'reply')
            ->orderBy('turn')
            ->pluck('content')
            ->all();
    }

    private function historyCount(string $runId, HistoryEventType $type): int
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', $type->value)
            ->count();
    }

    private function repairPass(): void
    {
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
        TaskWatchdog::runPass(respectThrottle: false);
    }

    private function readyActivityTask(string $runId): WorkflowTask
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
    }

    private function runReadyTask(string $runId, TaskType $type): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $this->runTask($task);
    }

    /**
     * Runs every task that is due, oldest first, like an idle worker pool.
     */
    private function drain(): void
    {
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            $cutoff = now()->format('Y-m-d H:i:s.u');

            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->where(function ($query) use ($cutoff): void {
                    $query->whereNull('available_at')
                        ->orWhere('available_at', '<=', $cutoff);
                })
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            $this->runTask($task);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }

    private function runTask(WorkflowTask $task): void
    {
        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }
}
