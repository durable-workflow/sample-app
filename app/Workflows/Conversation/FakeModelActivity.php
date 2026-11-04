<?php

declare(strict_types=1);

namespace App\Workflows\Conversation;

use Workflow\V2\Activity;

/**
 * Deterministic stand-in for a language-model call.
 *
 * A real model call belongs in an activity because it is slow, can fail and
 * is not deterministic. This fake keeps the sample runnable without a
 * provider account or secrets: the same input always produces the same
 * reply, proposal and summary.
 */
class FakeModelActivity extends Activity
{
    // Allow a redelivery after a lost worker's lease expires.
    public int $tries = 3;

    private const SUMMARY_LIMIT = 200;

    /**
     * @return array{reply: string, proposal: array{action: string, description: string}|null, summary: string}
     */
    public function handle(string $summary, string $text, int $turn): array
    {
        $proposal = preg_match('/\brefund\b/i', $text) === 1
            ? ['action' => 'refund', 'description' => 'Refund the most recent order']
            : null;

        $reply = $proposal === null
            ? sprintf('Turn %d: you said "%s".', $turn, $text)
            : sprintf('Turn %d: I can refund the most recent order once a reviewer approves.', $turn);

        // Bounded running summary: the only conversation context that is
        // carried into the next run by continue-as-new.
        $summary = trim($summary === '' ? $text : $summary.' | '.$text);

        if (mb_strlen($summary) > self::SUMMARY_LIMIT) {
            $summary = mb_substr($summary, -self::SUMMARY_LIMIT);
        }

        return [
            'reply' => $reply,
            'proposal' => $proposal,
            'summary' => $summary,
        ];
    }
}
