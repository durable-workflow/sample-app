<?php

declare(strict_types=1);

namespace App\Workflows\Webhooks;

use Workflow\SignalMethod;
use Workflow\V2\Workflow;
use Workflow\Webhook;

use function Workflow\V2\activity;
use function Workflow\V2\await;

#[Webhook]
class WebhookWorkflow extends Workflow
{
    public bool $ready = false;

    #[SignalMethod]
    #[Webhook]
    public function ready(): void
    {
        $this->ready = true;
    }

    public function handle(string $message): string
    {
        await(fn () => $this->ready);

        return activity(WebhookActivity::class, $message);
    }
}
