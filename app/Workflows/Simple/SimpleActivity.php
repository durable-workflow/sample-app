<?php

declare(strict_types=1);

namespace App\Workflows\Simple;

use Workflow\V2\Activity;

class SimpleActivity extends Activity
{
    public function handle(): string
    {
        return 'activity';
    }
}
