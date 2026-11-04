<?php

declare(strict_types=1);

namespace App\Workflows\ServiceMode\Conversation;

use DurableWorkflow\Attribute\Query;
use DurableWorkflow\Attribute\Signal;
use DurableWorkflow\Attribute\Workflow;
use DurableWorkflow\Codec\AvroPayloadCodec;
use DurableWorkflow\Worker\QueryContext;
use DurableWorkflow\Worker\WorkflowContext;
use Illuminate\Support\Str;

/**
 * The embedded `App\Workflows\Conversation\ConversationWorkflow`, written for
 * a PHP service-mode worker with the same turns, approval rule and durations.
 *
 * - User input arrives as `message` signals. The workflow reads committed
 *   signals from history with `$context->signals()` and applies them one turn
 *   at a time, in the order the server accepted them.
 * - The conversation ends after `$inactivityTimeout` ('1 hour') without a
 *   message. A proposal nobody decides within `$approvalTimeout`
 *   ('5 minutes') changes nothing.
 * - A proposed side effect waits for a `decision` signal that names the
 *   proposal's ID. Decisions for any other proposal are ignored.
 * - After `$turnsPerRun` turns the run continues as new with the explicit
 *   state below. Messages this run accepted but did not apply are carried in
 *   that state, in order.
 *
 * History holds every signal the run has accepted, including ones that arrive
 * after a wait was recorded. A replayed wait takes its recorded outcome
 * instead of evaluating its predicate again, and messages are consumed by
 * position, so a replay applies the same messages in the same turns. Each
 * wait has a stable key, as the SDK recommends for repeated waits.
 */
final class ConversationWorkflow
{
    public const TYPE = 'sample.service-mode.conversation';

    public const END_COMMAND = '/end';

    private const NO_DECISION = 'No decision arrived in time, so nothing was changed.';

    private const ACTIVITY_OPTIONS = [
        'start_to_close_timeout' => '30 seconds',
        // Allow a redelivery after a lost worker's lease expires.
        'retry_policy' => ['max_attempts' => 3],
    ];

    /**
     * Conversation state that crosses continue-as-new.
     *
     * @var array{
     *     generation: int,
     *     turns: int,
     *     summary: string,
     *     last_reply_reference: string|null,
     *     queued_messages: list<string>
     * }
     */
    private const INITIAL_STATE = [
        'generation' => 1,
        'turns' => 0,
        'summary' => '',
        'last_reply_reference' => null,
        'queued_messages' => [],
    ];

    /**
     * @param  array<string, mixed>  $state  state handed over by continue-as-new
     * @return array{outcome: string, generation: int, turns: int, last_reply_reference: string|null}
     */
    #[Workflow(self::TYPE)]
    public function run(
        WorkflowContext $context,
        array $state = [],
        int $turnsPerRun = 3,
        string $inactivityTimeout = '1 hour',
        string $approvalTimeout = '5 minutes',
    ): array {
        $state = array_replace(self::INITIAL_STATE, $state);
        $queued = array_values(array_map('strval', $state['queued_messages']));
        $state['queued_messages'] = [];

        $inbox = static fn (): array => [
            ...$queued,
            ...array_map(
                static fn (array $arguments): string => (string) ($arguments[0] ?? ''),
                $context->signals('message'),
            ),
        ];
        $applied = 0;
        $turnsThisRun = 0;

        while (true) {
            if ($turnsThisRun >= $turnsPerRun) {
                $context->continueAsNew([
                    array_replace($state, [
                        'generation' => $state['generation'] + 1,
                        'queued_messages' => array_slice($inbox(), $applied),
                    ]),
                    $turnsPerRun,
                    $inactivityTimeout,
                    $approvalTimeout,
                ]);
            }

            $arrived = $context->waitCondition(
                static fn (): bool => count($inbox()) > $applied,
                key: 'message-'.($applied + 1),
                timeout: $inactivityTimeout,
            );

            if (! $arrived) {
                return self::finish($state, 'idle_timeout');
            }

            $text = $inbox()[$applied];
            $applied++;

            if (trim($text) === self::END_COMMAND) {
                return self::finish($state, 'ended_by_user');
            }

            $state = $this->takeTurn($context, $state, $text, $approvalTimeout);
            $turnsThisRun++;
        }
    }

    /**
     * Explains where the current run is without changing it.
     *
     * Query handlers receive committed history, not workflow memory, so this
     * reads the recorded activity results and proposal.
     *
     * @return array{
     *     phase: string,
     *     messages_received: int,
     *     replies_this_run: int,
     *     last_reply_reference: string|null,
     *     pending_proposal: array{id: string, action: string, description: string, turn: int}|null
     * }
     */
    #[Query('status')]
    public function status(QueryContext $context): array
    {
        $codec = new AvroPayloadCodec;
        $activityTypes = [];
        $repliesThisRun = 0;
        $lastReply = null;
        $pendingProposal = null;
        $closed = false;

        foreach ($context->history as $event) {
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = (string) ($payload['sequence'] ?? '');

            switch ($event['event_type'] ?? $event['type'] ?? null) {
                case 'SideEffectRecorded':
                    $pendingProposal = $codec->decodeEnvelope($payload['result'] ?? null);
                    break;
                case 'ActivityScheduled':
                    // The next activity after a proposal runs once its wait resolves.
                    $pendingProposal = null;
                    $activityTypes[$sequence] = $payload['activity_type'] ?? null;
                    break;
                case 'ActivityCompleted':
                    if (($activityTypes[$sequence] ?? null) === ConversationActivities::RECORD_REPLY) {
                        $lastReply = $codec->decodeEnvelope($payload['result'] ?? null);
                        $repliesThisRun++;
                    }
                    break;
                case 'WorkflowCompleted':
                case 'WorkflowContinuedAsNew':
                    $closed = true;
                    break;
            }
        }

        return [
            'phase' => match (true) {
                $closed => 'closed',
                $pendingProposal !== null => 'awaiting_approval',
                default => 'running',
            },
            'messages_received' => count(array_filter(
                $context->events('SignalReceived'),
                static fn (array $event): bool => ($event['payload']['signal_name'] ?? null) === 'message',
            )),
            'replies_this_run' => $repliesThisRun,
            'last_reply_reference' => is_string($lastReply) ? $lastReply : null,
            'pending_proposal' => is_array($pendingProposal) ? $pendingProposal : null,
        ];
    }

    #[Signal('message')]
    public function message(string $text): void
    {
        // Declared for server admission. run() reads committed signals from history.
    }

    #[Signal('decision')]
    public function decision(string $proposalId, bool $approved, string $reviewer): void
    {
        // Declared for server admission. run() reads committed signals from history.
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function takeTurn(WorkflowContext $context, array $state, string $text, string $approvalTimeout): array
    {
        $turn = $state['turns'] + 1;

        $model = $context->activity(
            ConversationActivities::MODEL,
            [$state['summary'], $text, $turn],
            self::ACTIVITY_OPTIONS,
        );
        $reply = $model['reply'];

        if ($model['proposal'] !== null) {
            $reply .= ' '.$this->awaitApproval($context, $model['proposal'] + ['turn' => $turn], $approvalTimeout);
        }

        $reference = $context->activity(
            ConversationActivities::RECORD_REPLY,
            [$turn, $reply],
            self::ACTIVITY_OPTIONS,
        );

        return array_replace($state, [
            'turns' => $turn,
            'summary' => $model['summary'],
            'last_reply_reference' => $reference,
        ]);
    }

    /**
     * Waits for a decision on this proposal only.
     *
     * The proposal ID is recorded once by a side effect and reported by the
     * `status` query. A decision must repeat it to count, so a stale, early,
     * duplicate or retried decision cannot answer a different proposal.
     *
     * @param  array{action: string, description: string, turn: int}  $proposal
     */
    private function awaitApproval(WorkflowContext $context, array $proposal, string $approvalTimeout): string
    {
        /** @var array{id: string, action: string, description: string, turn: int} $proposal */
        $proposal = $context->sideEffect(static fn (): array => ['id' => (string) Str::uuid()] + $proposal);

        $decision = static function () use ($context, $proposal): ?array {
            foreach ($context->signals('decision') as $arguments) {
                [$proposalId, $approved, $reviewer] = array_pad(array_values($arguments), 3, null);

                if ($proposalId === $proposal['id']) {
                    return ['approved' => (bool) $approved, 'reviewer' => (string) $reviewer];
                }
            }

            return null;
        };

        $decided = $context->waitCondition(
            static fn (): bool => $decision() !== null,
            key: 'decision-'.$proposal['turn'],
            timeout: $approvalTimeout,
        );

        if (! $decided) {
            return self::NO_DECISION;
        }

        ['approved' => $approved, 'reviewer' => $reviewer] = $decision();

        if (! $approved) {
            return sprintf('%s declined it, so nothing was changed.', $reviewer);
        }

        $receipt = $context->activity(
            ConversationActivities::APPLY_APPROVED_ACTION,
            [$proposal, $reviewer],
            self::ACTIVITY_OPTIONS,
        );

        return sprintf('%s approved it. Receipt: %s.', $reviewer, $receipt);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{outcome: string, generation: int, turns: int, last_reply_reference: string|null}
     */
    private static function finish(array $state, string $outcome): array
    {
        return [
            'outcome' => $outcome,
            'generation' => $state['generation'],
            'turns' => $state['turns'],
            'last_reply_reference' => $state['last_reply_reference'],
        ];
    }
}
