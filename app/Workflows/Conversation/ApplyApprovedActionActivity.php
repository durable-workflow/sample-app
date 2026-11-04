<?php

declare(strict_types=1);

namespace App\Workflows\Conversation;

use App\Models\ConversationRecord;
use Workflow\V2\Activity;

/**
 * Performs the side effect a reviewer approved.
 *
 * The receipt row stands in for a call to an external system. Pass the same
 * reference to that system as its idempotency key: the activity can run more
 * than once for one approval, and the engine does not make the external
 * effect exactly-once.
 */
class ApplyApprovedActionActivity extends Activity
{
    public int $tries = 3;

    /**
     * @param  array{id: string, action: string, description: string, turn: int}  $proposal
     */
    public function handle(array $proposal, string $reviewer): string
    {
        $reference = ConversationWorkflow::actionReference($this->workflowId(), $proposal['turn']);

        ConversationRecord::query()->firstOrCreate(
            ['reference' => $reference],
            [
                'workflow_id' => $this->workflowId(),
                'run_id' => $this->runId(),
                'kind' => 'action',
                'turn' => $proposal['turn'],
                'content' => json_encode([
                    'action' => $proposal['action'],
                    'description' => $proposal['description'],
                    'approved_by' => $reviewer,
                ], JSON_THROW_ON_ERROR),
            ],
        );

        return $reference;
    }
}
