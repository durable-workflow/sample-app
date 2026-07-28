<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Polyglot\Avro;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\Serializers\Avro as WorkflowAvro;
use Workflow\Serializers\AvroBinaryValue;

/** Polyglot worker checks for the shared fixed Avro Value protocol. */
final class PolyglotAvroTest extends TestCase
{
    public function test_envelope_round_trips_every_native_value_category(): void
    {
        $value = [
            'null' => null,
            'boolean' => true,
            'integer' => 7,
            'double' => 7.0,
            'text' => 'こんにちは',
            'bytes' => AvroBinaryValue::fromBytes("\x00\xFF"),
            'list' => [1, 2],
            'map' => ['nested' => false],
        ];

        $envelope = Avro::envelope($value);

        self::assertSame('avro', $envelope['codec']);
        self::assertEquals($value, Avro::decodeEnvelope($envelope));
        self::assertSame(
            WorkflowAvro::SINGLE_OBJECT_MAGIC.WorkflowAvro::VALUE_SCHEMA_FINGERPRINT,
            substr((string) base64_decode($envelope['blob'], true), 0, 10),
        );
    }

    public function test_sample_worker_matches_cross_language_golden_bytes(): void
    {
        self::assertSame('wwHioz3/VYAiNwQO', Avro::encode(7));
        self::assertSame('wwHioz3/VYAiNwYAAAAAAAAcQA==', Avro::encode(7.0));
        self::assertSame('wwHioz3/VYAiNwgEAP8=', Avro::encode(AvroBinaryValue::fromBytes("\x00\xFF")));
        self::assertSame('wwHioz3/VYAiNwoMaMOpbGxv', Avro::encode('héllo'));
    }

    public function test_prerelease_wrapper_is_not_a_protocol_fallback(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_payload_framing');

        Avro::decode(base64_encode("\x00legacy-wrapper"));
    }

    public function test_decode_envelope_rejects_engine_specific_codec(): void
    {
        $this->expectExceptionMessage('expected an `avro`');

        Avro::decodeEnvelope([
            'codec' => 'workflow-serializer-y',
            'blob' => 'irrelevant',
        ]);
    }
}
