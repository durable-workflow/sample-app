<?php

declare(strict_types=1);

namespace App\Workflows\Ai;

use Illuminate\Support\Facades\Log;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;

class BookHotelActivity extends Activity
{
    public function handle(
        string $hotelName,
        string $checkIn,
        string $checkOut,
        int $guests,
        bool $shouldFail = false,
    ): string {
        if ($shouldFail) {
            throw new NonRetryableException("Hotel booking failed: {$hotelName}.");
        }

        $id = random_int(100000, 999999);
        Log::error("Booking hotel: {$hotelName}, {$checkIn} to {$checkOut}, {$guests} guest(s). Confirmation #{$id}");

        return "Hotel booked: {$hotelName}, check-in {$checkIn}, check-out {$checkOut}, {$guests} guest(s). Confirmation #{$id}";
    }
}
