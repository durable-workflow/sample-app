<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ConversationRecord;
use App\Workflows\Conversation\ConversationWorkflow;
use Illuminate\Console\Command;
use Workflow\V2\WorkflowStub;

class Conversation extends Command
{
    protected $signature = 'app:conversation
        {--message=* : Scripted user message; repeat for several turns. Without it the command is interactive}
        {--decision=approve : Scripted answer to an approval request (approve or decline)}
        {--turns-per-run=3 : Turns before the run continues as new}';

    protected $description = 'Long-lived conversation with a fake model, human approval and continue-as-new';

    private const WAIT_SECONDS = 60;

    public function handle(): int
    {
        $turnsPerRun = max(1, (int) $this->option('turns-per-run'));
        $workflow = WorkflowStub::make(ConversationWorkflow::class);
        $workflow->start([], $turnsPerRun, 3600);

        $this->info(sprintf('Conversation %s started. Run a queue worker to process it.', $workflow->id()));

        $scripted = $this->option('message');
        $messages = $scripted !== [] ? $scripted : null;

        while (true) {
            $text = $messages !== null ? array_shift($messages) : $this->ask('You ('.ConversationWorkflow::END_COMMAND.' to finish)');

            if ($text === null || trim($text) === '' || trim($text) === ConversationWorkflow::END_COMMAND) {
                break;
            }

            if ($messages !== null) {
                $this->line("<comment>You:</comment> {$text}");
            }

            if (! $this->takeTurn($workflow, $text, $messages !== null)) {
                return self::FAILURE;
            }
        }

        $workflow->signal('message', ConversationWorkflow::END_COMMAND);

        if (! $this->waitUntil(fn (): bool => ! $workflow->refresh()->running())) {
            return self::FAILURE;
        }

        $this->line(json_encode($workflow->output(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $workflow->completed() ? self::SUCCESS : self::FAILURE;
    }

    private function takeTurn(WorkflowStub $workflow, string $text, bool $scripted): bool
    {
        $turnsBefore = $this->status($workflow)['turns'];
        $workflow->signal('message', $text);

        while (true) {
            $status = null;
            $ready = $this->waitUntil(function () use ($workflow, $turnsBefore, &$status): bool {
                $status = $this->status($workflow);

                return $status['turns'] > $turnsBefore || $status['phase'] === 'awaiting_approval';
            });

            if (! $ready) {
                return false;
            }

            if ($status['turns'] > $turnsBefore) {
                break;
            }

            $proposal = $status['pending_proposal'];
            $approved = $scripted
                ? $this->option('decision') === 'approve'
                : $this->confirm(sprintf('Approve "%s"?', $proposal['description']));

            $this->line(sprintf('<comment>Reviewer:</comment> %s', $approved ? 'approve' : 'decline'));
            $workflow->signal('decision', $proposal['id'], $approved, 'artisan');

            $this->waitUntil(fn (): bool => $this->status($workflow)['phase'] !== 'awaiting_approval');
        }

        /** @var ConversationRecord|null $reply */
        $reply = ConversationRecord::query()->find($status['last_reply_reference']);
        $this->line(sprintf(
            '<info>Assistant:</info> %s <fg=gray>(run %d)</>',
            $reply?->content ?? '[reply not found]',
            $status['generation'],
        ));

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function status(WorkflowStub $workflow): array
    {
        return $workflow->refresh()->query('status');
    }

    private function waitUntil(callable $condition): bool
    {
        $deadline = time() + self::WAIT_SECONDS;

        while (time() < $deadline) {
            if ($condition()) {
                return true;
            }

            usleep(200_000);
        }

        $this->error('Timed out waiting for the conversation. Is a queue worker running?');

        return false;
    }
}
