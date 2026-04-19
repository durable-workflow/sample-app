<?php

declare(strict_types=1);

namespace App\Workflows\Ai;

use App\Ai\Agents\TravelAgent;
use App\Ai\Tools\BookFlight;
use App\Ai\Tools\BookHotel;
use App\Ai\Tools\BookRentalCar;
use Workflow\V2\Activity;

class TravelAgentActivity extends Activity
{
    public function handle(array $messages): string
    {
        BookHotel::$pending = [];
        BookFlight::$pending = [];
        BookRentalCar::$pending = [];

        $history = array_slice($messages, 0, -1);
        $currentUserMessage = end($messages);

        $response = (new TravelAgent())
            ->continue($history)
            ->prompt($currentUserMessage->content);

        $bookings = array_merge(
            BookHotel::$pending,
            BookFlight::$pending,
            BookRentalCar::$pending,
        );

        return json_encode([
            'text' => (string) $response,
            'bookings' => $bookings,
        ]);
    }
}
