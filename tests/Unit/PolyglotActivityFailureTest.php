<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Polyglot\Avro;
use App\Polyglot\PolyglotActivityFailure;
use PHPUnit\Framework\TestCase;

final class PolyglotActivityFailureTest extends TestCase
{
    public function test_it_decodes_structured_details_from_payload_envelope(): void
    {
        $details = [
            'origin' => 'python',
            'code' => 'PYTHON_TYPED_ERROR',
            'structured' => ['request' => ['case' => 'typed-error']],
        ];

        $failure = PolyglotActivityFailure::fromHistoryPayload([
            'message' => 'activity failed',
            'activity_type' => 'polyglot.php-to-python.typed-error',
            'exception' => [
                'type' => 'PolyglotPythonTypedError',
                'non_retryable' => true,
                'details' => Avro::envelope($details),
            ],
        ]);

        $this->assertSame('PolyglotPythonTypedError', $failure->exceptionType);
        $this->assertTrue($failure->nonRetryable);
        $this->assertSame($details, $failure->decodedDetails);
    }

    public function test_it_decodes_structured_details_from_payload_envelope_wrapped_in_a_list(): void
    {
        $details = [
            'origin' => 'python',
            'code' => 'PYTHON_TYPED_ERROR',
            'structured' => ['request' => ['case' => 'typed-error']],
        ];

        $failure = PolyglotActivityFailure::fromHistoryPayload([
            'message' => 'activity failed',
            'activity_type' => 'polyglot.php-to-python.typed-error',
            'exception' => [
                'type' => 'PolyglotPythonTypedError',
                'non_retryable' => true,
                'details' => [Avro::envelope($details)],
            ],
        ]);

        $this->assertSame($details, $failure->decodedDetails);
    }

    public function test_it_decodes_structured_details_from_codec_tagged_blob(): void
    {
        $details = [
            'origin' => 'python',
            'code' => 'PYTHON_TYPED_ERROR',
            'structured' => ['request' => ['case' => 'typed-error']],
        ];

        $failure = PolyglotActivityFailure::fromHistoryPayload([
            'message' => 'activity failed',
            'activity_type' => 'polyglot.php-to-python.typed-error',
            'exception' => [
                'type' => 'PolyglotPythonTypedError',
                'non_retryable' => true,
                'details' => Avro::encode($details),
                'details_payload_codec' => 'avro',
            ],
        ]);

        $this->assertSame($details, $failure->decodedDetails);
    }

    public function test_it_decodes_details_from_top_level_payload_fields(): void
    {
        $details = [
            'origin' => 'python',
            'code' => 'PYTHON_TYPED_ERROR',
            'structured' => ['request' => ['case' => 'typed-error']],
        ];

        $failure = PolyglotActivityFailure::fromHistoryPayload([
            'message' => 'activity failed',
            'activity_type' => 'polyglot.php-to-python.typed-error',
            'exception_type' => 'PolyglotPythonTypedError',
            'non_retryable' => true,
            'details' => Avro::envelope($details),
        ]);

        $this->assertSame('PolyglotPythonTypedError', $failure->exceptionType);
        $this->assertTrue($failure->nonRetryable);
        $this->assertSame($details, $failure->decodedDetails);
    }
}
