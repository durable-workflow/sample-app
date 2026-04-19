<?php

declare(strict_types=1);

namespace App\Workflows\Ai;

use Illuminate\Support\Facades\Log;
use Workflow\V2\Activity;

class CancelFlightActivity extends Activity
{
    public function handle(string $flightId): void
    {
        Log::error('Cancelling flight ' . $flightId . '...');
    }
}
