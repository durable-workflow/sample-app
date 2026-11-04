<?php

declare(strict_types=1);

namespace App\Workflows\Conversation;

use Carbon\CarbonInterval;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Workflow;

use function Workflow\V2\activity;
use function Workflow\V2\await;
use function Workflow\V2\continueAsNew;
use function Workflow\V2\now;
use function Workflow\V2\uuid4;

/**
 * A long-lived conversation that survives worker replacement and bounds its
 * history.
 *
 * - User input arrives as `message` signals and is applied one turn at a time,
 *   in the order the signals were accepted.
 * - Model work runs in an activity, so a replay reuses the recorded result
 *   instead of calling the model again.
 * - A proposed side effect waits for a human `decision` signal that names the
 *   proposal's ID before an activity performs it. Decisions for any other
 *   proposal, including stale, early, duplicate or retried ones, are ignored.
 * - The conversation ends after `$inactivityTimeout` ('1 hour') without a
 *   message. A proposal nobody decides within `$approvalTimeout`
 *   ('5 minutes') changes nothing. The service-mode
 *   `App\Workflows\ServiceMode\Conversation\ConversationWorkflow` uses the
 *   same durations.
 * - Each reply is written to external storage by an activity. History keeps
 *   only the returned payload reference, and the `status` query exposes the
 *   latest one.
 * - After `$turnsPerRun` turns the run continues as new, carrying only the
 *   explicit state below. Accepted messages that this run has not applied move
 *   to the next run in order.
 */
#[Signal('message', [
    ['name' => 'text', 'type' => 'string'],
])]
#[Signal('decision', [
    ['name' => 'proposalId', 'type' => 'string'],
    ['name' => 'approved', 'type' => 'bool'],
    ['name' => 'reviewer', 'type' => 'string'],
])]
class ConversationWorkflow extends Workflow
{
    public const END_COMMAND = '/end';

    /**
     * Conversation state that crosses continue-as-new. Anything else held by
     * this object is rebuilt by replay and is not carried forward.
     *
     * @var array{
     *     generation: int,
     *     turns: int,
     *     summary: string,
     *     last_reply_reference: string|null,
     *     pending_proposal: array{id: string, action: string, description: string, turn: int}|null,
     *     outcome: string|null
     * }
     */
    private array $state = [
        'generation' => 1,
        'turns' => 0,
        'summary' => '',
        'last_reply_reference' => null,
        'pending_proposal' => null,
        'outcome' => null,
    ];

    private string $phase = 'starting';

    private int $turnsThisRun = 0;

    private int $ignoredDecisions = 0;

    public static function replyReference(string $workflowId, int $turn): string
    {
        return ConversationStore::replyReference($workflowId, $turn);
    }

    public static function actionReference(string $workflowId, int $turn): string
    {
        return ConversationStore::actionReference($workflowId, $turn);
    }

    /**
     * Explains where the conversation is without changing it.
     *
     * @return array<string, mixed>
     */
    #[QueryMethod('status')]
    public function status(): array
    {
        return [
            'phase' => $this->phase,
            'generation' => $this->state['generation'],
            'turns' => $this->state['turns'],
            'turns_this_run' => $this->turnsThisRun,
            'summary' => $this->state['summary'],
            'pending_proposal' => $this->state['pending_proposal'],
            'ignored_decisions' => $this->ignoredDecisions,
            'last_reply_reference' => $this->state['last_reply_reference'],
            'outcome' => $this->state['outcome'],
        ];
    }

    /**
     * @param  array<string, mixed>  $state  state handed over by continue-as-new
     * @return array{outcome: string|null, generation: int, turns: int, last_reply_reference: string|null}
     */
    public function handle(
        array $state = [],
        int $turnsPerRun = 3,
        string $inactivityTimeout = '1 hour',
        string $approvalTimeout = '5 minutes',
    ): array {
        $this->state = array_replace($this->state, $state);

        while (true) {
            if ($this->turnsThisRun >= $turnsPerRun) {
                $this->phase = 'continuing_as_new';

                return continueAsNew(
                    array_replace($this->state, ['generation' => $this->state['generation'] + 1]),
                    $turnsPerRun,
                    $inactivityTimeout,
                    $approvalTimeout,
                );
            }

            $this->phase = 'waiting_for_input';
            $text = await('message', $inactivityTimeout);

            if ($text === null) {
                return $this->finish('idle_timeout');
            }

            if (trim((string) $text) === self::END_COMMAND) {
                return $this->finish('ended_by_user');
            }

            $this->takeTurn((string) $text, $approvalTimeout);
        }
    }

    private function takeTurn(string $text, string $approvalTimeout): void
    {
        $turn = $this->state['turns'] + 1;

        $this->phase = 'thinking';
        $model = activity(FakeModelActivity::class, $this->state['summary'], $text, $turn);
        $reply = $model['reply'];

        if ($model['proposal'] !== null) {
            $reply .= ' '.$this->awaitApproval($model['proposal'] + ['turn' => $turn], $approvalTimeout);
        }

        $this->phase = 'recording_reply';
        $reference = activity(RecordReplyActivity::class, $turn, $reply);

        $this->state['turns'] = $turn;
        $this->state['summary'] = $model['summary'];
        $this->state['last_reply_reference'] = $reference;
        $this->turnsThisRun++;
    }

    /**
     * Waits for a decision on this proposal only.
     *
     * Signals are buffered by name, so a `decision` sent before this proposal
     * existed, sent twice, or retried after an uncertain response would
     * otherwise answer whichever proposal waits next. The proposal ID is
     * recorded in history and reported by the `status` query; a decision must
     * repeat it to count. Ignored decisions do not extend the deadline.
     *
     * @param  array{action: string, description: string, turn: int}  $proposal
     */
    private function awaitApproval(array $proposal, string $approvalTimeout): string
    {
        $proposal = ['id' => (string) uuid4()] + $proposal;
        $this->state['pending_proposal'] = $proposal;
        $this->phase = 'awaiting_approval';
        $deadline = now()->getTimestamp() + (int) ceil(CarbonInterval::fromString($approvalTimeout)->totalSeconds);

        while (true) {
            $remaining = $deadline - now()->getTimestamp();
            $decision = $remaining > 0 ? await('decision', $remaining) : null;

            if ($decision === null) {
                $this->state['pending_proposal'] = null;

                return 'No decision arrived in time, so nothing was changed.';
            }

            [$proposalId, $approved, $reviewer] = array_values((array) $decision);

            if ($proposalId === $proposal['id']) {
                break;
            }

            $this->ignoredDecisions++;
        }

        $this->state['pending_proposal'] = null;

        if (! $approved) {
            return sprintf('%s declined it, so nothing was changed.', $reviewer);
        }

        $this->phase = 'applying_action';
        $receipt = activity(ApplyApprovedActionActivity::class, $proposal, (string) $reviewer);

        return sprintf('%s approved it. Receipt: %s.', $reviewer, $receipt);
    }

    /**
     * @return array{outcome: string, generation: int, turns: int, last_reply_reference: string|null}
     */
    private function finish(string $outcome): array
    {
        $this->state['outcome'] = $outcome;
        $this->phase = 'completed';

        return [
            'outcome' => $outcome,
            'generation' => $this->state['generation'],
            'turns' => $this->state['turns'],
            'last_reply_reference' => $this->state['last_reply_reference'],
        ];
    }
}
