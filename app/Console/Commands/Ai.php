<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workflows\Ai\AiWorkflow;
use Illuminate\Console\Command;
use Workflow\V2\WorkflowStub;

class Ai extends Command
{
    protected $signature = 'app:ai
        {--inject-failure= : Inject failure into a booking activity (hotel, flight, car)}
        {--message=* : Scripted user message to send before exiting; repeat for multiple turns}
        {--inactivity-timeout= : Override workflow inactivity timeout in seconds for scripted runs}';

    protected $description = 'Interactive AI travel agent powered by a durable workflow';

    public function handle(): int
    {
        $injectFailure = $this->option('inject-failure');
        $messages = $this->scriptedMessages();
        $inactivityTimeout = $this->positiveIntOption('inactivity-timeout');

        $workflow = WorkflowStub::make(AiWorkflow::class);
        $workflow->start($injectFailure, $inactivityTimeout);

        if ($messages !== []) {
            $this->info('Travel Agent started in scripted mode.');
            $this->newLine();

            foreach ($messages as $message) {
                $this->line("<comment>You:</comment> {$message}");
                $workflow->signal('send', $message);

                if (! $this->waitForMessage($workflow)) {
                    return self::FAILURE;
                }

                $workflow->refresh();

                if ($workflow->failed()) {
                    return self::FAILURE;
                }

                if ($workflow->completed()) {
                    return self::SUCCESS;
                }
            }

            if ($inactivityTimeout !== null && ! $this->waitForTerminalState($workflow, $inactivityTimeout + 30)) {
                return self::FAILURE;
            }

            return self::SUCCESS;
        }

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

            $workflow->refresh();
            if ($workflow->failed() || $workflow->completed()) {
                break;
            }
        }

        return 0;
    }

    /**
     * Poll the workflow's assistant message stream until one message arrives, then display it.
     *
     * Uses attemptUpdate() so that v2 protocol-level rejections like
     * earlier_signal_pending (the signal we just sent has not been applied to
     * the workflow yet) are treated as "try again shortly" rather than fatal.
     */
    private function waitForMessage(WorkflowStub $workflow, int $timeoutSeconds = 120): bool
    {
        $elapsed = 0;

        while ($elapsed < $timeoutSeconds) {
            $result = $workflow->attemptUpdate('receive');

            if ($result->completed()) {
                $message = $result->result();

                if ($message !== null) {
                    $this->newLine();
                    $this->line("<comment>Agent:</comment> {$message}");
                    $workflow->refresh();

                    return ! $workflow->failed();
                }
            } elseif ($result->failed()) {
                $this->error('Update failed: '.($result->failureMessage() ?? 'unknown'));

                return false;
            }
            // Rejected (for example earlier_signal_pending) or accepted but
            // not completed yet: fall through to sleep and retry.

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

    private function waitForTerminalState(WorkflowStub $workflow, int $timeoutSeconds): bool
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $workflow->refresh();

            if ($workflow->failed()) {
                $this->error('Workflow failed.');

                return false;
            }

            if ($workflow->completed()) {
                $this->info('Workflow completed.');

                return true;
            }

            sleep(1);
        }

        $this->error('Timed out waiting for the workflow to complete.');

        return false;
    }

    /**
     * @return list<string>
     */
    private function scriptedMessages(): array
    {
        $messages = $this->option('message');

        if (! is_array($messages)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $message): string => trim((string) $message), $messages),
            static fn (string $message): bool => $message !== '',
        ));
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value <= 0) {
            $this->warn("Ignoring invalid {$name} value; expected a positive integer.");

            return null;
        }

        return (int) $value;
    }
}
