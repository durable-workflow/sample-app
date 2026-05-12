<?php

declare(strict_types=1);

namespace App\Workflows\Polyglot;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;

/**
 * PHP-authored workflow that schedules activities handled by a Python worker.
 *
 * Both the PHP workflow worker and the Python activity worker poll the
 * same task queue against one standalone Durable Workflow server. The
 * server routes the workflow task to a worker that supports the workflow
 * type and routes the activity task to a worker that supports the
 * activity type. With this workflow registered to a PHP worker and the
 * `polyglot.php-to-python.*` activities registered to the Python worker,
 * each run drives a real cross-language interaction over the wire.
 *
 * The polling worker that executes this class lives in the polyglot
 * docker stack at `polyglot/php_worker/`; the same class file is
 * registered in `config/workflow_mcp.php` so it surfaces in the sample
 * app's MCP listing alongside the other authored workflows.
 */
class PhpToPythonWorkflow extends Workflow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(string $value): array
    {
        $reverse = activity(
            'polyglot.php-to-python.reverse',
            $value,
        );

        $tally = activity(
            'polyglot.php-to-python.tally',
            [
                ['quantity' => 2, 'unit_price_cents' => 1500],
                ['quantity' => 1, 'unit_price_cents' => 4200],
            ],
        );

        return [
            'workflow_runtime' => 'php',
            'input' => $value,
            'reverse' => $reverse,
            'tally' => $tally,
        ];
    }
}
