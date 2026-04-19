<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workflows\Prism\PrismWorkflow;
use Illuminate\Console\Command;
use Workflow\V2\WorkflowStub;

class Prism extends Command
{
    protected $signature = 'app:prism';

    protected $description = 'Runs a Prism AI workflow';

    public function handle(): void
    {
        $workflow = WorkflowStub::make(PrismWorkflow::class);
        $workflow->start();
        while ($workflow->refresh()->running()) {
            usleep(100_000);
        }
        $user = $workflow->output();

        $this->info('Generated User:');
        $this->info(json_encode($user, JSON_PRETTY_PRINT));
    }
}
