<?php

declare(strict_types=1);

namespace App\Workflows\Polyglot;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;

/**
 * PHP-authored workflow that round-trips JSON-native values through Python.
 */
class PhpToPythonTypeRoundtripWorkflow extends Workflow
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $echo = activity('polyglot.php-to-python.echo', $payload);

        return [
            'workflow_runtime' => 'php',
            'activity_runtime' => is_array($echo) ? ($echo['runtime'] ?? null) : null,
            'input' => $payload,
            'echo' => $echo,
        ];
    }
}
