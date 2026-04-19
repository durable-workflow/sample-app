<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workflows\Elapsed\ElapsedTimeWorkflow;
use Illuminate\Console\Command;
use Workflow\V2\WorkflowStub;

class Elapsed extends Command
{
    protected $signature = 'app:elapsed';

    protected $description = 'Runs an elapsed time workflow';

    public function handle(): void
    {
        $workflow = WorkflowStub::make(ElapsedTimeWorkflow::class);
        $workflow->start();
        while ($workflow->refresh()->running()) {
            usleep(100_000);
        }
        $this->info($workflow->output());
    }
}
