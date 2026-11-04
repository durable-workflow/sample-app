<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ConversationRecord;
use App\Workflows\Conversation\ConversationStore;
use App\Workflows\ServiceMode\Conversation\ConversationWorkflow;
use DurableWorkflow\Bridge\Laravel\LaravelWorkflowClientInterface;
use DurableWorkflow\WorkflowHandleInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ServiceModeConversation extends Command
{
    protected $signature = 'app:service-mode-conversation
        {--message=* : Scripted user message; repeat for several turns. Without it the command is interactive}
        {--decision=approve : Scripted answer to an approval request (approve or decline)}
        {--turns-per-run=3 : Turns before the run continues as new}
        {--workflow-id= : Use a specific workflow ID}';

    protected $description = 'Run the conversation reference on a PHP service-mode worker';

    private const WAIT_SECONDS = 60;

    public function __construct(private readonly LaravelWorkflowClientInterface $workflows)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $workflowId = $this->option('workflow-id') ?: 'service-conversation-'.Str::lower((string) Str::ulid());
        $workflow = $this->workflows->start(
            ConversationWorkflow::class,
            [[], max(1, (int) $this->option('turns-per-run'))],
            $workflowId,
        );

        $this->info("Conversation {$workflowId} started. A service-mode worker processes it.");

        $scripted = $this->option('message');
        $messages = $scripted !== [] ? $scripted : null;
        $turn = 0;

        while (true) {
            $text = $messages !== null ? array_shift($messages) : $this->ask('You ('.ConversationWorkflow::END_COMMAND.' to finish)');

            if ($text === null || trim($text) === '' || trim($text) === ConversationWorkflow::END_COMMAND) {
                break;
            }

            if ($messages !== null) {
                $this->line("<comment>You:</comment> {$text}");
            }

            $turn++;

            if (! $this->takeTurn($workflow, $workflowId, $turn, $text, $messages !== null)) {
                return self::FAILURE;
            }
        }

        $workflow->signal('message', [ConversationWorkflow::END_COMMAND]);
        $this->line(json_encode($workflow->result(self::WAIT_SECONDS, 0.25), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Sends one message and answers any approval request until the turn's
     * reply is stored.
     *
     * The `status` query describes the current run, and a turn can end with
     * continue-as-new. The reply record is keyed by workflow ID and turn, so
     * it is the reliable sign that the turn finished.
     */
    private function takeTurn(WorkflowHandleInterface $workflow, string $workflowId, int $turn, string $text, bool $scripted): bool
    {
        $reference = ConversationStore::replyReference($workflowId, $turn);
        $workflow->signal('message', [$text]);
        $answered = null;

        while (true) {
            $reply = null;
            $proposal = null;
            $ready = $this->waitUntil(function () use ($workflow, $reference, $answered, &$reply, &$proposal): bool {
                $reply = ConversationRecord::query()->find($reference);
                $proposal = $reply === null ? $this->status($workflow)['pending_proposal'] : null;

                return $reply !== null || ($proposal !== null && $proposal['id'] !== $answered);
            });

            if (! $ready) {
                return false;
            }

            if ($reply !== null) {
                break;
            }

            $approved = $scripted
                ? $this->option('decision') === 'approve'
                : $this->confirm(sprintf('Approve "%s"?', $proposal['description']));

            $this->line(sprintf('<comment>Reviewer:</comment> %s', $approved ? 'approve' : 'decline'));
            $workflow->signal('decision', [$proposal['id'], $approved, 'artisan']);
            $answered = $proposal['id'];
        }

        $this->line(sprintf('<info>Assistant:</info> %s', $reply->content));

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function status(WorkflowHandleInterface $workflow): array
    {
        return $workflow->query('status');
    }

    private function waitUntil(callable $condition): bool
    {
        $deadline = time() + self::WAIT_SECONDS;

        while (time() < $deadline) {
            if ($condition()) {
                return true;
            }

            usleep(250_000);
        }

        $this->error('Timed out waiting for the conversation. Is `php artisan durable-workflow:worker` running?');

        return false;
    }
}
