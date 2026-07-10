<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apache\Avro\Datum\AvroIOBinaryDecoder;
use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\Datum\AvroIODatumReader;
use Apache\Avro\Datum\AvroIODatumWriter;
use Apache\Avro\IO\AvroStringIO;
use App\Polyglot\Avro;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\Serializers\Avro as WorkflowAvro;

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

    public function test_sample_worker_bytes_are_readable_by_official_apache_avro(): void
    {
        $value = [
            'runtime' => 'php',
            'message' => 'official package interoperability',
            'count' => 3,
        ];
        $raw = base64_decode(Avro::encode($value), strict: true);

        $this->assertNotFalse($raw);
        $this->assertSame("\x00", $raw[0]);

        $reader = new AvroIODatumReader(WorkflowAvro::parseSchema(WorkflowAvro::wrapperSchemaJson()));
        $record = $reader->read(new AvroIOBinaryDecoder(new AvroStringIO(substr($raw, 1))));

        $this->assertSame(1, $record['version']);
        $this->assertSame($value, json_decode($record['json'], associative: true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_sample_worker_decodes_bytes_written_by_official_apache_avro(): void
    {
        $value = [
            'runtime' => 'apache-avro-php',
            'unicode' => 'こんにちは',
            'nested' => ['ready' => true],
        ];
        $blob = self::officialPackageBlob($value);

        $this->assertSame($value, Avro::decode($blob));
        $this->assertSame($blob, Avro::encode($value));
    }

    public function test_decode_preserves_generic_wrapper_version_validation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected Avro wrapper version 2 (expected 1).');

        Avro::decode(self::officialPackageBlob(['runtime' => 'future'], version: 2));
    }

    /** @param array<string, mixed> $value */
    #[DataProvider('crossLanguageGenericWrapperFixtures')]
    public function test_official_codec_matches_cross_language_generic_wrapper_fixtures(
        array $value,
        string $blob,
    ): void {
        $this->assertSame($value, Avro::decode($blob));
        $this->assertSame($blob, Avro::encode($value));
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

    /**
     * Canonical generic-wrapper bytes shared by the Python and Rust SDKs.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function crossLanguageGenericWrapperFixtures(): iterable
    {
        yield 'Python SDK activity result' => [
            [
                'runtime' => 'python',
                'input' => 'polyglot',
                'reversed' => 'tolygolp',
                'length' => 8,
            ],
            'AJABeyJydW50aW1lIjoicHl0aG9uIiwiaW5wdXQiOiJwb2x5Z2xvdCIsInJldmVyc2VkIjoidG9seWdvbHAiLCJsZW5ndGgiOjh9Ag==',
        ];

        yield 'Rust SDK generic wrapper' => [
            [
                'count' => 3,
                'greeting' => 'hello',
                'ok' => true,
            ],
            'AFB7ImNvdW50IjozLCJncmVldGluZyI6ImhlbGxvIiwib2siOnRydWV9Ag==',
        ];
    }

    private static function officialPackageBlob(mixed $value, int $version = 1): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $io = new AvroStringIO();
        $io->write("\x00");

        $writer = new AvroIODatumWriter(WorkflowAvro::parseSchema(WorkflowAvro::wrapperSchemaJson()));
        $writer->write([
            'json' => $json,
            'version' => $version,
        ], new AvroIOBinaryEncoder($io));

        return base64_encode($io->string());
    }
}
