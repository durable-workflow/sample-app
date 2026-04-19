<?php

declare(strict_types=1);

namespace App\Workflows\Webhooks;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Workflow;
use Workflow\Webhook;

use function Workflow\V2\activity;
use function Workflow\V2\await;

#[Webhook]
#[Signal('ready')]
class WebhookWorkflow extends Workflow
{
    public function handle(string $message): string
    {
        // Block until the `ready` signal is delivered. v2 signals are pull-style:
        // call await($signalName) inside the workflow code and the fiber suspends
        // until the engine records the matching signal.
        await('ready');

        return activity(WebhookActivity::class, $message);
    }
}
