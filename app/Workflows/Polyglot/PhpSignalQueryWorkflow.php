<?php

declare(strict_types=1);

namespace App\Workflows\Polyglot;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Workflow;

use function Workflow\V2\signal;

/**
 * PHP-authored workflow used by the polyglot CLI signal/query surface.
 */
#[Signal('polyglot-signal')]
class PhpSignalQueryWorkflow extends Workflow
{
    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        $signal = signal('polyglot-signal');

        return [
            'workflow_runtime' => 'php',
            'request' => $request,
            'signal' => $signal,
        ];
    }
}
