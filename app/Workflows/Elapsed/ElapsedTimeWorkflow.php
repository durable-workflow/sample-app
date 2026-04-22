<?php

declare(strict_types=1);

namespace App\Workflows\Elapsed;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;
use function Workflow\V2\sideEffect;

class ElapsedTimeWorkflow extends Workflow
{
    public function handle(): string
    {
        // Clock reads are non-deterministic, so sideEffect() records the value
        // once and replays it instead of asking the system clock again.
        $start = sideEffect(fn () => now());

        activity(SleepActivity::class, 3);

        $end = sideEffect(fn () => now());

        return 'Elapsed Time: '.$start->diffInSeconds($end).' seconds';
    }
}
