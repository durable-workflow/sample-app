<?php

declare(strict_types=1);

namespace App\Workflows\Conversation;

use Workflow\V2\Activity;

/**
 * Checkpoints one assistant reply outside workflow history. A retried
 * execution updates the same row instead of adding a second reply.
 */
class RecordReplyActivity extends Activity
{
    public int $tries = 3;

    public function handle(int $turn, string $content): string
    {
        return ConversationStore::recordReply($this->workflowId(), $this->runId(), $turn, $content);
    }
}
