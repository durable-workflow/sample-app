<?php

declare(strict_types=1);

namespace App\Workflows\Conversation;

use Workflow\V2\Activity;

/**
 * Performs the side effect a reviewer approved, keyed by an idempotency
 * reference so a repeated execution does not apply it twice.
 */
class ApplyApprovedActionActivity extends Activity
{
    public int $tries = 3;

    /**
     * @param  array{id: string, action: string, description: string, turn: int}  $proposal
     */
    public function handle(array $proposal, string $reviewer): string
    {
        return ConversationStore::recordApprovedAction($this->workflowId(), $this->runId(), $proposal, $reviewer);
    }
}
