<?php

declare(strict_types=1);

namespace App\Polyglot;

use Apache\Avro\Datum\AvroIOBinaryDecoder;
use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\Datum\AvroIODatumReader;
use Apache\Avro\Datum\AvroIODatumWriter;
use Apache\Avro\IO\AvroStringIO;
use Apache\Avro\Schema\AvroSchema;
use RuntimeException;
use Workflow\Serializers\Avro as WorkflowAvro;

/**
 * Avro generic-wrapper adapter for the polyglot worker.
 *
 * The Durable Workflow server uses an Avro generic-wrapper format on the
 * wire when `payload_codec` is `"avro"`. The wire layout is:
 *
 *     base64( 0x00 || avro_binary( record{ json: string, version: int } ) )
 *
 * The wrapper carries a single JSON document; class identity is not on
 * the wire. Apache Avro encodes and decodes the record datum while this
 * adapter preserves the worker-facing helper API and `0x00` framing. It
 * does not cover typed-schema payloads (`0x01`) or any other Avro schema.
 */
final class Avro
{
    private const PREFIX_GENERIC_WRAPPER = "\x00";

    private const WRAPPER_VERSION = 1;

    private static ?AvroSchema $wrapperSchema = null;

    /**
     * Encode a JSON-serializable value as a base64 Avro generic-wrapper blob.
     */
    public static function encode(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('failed to JSON-encode value for Avro: '.json_last_error_msg());
        }

        return self::withApacheAvro(static function () use ($json): string {
            $io = new AvroStringIO();
            $io->write(self::PREFIX_GENERIC_WRAPPER);

            $writer = new AvroIODatumWriter(self::wrapperSchema());
            $writer->write([
                'json' => $json,
                'version' => self::WRAPPER_VERSION,
            ], new AvroIOBinaryEncoder($io));

            return base64_encode($io->string());
        });
    }

    /**
     * Decode a base64 Avro generic-wrapper blob back into a JSON-native value.
     */
    public static function decode(string $blob): mixed
    {
        $raw = base64_decode($blob, true);

        if ($raw === false || $raw === '') {
            throw new RuntimeException('Avro payload is empty or not valid base64.');
        }

        $prefix = $raw[0] ?? '';

        if ($prefix !== self::PREFIX_GENERIC_WRAPPER) {
            throw new RuntimeException(sprintf(
                'Unsupported Avro payload prefix 0x%02x (this codec supports only 0x00 generic wrappers).',
                ord($prefix)
            ));
        }

        $record = self::withApacheAvro(static function () use ($raw): array {
            $reader = new AvroIODatumReader(self::wrapperSchema());
            $decoder = new AvroIOBinaryDecoder(new AvroStringIO(substr($raw, 1)));
            $record = $reader->read($decoder);

            if (! is_array($record)) {
                throw new RuntimeException('Avro generic wrapper did not decode to a record.');
            }

            return $record;
        });

        $json = $record['json'] ?? null;
        $version = $record['version'] ?? null;

        if (! is_string($json)) {
            throw new RuntimeException('Avro generic wrapper is missing its `json` field.');
        }

        if (! is_int($version)) {
            throw new RuntimeException('Avro generic wrapper is missing its integer `version` field.');
        }

        if ($version !== self::WRAPPER_VERSION) {
            throw new RuntimeException(sprintf(
                'Unexpected Avro wrapper version %d (expected %d).',
                $version,
                self::WRAPPER_VERSION
            ));
        }

        return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Build a {codec, blob} envelope from a JSON-native value.
     *
     * @return array{codec: string, blob: string}
     */
    public static function envelope(mixed $value): array
    {
        return [
            'codec' => 'avro',
            'blob' => self::encode($value),
        ];
    }

    /**
     * Decode a {codec, blob} envelope, accepting either a wrapped envelope
     * shape or a raw blob string.
     *
     * @param array<string, mixed>|string|null $envelope
     */
    public static function decodeEnvelope(array|string|null $envelope): mixed
    {
        if ($envelope === null) {
            return null;
        }

        if (is_string($envelope)) {
            return self::decode($envelope);
        }

        $codec = $envelope['codec'] ?? 'avro';
        $blob = $envelope['blob'] ?? null;

        if (! is_string($blob)) {
            throw new RuntimeException('Avro envelope is missing a `blob` field.');
        }

        if ($codec !== 'avro') {
            throw new RuntimeException(sprintf(
                'Polyglot worker only handles the language-neutral `avro` codec; got %s.',
                json_encode($codec)
            ));
        }

        return self::decode($blob);
    }

    private static function wrapperSchema(): AvroSchema
    {
        if (self::$wrapperSchema === null) {
            self::$wrapperSchema = WorkflowAvro::parseSchema(WorkflowAvro::wrapperSchemaJson());
        }

        return self::$wrapperSchema;
    }

    /**
     * Apache Avro 1.12 still emits PHP 8.4 deprecations from internal casts.
     * Keep those dependency warnings out of the long-running worker process.
     */
    private static function withApacheAvro(callable $operation): mixed
    {
        set_error_handler(static fn (): bool => true, E_DEPRECATED);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
