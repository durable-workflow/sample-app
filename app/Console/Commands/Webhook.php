<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Workflow\V2\WorkflowStub;

class Webhook extends Command
{
    protected $signature = 'app:webhook';

    protected $description = 'Runs a workflow via a webhook and then signals it';

    public function handle(): int
    {
        $start = Http::post('http://localhost/api/webhooks/start/webhook-workflow', [
            'message' => 'world',
        ]);

        if (! $start->successful()) {
            $this->error('Webhook start failed: ' . $start->status() . ' ' . $start->body());

            return 1;
        }

        $workflowId = $start->json('workflow_id');

        if (! is_string($workflowId) || $workflowId === '') {
            $this->error('Webhook response missing workflow_id: ' . $start->body());

            return 1;
        }

        $workflow = WorkflowStub::load($workflowId);
        $workflow->signal('ready');

        while ($workflow->running()) {
            usleep(100_000);
        }

        $this->info($workflow->output());

        return 0;
    }
}
