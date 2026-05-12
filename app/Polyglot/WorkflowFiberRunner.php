<?php

declare(strict_types=1);

namespace App\Polyglot;

use Fiber;
use RuntimeException;
use Throwable;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\WorkflowFiberContext;
use Workflow\V2\Workflow;

/**
 * Per-run executor that pumps a PHP-authored workflow class step by step.
 *
 * Each `step()` call resumes the workflow until it either suspends with a
 * supported command (currently `ActivityCall`) or returns from `handle()`.
 * The polyglot polling worker holds one runner per `(workflow_id, run_id)`
 * across consecutive workflow tasks so the same Fiber observes the
 * activity result the server delivers in history.
 *
 * The runner only handles the small set of suspend types this sample
 * exercises. Any other suspend value raises a hard error so a future
 * scenario does not silently no-op.
 */
final class WorkflowFiberRunner
{
    private readonly Fiber $fiber;

    private bool $started = false;

    /**
     * @param array<int, mixed> $arguments
     */
    public function __construct(
        private readonly Workflow $workflow,
        private readonly array $arguments,
    ) {
        $this->fiber = new Fiber(function (): mixed {
            WorkflowFiberContext::enter();

            try {
                return $this->workflow->handle(...$this->arguments);
            } finally {
                WorkflowFiberContext::leave();
            }
        });
    }

    /**
     * Build a runner for a workflow class registered to this worker.
     *
     * The workflow constructor takes a `WorkflowRun`. The polling worker
     * does not own the workflow database (the standalone server does),
     * so we hand the constructor a transient in-memory `WorkflowRun`
     * with the run identity the server assigned. The workflow's
     * `handle()` method must stay within authoring API surface that
     * does not require the DB-bound run row.
     *
     * @param class-string<Workflow> $workflowClass
     * @param array<int, mixed> $arguments
     */
    public static function forClass(string $workflowClass, string $workflowId, string $runId, array $arguments): self
    {
        $run = new WorkflowRun();
        $run->id = $runId;
        $run->workflow_instance_id = $workflowId;

        $workflow = new $workflowClass($run);

        if (! $workflow instanceof Workflow) {
            throw new RuntimeException(sprintf(
                'Polyglot worker can only host Workflow\\V2\\Workflow subclasses; got %s.',
                $workflowClass,
            ));
        }

        return new self($workflow, $arguments);
    }

    /**
     * Advance the workflow until the next suspension or completion.
     *
     * Returns a {@see WorkflowStep} describing what the workflow yielded.
     * The first call passes `$resumeWith = null` to start the fiber; later
     * calls pass the activity result the server delivered in history.
     */
    public function step(mixed $resumeWith = null): WorkflowStep
    {
        try {
            $value = $this->started
                ? $this->fiber->resume($resumeWith)
                : $this->fiber->start();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Workflow execution raised: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $this->started = true;

        if ($this->fiber->isTerminated()) {
            return WorkflowStep::completed($this->fiber->getReturn());
        }

        if ($value instanceof ActivityCall) {
            return WorkflowStep::scheduleActivity($value);
        }

        throw new RuntimeException(sprintf(
            'Polyglot worker received an unsupported workflow suspension of type %s.',
            is_object($value) ? $value::class : gettype($value),
        ));
    }
}
