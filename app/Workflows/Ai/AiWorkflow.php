<?php

declare(strict_types=1);

namespace App\Workflows\Ai;

use App\Models\AiWorkflowMessage;
use Exception;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use RuntimeException;
use Throwable;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Enums\MessageChannel;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\Enums\MessageDirection;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Support\MessageService;
use Workflow\V2\Support\MessageStreamCursor;
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

    private const ASSISTANT_STREAM = 'ai.assistant';

    private int $assistantMessagesSent = 0;

    #[UpdateMethod]
    public function receive(): ?string
    {
        $messageService = app(MessageService::class);
        $streamMessage = $messageService
            ->receiveMessages($this->run, self::ASSISTANT_STREAM, 1)
            ->first();

        if ($streamMessage === null) {
            return null;
        }

        $reference = $streamMessage->payload_reference;

        if ($reference === null) {
            throw new RuntimeException('Assistant message stream entry is missing a payload reference.');
        }

        /** @var AiWorkflowMessage|null $message */
        $message = AiWorkflowMessage::query()->find($reference);

        if ($message === null) {
            throw new RuntimeException("Assistant message payload [{$reference}] was not found.");
        }

        $messageService->consumeMessage($this->run, $streamMessage, (int) $streamMessage->sequence);

        return $message->content;
    }

    public function handle(?string $injectFailure = null): array
    {
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
                $this->publishAssistantMessage($data['text']);
            }

            if (count($messages) >= self::MAX_MESSAGES) {
                throw new Exception(
                    'This conversation has reached its message limit. Please start a new conversation to continue.'
                );
            }
        } catch (Throwable $th) {
            $this->compensate();
            $this->publishAssistantMessage($th->getMessage().' Any previous bookings have been cancelled.');
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

    private function publishAssistantMessage(string $content): void
    {
        $this->assistantMessagesSent++;

        $reference = sprintf(
            'ai:%s:%d',
            $this->workflowId(),
            $this->assistantMessagesSent,
        );

        AiWorkflowMessage::query()->updateOrCreate(
            ['reference' => $reference],
            [
                'workflow_id' => $this->workflowId(),
                'run_id' => $this->runId(),
                'role' => 'assistant',
                'content' => $content,
            ],
        );

        DB::transaction(function () use ($reference): void {
            $instance = WorkflowInstance::query()
                ->where('id', $this->workflowId())
                ->lockForUpdate()
                ->firstOrFail();

            $sequence = MessageStreamCursor::reserveNextSequence($instance);

            WorkflowMessage::query()->create([
                'workflow_instance_id' => $this->workflowId(),
                'workflow_run_id' => $this->runId(),
                'direction' => MessageDirection::Inbound,
                'channel' => MessageChannel::WorkflowMessage->value,
                'stream_key' => self::ASSISTANT_STREAM,
                'sequence' => $sequence,
                'source_workflow_instance_id' => $this->workflowId(),
                'source_workflow_run_id' => $this->runId(),
                'target_workflow_instance_id' => $this->workflowId(),
                'target_workflow_run_id' => $this->runId(),
                'payload_reference' => $reference,
                'correlation_id' => $reference,
                'idempotency_key' => $reference,
                'metadata' => ['role' => 'assistant'],
                'consume_state' => MessageConsumeState::Pending,
            ]);
        });
    }
}
