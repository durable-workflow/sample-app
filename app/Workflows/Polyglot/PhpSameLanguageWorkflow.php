<?php

declare(strict_types=1);

namespace App\Workflows\Polyglot;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;

/**
 * PHP-authored workflow that schedules PHP-authored activities.
 *
 * This is the same-language sanity check paired with the Python-authored
 * same-language smoke. It uses the same standalone server and worker-plane
 * protocol as the cross-language scenarios, but both the workflow task and
 * activity tasks are claimed by PHP workers.
 */
class PhpSameLanguageWorkflow extends Workflow
{
    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        $marker = activity(
            'polyglot.php.marker',
            $request,
        );

        $description = activity(
            'polyglot.php.describe',
            $marker,
        );

        return [
            'workflow_runtime' => 'php',
            'activity_runtime' => is_array($marker) ? ($marker['runtime'] ?? null) : null,
            'request' => $request,
            'php_marker' => $marker,
            'php_description' => $description,
        ];
    }
}
