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
    public function handle(array $messages): string
    {
        BookHotel::$pending = [];
        BookFlight::$pending = [];
        BookRentalCar::$pending = [];

        // Activity arguments are Avro-serialized under the v2 default codec,
        // which strips PHP class info from UserMessage/AssistantMessage and
        // hands us plain associative arrays. Rehydrate the typed Message
        // objects so TravelAgent + Prism see the shape they expect.
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
