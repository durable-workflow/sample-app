<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workflows\Ai\AiWorkflow;
use Illuminate\Console\Command;
use Workflow\V2\WorkflowStub;

class Ai extends Command
{
    protected $signature = 'app:ai {--inject-failure= : Inject failure into a booking activity (hotel, flight, car)}';

    protected $description = 'Interactive AI travel agent powered by a durable workflow';

    public function handle(): int
    {
        $injectFailure = $this->option('inject-failure');

        $workflow = WorkflowStub::make(AiWorkflow::class);
        $workflow->start($injectFailure);

        $this->info('Travel Agent started. Type your messages below. Type "quit" to exit.');
        $this->newLine();

        while (true) {
            $input = $this->ask('You');

            if ($input === null || strtolower(trim($input)) === 'quit') {
                $this->info('Goodbye!');
                break;
            }

            if (trim($input) === '') {
                continue;
            }

            $workflow->signal('send', $input);

            if (! $this->waitForMessage($workflow)) {
                break;
            }
        }

        return 0;
    }

    /**
     * Poll the workflow outbox until one message arrives, then display it.
     */
    private function waitForMessage(WorkflowStub $workflow, int $timeoutSeconds = 120): bool
    {
        $elapsed = 0;

        while ($elapsed < $timeoutSeconds) {
            $message = $workflow->update('receive');

            if ($message !== null) {
                $this->newLine();
                $this->line("<comment>Agent:</comment> {$message}");
                $workflow->refresh();

                return ! $workflow->failed() && ! $workflow->completed();
            }

            $workflow->refresh();
            if ($workflow->failed() || $workflow->completed()) {
                return false;
            }

            sleep(2);
            $elapsed += 2;
        }

        $this->error('Timed out waiting for a response.');

        return false;
    }
}
