<?php

declare(strict_types=1);

namespace App\Workflows\Webhooks;

use Workflow\V2\Activity;

class WebhookActivity extends Activity
{
    public function handle(string $message): string
    {
        return 'Hello ' . $message;
    }
}
