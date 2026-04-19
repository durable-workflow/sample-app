<?php

declare(strict_types=1);

namespace App\Workflows\Ai;

use Illuminate\Support\Facades\Log;
use Workflow\V2\Activity;

class CancelRentalCarActivity extends Activity
{
    public function handle(string $rentalCarId): void
    {
        Log::error('Cancelling rental car ' . $rentalCarId . '...');
    }
}
