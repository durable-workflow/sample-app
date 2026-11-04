<?php

declare(strict_types=1);

namespace App\Workflows\ServiceMode\Conversation;

use App\Workflows\Conversation\ConversationStore;
use App\Workflows\Conversation\FakeModel;
use DurableWorkflow\Attribute\Activity;
use DurableWorkflow\Worker\ActivityContext;
use LogicException;

/**
 * Service-mode activities for the conversation. They run the same fake model
 * and write the same idempotent records as the embedded activities.
 */
final class ConversationActivities
{
    public const MODEL = 'sample.service-mode.conversation.model';

    public const RECORD_REPLY = 'sample.service-mode.conversation.record-reply';

    public const APPLY_APPROVED_ACTION = 'sample.service-mode.conversation.apply-approved-action';

    /**
     * @return array{reply: string, proposal: array{action: string, description: string}|null, summary: string}
     */
    #[Activity(self::MODEL)]
    public function model(ActivityContext $context, string $summary, string $text, int $turn): array
    {
        return FakeModel::respond($summary, $text, $turn);
    }

    #[Activity(self::RECORD_REPLY)]
    public function recordReply(ActivityContext $context, int $turn, string $content): string
    {
        [$workflowId, $runId] = self::identity($context);

        return ConversationStore::recordReply($workflowId, $runId, $turn, $content);
    }

    /**
     * @param  array{id: string, action: string, description: string, turn: int}  $proposal
     */
    #[Activity(self::APPLY_APPROVED_ACTION)]
    public function applyApprovedAction(ActivityContext $context, array $proposal, string $reviewer): string
    {
        [$workflowId, $runId] = self::identity($context);

        return ConversationStore::recordApprovedAction($workflowId, $runId, $proposal, $reviewer);
    }

    /**
     * The records are keyed by workflow ID, which the server sends with every
     * activity claim.
     *
     * @return array{0: string, 1: string}
     */
    private static function identity(ActivityContext $context): array
    {
        if ($context->workflowId === null || $context->workflowId === '') {
            throw new LogicException('Conversation activities need the workflow ID from the activity claim.');
        }

        return [$context->workflowId, (string) $context->runId];
    }
}
