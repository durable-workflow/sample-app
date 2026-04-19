<?php

declare(strict_types=1);

namespace App\Workflows\Simple;

use Workflow\V2\Activity;

class SimpleOtherActivity extends Activity
{
    public function handle(string $string): string
    {
        return $string;
    }
}
