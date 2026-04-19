<?php

declare(strict_types=1);

namespace App\Workflows\Ai;

use Exception;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Throwable;
use Workflow\Outbox;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Workflow;

use function Workflow\V2\activity;
use function Workflow\V2\await;

#[Signal('send', [
    ['name' => 'message', 'type' => 'string'],
])]
class AiWorkflow extends Workflow
{
    private const INACTIVITY_TIMEOUT = '2 minutes';

    private const MAX_MESSAGES = 20;

    public Outbox $outbox;

    #[UpdateMethod]
    public function receive(): mixed
    {
        return $this->ensureOutbox()->nextUnsent();
    }

    public function handle(?string $injectFailure = null): array
    {
        $this->ensureOutbox();

        $messages = [];

        try {
            while (count($messages) < self::MAX_MESSAGES) {
                // v2 pull-style signal: blocks until a `send` signal arrives or
                // the inactivity timeout elapses. Returns the signal's `message`
                // arg, or null on timeout.
                $userMessage = await('send', self::INACTIVITY_TIMEOUT);

                if ($userMessage === null) {
                    throw new Exception(
                        'Session ended due to inactivity. Please start a new conversation.'
                    );
                }

                $messages[] = new UserMessage((string) $userMessage);
                $result = activity(TravelAgentActivity::class, $messages);
                $data = json_decode($result, true);

                foreach ($data['bookings'] as $booking) {
                    $this->handleBooking($booking, $injectFailure);
                }

                $messages[] = new AssistantMessage($data['text']);
                $this->outbox->send($data['text']);
            }

            if (count($messages) >= self::MAX_MESSAGES) {
                throw new Exception(
                    'This conversation has reached its message limit. Please start a new conversation to continue.'
                );
            }
        } catch (Throwable $th) {
            $this->compensate();
            $this->outbox->send($th->getMessage() . ' Any previous bookings have been cancelled.');
        }

        return $messages;
    }

    private function handleBooking(array $data, ?string $injectFailure): mixed
    {
        return match ($data['type']) {
            'book_hotel' => $this->bookHotel($data, $injectFailure),
            'book_flight' => $this->bookFlight($data, $injectFailure),
            'book_rental_car' => $this->bookRentalCar($data, $injectFailure),
        };
    }

    private function bookHotel(array $data, ?string $injectFailure): mixed
    {
        $hotel = activity(
            BookHotelActivity::class,
            $data['hotel_name'],
            $data['check_in_date'],
            $data['check_out_date'],
            (int) $data['guests'],
            $injectFailure === 'hotel',
        );
        $this->addCompensation(fn () => activity(CancelHotelActivity::class, $hotel));

        return $hotel;
    }

    private function bookFlight(array $data, ?string $injectFailure): mixed
    {
        $flight = activity(
            BookFlightActivity::class,
            $data['origin'],
            $data['destination'],
            $data['departure_date'],
            $data['return_date'] ?? null,
            $injectFailure === 'flight',
        );
        $this->addCompensation(fn () => activity(CancelFlightActivity::class, $flight));

        return $flight;
    }

    private function bookRentalCar(array $data, ?string $injectFailure): mixed
    {
        $rentalCar = activity(
            BookRentalCarActivity::class,
            $data['pickup_location'],
            $data['pickup_date'],
            $data['return_date'],
            $injectFailure === 'car',
        );
        $this->addCompensation(fn () => activity(CancelRentalCarActivity::class, $rentalCar));

        return $rentalCar;
    }

    private function ensureOutbox(): Outbox
    {
        if (! isset($this->outbox)) {
            $this->outbox = new Outbox();
        }

        return $this->outbox;
    }
}
