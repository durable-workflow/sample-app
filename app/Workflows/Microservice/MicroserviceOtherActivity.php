<?php

declare(strict_types=1);

namespace App\Workflows\Microservice;

use Workflow\V2\Activity;

class MicroserviceOtherActivity extends Activity
{
    public ?string $queue = 'activity';

    public function handle(string $string): string
    {
        return $string;
    }
}
