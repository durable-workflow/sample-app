<?php

declare(strict_types=1);

namespace App\Polyglot;

use RuntimeException;

/**
 * Language-neutral activity failure raised back into PHP-authored workflows.
 */
final class PolyglotActivityFailure extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $exceptionPayload
     * @param array<string, mixed>|null $decodedDetails
     */
    public function __construct(
        string $message,
        public readonly ?string $activityType = null,
        public readonly ?string $activityExecutionId = null,
        public readonly ?string $activityAttemptId = null,
        public readonly ?string $failureId = null,
        public readonly ?string $failureCategory = null,
        public readonly ?string $exceptionType = null,
        public readonly ?string $exceptionClass = null,
        public readonly bool $nonRetryable = false,
        public readonly mixed $failureCode = null,
        public readonly ?array $exceptionPayload = null,
        public readonly ?array $decodedDetails = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromHistoryPayload(array $payload): self
    {
        $exception = is_array($payload['exception'] ?? null) ? $payload['exception'] : [];
        $message = (string) ($payload['message'] ?? $exception['message'] ?? 'activity failed');
        $detailsCodec = (string) ($exception['details_payload_codec'] ?? $payload['details_payload_codec'] ?? '');
        $details = $exception['details'] ?? $payload['details'] ?? null;
        $decodedDetails = self::detailsArray($details, $detailsCodec);

        return new self(
            message: $message,
            activityType: self::nullableString($payload['activity_type'] ?? null),
            activityExecutionId: self::nullableString($payload['activity_execution_id'] ?? null),
            activityAttemptId: self::nullableString($payload['activity_attempt_id'] ?? null),
            failureId: self::nullableString($payload['failure_id'] ?? null),
            failureCategory: self::nullableString($payload['failure_category'] ?? null),
            exceptionType: self::nullableString($payload['exception_type'] ?? $exception['type'] ?? null),
            exceptionClass: self::nullableString($payload['exception_class'] ?? $exception['class'] ?? null),
            nonRetryable: ($payload['non_retryable'] ?? $exception['non_retryable'] ?? false) === true,
            failureCode: $payload['code'] ?? $exception['code'] ?? null,
            exceptionPayload: is_array($exception) ? $exception : null,
            decodedDetails: $decodedDetails,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'activity_type' => $this->activityType,
            'activity_execution_id' => $this->activityExecutionId,
            'activity_attempt_id' => $this->activityAttemptId,
            'failure_id' => $this->failureId,
            'failure_category' => $this->failureCategory,
            'exception_type' => $this->exceptionType,
            'exception_class' => $this->exceptionClass,
            'non_retryable' => $this->nonRetryable,
            'code' => $this->failureCode,
            'exception_payload' => $this->exceptionPayload,
            'details' => $this->decodedDetails,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function detailsArray(mixed $value, string $codec): ?array
    {
        if ($value === null) {
            return null;
        }

        return self::arrayDetails(self::decodeDetailsValue($value, $codec));
    }

    private static function decodeDetailsValue(mixed $value, string $codec, int $depth = 0): mixed
    {
        if ($depth > 3) {
            return $value;
        }

        if (is_string($value) && $codec === 'avro') {
            return self::decodeDetailsValue(Avro::decode($value), '', $depth + 1);
        }

        if (is_array($value) && self::isPayloadEnvelope($value)) {
            return self::decodeDetailsValue(Avro::decodeEnvelope($value), '', $depth + 1);
        }

        if (is_array($value) && array_is_list($value) && count($value) === 1) {
            return self::decodeDetailsValue($value[0], $codec, $depth + 1);
        }

        if (is_array($value) && isset($value['payload']) && self::isPayloadEnvelopeValue($value['payload'])) {
            return self::decodeDetailsValue($value['payload'], $codec, $depth + 1);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function isPayloadEnvelope(array $value): bool
    {
        return is_string($value['codec'] ?? null) && is_string($value['blob'] ?? null);
    }

    private static function isPayloadEnvelopeValue(mixed $value): bool
    {
        return is_array($value) && self::isPayloadEnvelope($value);
    }

    private static function arrayDetails(mixed $value): ?array
    {
        return is_array($value) ? $value : ['value' => $value];
    }
}
