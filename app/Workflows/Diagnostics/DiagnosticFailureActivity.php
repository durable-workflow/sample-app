<?php

declare(strict_types=1);

namespace App\Workflows\Diagnostics;

use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;

class DiagnosticFailureActivity extends Activity
{
    public function handle(string $reason): never
    {
        throw new NonRetryableException("Diagnostic failure requested: {$reason}.");
    }
}
