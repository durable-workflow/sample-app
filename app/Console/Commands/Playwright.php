<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workflows\Playwright\CheckConsoleErrorsWorkflow;
use Illuminate\Console\Command;
use Workflow\V2\WorkflowStub;

class Playwright extends Command
{
    protected $signature = 'app:playwright';

    protected $description = 'Runs a playwright workflow';

    public function handle(): void
    {
        $workflow = WorkflowStub::make(CheckConsoleErrorsWorkflow::class);
        $workflow->start('https://example.com');
        while ($workflow->running()) {
            usleep(100_000);
        }
        $this->info($workflow->output()['mp4']);
    }
}
