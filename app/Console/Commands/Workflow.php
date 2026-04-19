<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workflows\Simple\SimpleWorkflow;
use Illuminate\Console\Command;
use Workflow\V2\WorkflowStub;

class Workflow extends Command
{
    protected $signature = 'app:workflow';

    protected $description = 'Runs a workflow';

    public function handle(): void
    {
        $workflow = WorkflowStub::make(SimpleWorkflow::class);
        $workflow->start();
        while ($workflow->refresh()->running()) {
            usleep(100_000);
        }
        $this->info($workflow->output());
    }
}
