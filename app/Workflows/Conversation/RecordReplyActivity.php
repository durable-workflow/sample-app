<?php

declare(strict_types=1);

namespace App\Workflows\Conversation;

use App\Models\ConversationRecord;
use Workflow\V2\Activity;

/**
 * Checkpoints one assistant reply outside workflow history.
 *
 * Activities are at-least-once: a worker can write the row and then lose its
 * lease before reporting completion, and the retry runs this code again. The
 * reference is derived from the workflow ID and the conversation-wide turn
 * number, so a repeated execution updates the same row instead of adding a
 * second reply.
 */
class RecordReplyActivity extends Activity
{
    public int $tries = 3;

    public function handle(int $turn, string $content): string
    {
        $reference = ConversationWorkflow::replyReference($this->workflowId(), $turn);

        ConversationRecord::query()->updateOrCreate(
            ['reference' => $reference],
            [
                'workflow_id' => $this->workflowId(),
                'run_id' => $this->runId(),
                'kind' => 'reply',
                'turn' => $turn,
                'content' => $content,
            ],
        );

        return $reference;
    }
}
