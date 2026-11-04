<?php

declare(strict_types=1);

namespace App\Workflows\Conversation;

use Workflow\V2\Activity;

/**
 * Runs the deterministic fake model as an embedded activity, so a replay
 * reuses the recorded result instead of calling the model again.
 */
class FakeModelActivity extends Activity
{
    // Allow a redelivery after a lost worker's lease expires.
    public int $tries = 3;

    /**
     * @return array{reply: string, proposal: array{action: string, description: string}|null, summary: string}
     */
    public function handle(string $summary, string $text, int $turn): array
    {
        return FakeModel::respond($summary, $text, $turn);
    }
}
