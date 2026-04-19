<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workflows\Microservice\MicroserviceWorkflow;
use Illuminate\Console\Command;
use Workflow\V2\WorkflowStub;

class Microservice extends Command
{
    protected $signature = 'app:microservice';

    protected $description = 'Runs a microservice workflow';

    public function handle(): void
    {
        $workflow = WorkflowStub::make(MicroserviceWorkflow::class);
        $workflow->start();
        while ($workflow->refresh()->running()) {
            usleep(100_000);
        }
        $this->info($workflow->output());
    }
}
