<?php

declare(strict_types=1);

namespace App\Workflows\Ai;

use Illuminate\Support\Facades\Log;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;

class BookFlightActivity extends Activity
{
    public function handle(
        string $origin,
        string $destination,
        string $departureDate,
        ?string $returnDate = null,
        bool $shouldFail = false,
    ): string {
        if ($shouldFail) {
            throw new NonRetryableException("Flight booking failed: {$origin} to {$destination}.");
        }

        $id = random_int(100000, 999999);

        $summary = "Flight booked: {$origin} to {$destination}, departing {$departureDate}";
        if ($returnDate) {
            $summary .= ", returning {$returnDate}";
        } else {
            $summary .= ' (one-way)';
        }
        $summary .= ". Confirmation #{$id}";

        Log::error($summary);

        return $summary;
    }
}
