<?php

declare(strict_types=1);

use App\Workflows\Webhooks\WebhookWorkflow;
use Workflow\V2\Webhooks;

Webhooks::routes([
    'webhook-workflow' => WebhookWorkflow::class,
]);
