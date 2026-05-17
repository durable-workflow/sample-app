<?php

declare(strict_types=1);

namespace App\Workflows\Polyglot;

use App\Polyglot\PolyglotActivityFailure;
use Workflow\V2\Workflow;

use function Workflow\V2\activity;

/**
 * PHP-authored workflow that verifies structured Python activity failures.
 */
class PhpToPythonTypedErrorWorkflow extends Workflow
{
    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        try {
            activity('polyglot.php-to-python.typed-error', $request);
        } catch (PolyglotActivityFailure $failure) {
            return [
                'workflow_runtime' => 'php',
                'activity_runtime' => 'python',
                'failure' => $failure->toArray(),
                'request' => $request,
            ];
        }

        return [
            'workflow_runtime' => 'php',
            'activity_runtime' => 'python',
            'failure' => null,
            'request' => $request,
        ];
    }
}
