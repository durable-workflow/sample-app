<?php

declare(strict_types=1);

namespace App\Workflows\Conversation;

use App\Models\ConversationRecord;

/**
 * External payload storage shared by the embedded and service-mode
 * conversations.
 *
 * Activities are at-least-once: a worker can write a row and then lose its
 * lease before reporting completion, and the retry runs this code again. Each
 * reference is derived from the workflow ID and the conversation-wide turn
 * number, so a repeated execution reuses the same row.
 */
final class ConversationStore
{
    public static function replyReference(string $workflowId, int $turn): string
    {
        return sprintf('conversation:%s:%d:reply', $workflowId, $turn);
    }

    public static function actionReference(string $workflowId, int $turn): string
    {
        return sprintf('conversation:%s:%d:action', $workflowId, $turn);
    }

    public static function recordReply(string $workflowId, string $runId, int $turn, string $content): string
    {
        $reference = self::replyReference($workflowId, $turn);

        ConversationRecord::query()->updateOrCreate(
            ['reference' => $reference],
            [
                'workflow_id' => $workflowId,
                'run_id' => $runId,
                'kind' => 'reply',
                'turn' => $turn,
                'content' => $content,
            ],
        );

        return $reference;
    }

    /**
     * The receipt row stands in for a call to an external system. Pass the
     * same reference to that system as its idempotency key: the activity can
     * run more than once for one approval, and the engine does not make the
     * external effect exactly-once.
     *
     * @param  array{id: string, action: string, description: string, turn: int}  $proposal
     */
    public static function recordApprovedAction(string $workflowId, string $runId, array $proposal, string $reviewer): string
    {
        $reference = self::actionReference($workflowId, $proposal['turn']);

        ConversationRecord::query()->firstOrCreate(
            ['reference' => $reference],
            [
                'workflow_id' => $workflowId,
                'run_id' => $runId,
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
