<?php

declare(strict_types=1);

namespace App\Workflows\Polyglot;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;

/**
 * PHP-authored workflow that schedules an activity handled by Rust.
 */
class PhpToRustWorkflow extends Workflow
{
    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        $echo = activity('polyglot.php-to-rust.echo', $request);

        return [
            'workflow_runtime' => 'php',
            'activity_runtime' => is_array($echo) ? ($echo['runtime'] ?? null) : null,
            'request' => $request,
            'echo' => $echo,
        ];
    }
}
