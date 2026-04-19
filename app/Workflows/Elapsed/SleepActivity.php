<?php

declare(strict_types=1);

namespace App\Workflows\Elapsed;

use Workflow\V2\Activity;

class SleepActivity extends Activity
{
    public function handle(int $seconds): void
    {
        sleep($seconds);
    }
}
