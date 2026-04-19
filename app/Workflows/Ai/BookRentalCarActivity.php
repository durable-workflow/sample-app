<?php

declare(strict_types=1);

namespace App\Workflows\Ai;

use Illuminate\Support\Facades\Log;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;

class BookRentalCarActivity extends Activity
{
    public function handle(
        string $pickupLocation,
        string $pickupDate,
        string $returnDate,
        bool $shouldFail = false,
    ): string {
        if ($shouldFail) {
            throw new NonRetryableException("Rental car booking failed: {$pickupLocation}.");
        }

        $id = random_int(100000, 999999);
        Log::error('Booking rental car at ' . $pickupLocation . ' from ' . $pickupDate . ' to ' . $returnDate . '. Confirmation #' . $id);

        return 'Rental car booked at ' . $pickupLocation . ' from ' . $pickupDate . ' to ' . $returnDate . '. Confirmation #' . $id;
    }
}
