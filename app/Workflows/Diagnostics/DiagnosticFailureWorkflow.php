<?php

declare(strict_types=1);

namespace App\Workflows\Diagnostics;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;

class DiagnosticFailureWorkflow extends Workflow
{
    public function handle(string $reason = 'agent-operability-induced-failure'): string
    {
        // Purpose-built no-credential workflow for agent diagnostic drills.
        activity(DiagnosticFailureActivity::class, $reason);

        return 'unreachable';
    }
}
