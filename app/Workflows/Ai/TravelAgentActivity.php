<?php

declare(strict_types=1);

namespace App\Workflows\Ai;

use App\Ai\Agents\TravelAgent;
use App\Ai\Tools\BookFlight;
use App\Ai\Tools\BookHotel;
use App\Ai\Tools\BookRentalCar;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Workflow\V2\Activity;

class TravelAgentActivity extends Activity
{
    public function handle(array $messages, ?array $bookingPlan = null): string
    {
        BookHotel::$pending = [];
        BookFlight::$pending = [];
        BookRentalCar::$pending = [];

        if ($bookingPlan !== null) {
            return json_encode($bookingPlan, JSON_THROW_ON_ERROR);
        }

        // The workflow keeps durable history in language-neutral maps so its
        // arguments and result stay inside the fixed Avro Value protocol.
        // Rehydrate typed messages only at the Laravel AI boundary.
        $rehydrated = array_map(static fn ($message) => self::rehydrate($message), $messages);

        $history = array_slice($rehydrated, 0, -1);
        $currentUserMessage = end($rehydrated);

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

    private static function rehydrate(mixed $message): Message
    {
        if ($message instanceof Message) {
            return $message;
        }

        if (is_array($message)) {
            $role = $message['role'] ?? 'user';
            $content = (string) ($message['content'] ?? '');

            return $role === 'assistant'
                ? new AssistantMessage($content)
                : new UserMessage($content);
        }

        return new UserMessage((string) $message);
    }
}
