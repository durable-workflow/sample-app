<?php

declare(strict_types=1);

namespace App\Workflows\Polyglot;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Workflow;

use function Workflow\V2\signal;

/**
 * PHP-authored workflow used by the polyglot CLI signal/query surface.
 */
#[Signal('polyglot-signal')]
class PhpSignalQueryWorkflow extends Workflow
{
    /** @var array<string, mixed> */
    private array $request = [];

    /** @var list<mixed> */
    private array $signals = [];

    private string $stage = 'created';

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        $this->request = $request;
        $this->stage = 'waiting';

        $signal = signal('polyglot-signal');
        $this->signals[] = $signal;
        $this->stage = 'signaled';

        $completionSignal = signal('polyglot-signal');
        $this->signals[] = $completionSignal;

        return [
            'workflow_runtime' => 'php',
            'request' => $request,
            'signal' => $signal,
        ];
    }

    /**
     * @return array{
     *     workflow_runtime: string,
     *     stage: string,
     *     signal_count: int,
     *     signals: list<mixed>,
     *     request: array<string, mixed>
     * }
     */
    #[QueryMethod('state')]
    public function state(): array
    {
        return [
            'workflow_runtime' => 'php',
            'stage' => $this->stage,
            'signal_count' => count($this->signals),
            'signals' => $this->signals,
            'request' => $this->request,
        ];
    }
}
