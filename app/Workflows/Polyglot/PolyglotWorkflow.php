<?php

declare(strict_types=1);

namespace App\Workflows\Polyglot;

use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

use function Workflow\V2\activity;

/**
 * PHP-authored workflow that coordinates Python and Rust activity workers.
 *
 * Each activity call carries an explicit task queue, so the standalone
 * Durable Workflow service dispatches the calculation to the Python worker
 * and the receipt formatting to the Rust worker. Neither activity executes
 * inside the PHP workflow process.
 */
class PolyglotWorkflow extends Workflow
{
    public const WORKFLOW_TASK_QUEUE = 'polyglot-workflow';

    public const PYTHON_ACTIVITY_TASK_QUEUE = 'polyglot-php-to-python';

    public const RUST_ACTIVITY_TASK_QUEUE = 'polyglot-to-rust';

    /**
     * @param  array{name?: string, items?: list<array{quantity: int, unit_price_cents: int}>}  $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        $name = (string) ($request['name'] ?? 'Ada');
        $items = $request['items'] ?? [
            ['quantity' => 2, 'unit_price_cents' => 1500],
            ['quantity' => 1, 'unit_price_cents' => 4200],
        ];

        $calculation = activity(
            'polyglot.php-to-python.tally',
            new ActivityOptions(queue: self::PYTHON_ACTIVITY_TASK_QUEUE),
            $items,
        );

        $receipt = activity(
            'polyglot.php-to-rust.receipt',
            new ActivityOptions(queue: self::RUST_ACTIVITY_TASK_QUEUE),
            [
                'name' => $name,
                'item_count' => is_array($calculation) ? ($calculation['item_count'] ?? null) : null,
                'total_cents' => is_array($calculation) ? ($calculation['total_cents'] ?? null) : null,
                'calculation_runtime' => is_array($calculation) ? ($calculation['runtime'] ?? null) : null,
            ],
        );

        return [
            'workflow' => 'PolyglotWorkflow',
            'workflow_runtime' => 'php',
            'activity_runtimes' => [
                'calculation' => is_array($calculation) ? ($calculation['runtime'] ?? null) : null,
                'receipt' => is_array($receipt) ? ($receipt['runtime'] ?? null) : null,
            ],
            'task_queues' => [
                'workflow' => self::WORKFLOW_TASK_QUEUE,
                'python_activity' => self::PYTHON_ACTIVITY_TASK_QUEUE,
                'rust_activity' => self::RUST_ACTIVITY_TASK_QUEUE,
            ],
            'request' => [
                'name' => $name,
                'items' => $items,
            ],
            'python_calculation' => $calculation,
            'rust_receipt' => $receipt,
            'summary' => sprintf(
                'PHP orchestrated a Python order calculation and a Rust receipt: %s',
                is_array($receipt) ? (string) ($receipt['message'] ?? 'receipt unavailable') : 'receipt unavailable',
            ),
        ];
    }
}
