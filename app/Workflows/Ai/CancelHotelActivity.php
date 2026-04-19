<?php

declare(strict_types=1);

namespace App\Workflows\Ai;

use Illuminate\Support\Facades\Log;
use Workflow\V2\Activity;

class CancelHotelActivity extends Activity
{
    public function handle(string $hotelId): void
    {
        Log::error('Cancelling hotel ' . $hotelId . '...');
    }
}
