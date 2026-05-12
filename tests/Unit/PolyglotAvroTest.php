<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Polyglot\Avro;
use PHPUnit\Framework\TestCase;

/**
 * Polyglot worker codec round-trip checks.
 *
 * The polyglot PHP worker emits and decodes Avro generic-wrapper
 * payloads on the wire because the standalone Durable Workflow server's
 * universal codec list contains only `avro`. These tests pin the
 * envelope shape against the language-neutral spec — base64 of
 * `0x00 || avro_binary_record({json: string, version: int=1})` — so a
 * regression in the encoder catches at the unit level instead of at
 * the polyglot smoke.
 */
class PolyglotAvroTest extends TestCase
{
    public function test_envelope_round_trips_clean_categories(): void
    {
        $values = [
            null,
            true,
            false,
            0,
            42,
            -7,
            3.14,
            'polyglot',
            'こんにちは',
            ['a' => 1, 'b' => 'two', 'c' => [1, 2, 3]],
            [1, 'two', 3.0, null, true, ['nested' => 'map']],
        ];

        foreach ($values as $value) {
            $envelope = Avro::envelope($value);
            $this->assertSame('avro', $envelope['codec']);
            $this->assertIsString($envelope['blob']);

            $decoded = Avro::decodeEnvelope($envelope);
            $this->assertEquals($value, $decoded, 'avro envelope round-trip failed');
        }
    }

    public function test_decoded_blob_starts_with_generic_wrapper_prefix(): void
    {
        $blob = Avro::encode(['polyglot' => true]);
        $raw = base64_decode($blob, strict: true);

        $this->assertNotFalse($raw);
        $this->assertNotEmpty($raw);
        $this->assertSame("\x00", $raw[0], 'avro envelope must use the generic-wrapper prefix.');
    }

    public function test_decode_envelope_rejects_engine_specific_codec(): void
    {
        $this->expectExceptionMessage('language-neutral');

        Avro::decodeEnvelope([
            'codec' => 'workflow-serializer-y',
            'blob' => 'irrelevant',
        ]);
    }

    public function test_decoded_blob_round_trips_with_python_shape_payload(): void
    {
        // Mirrors the activity result the polyglot Python worker returns
        // from `polyglot.php-to-python.reverse`. Pinning the JSON-native
        // categories from the codec round-trip contract keeps the
        // PHP-side decode honest if a future refactor accidentally
        // narrows the supported set.
        $payload = [
            'runtime' => 'python',
            'input' => 'polyglot',
            'reversed' => 'tolygolp',
            'length' => 8,
        ];

        $envelope = Avro::envelope($payload);
        $this->assertSame($payload, Avro::decodeEnvelope($envelope));
    }
}
