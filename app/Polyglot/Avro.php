<?php

declare(strict_types=1);

namespace App\Polyglot;

use RuntimeException;

/**
 * Minimal Avro generic-wrapper codec for the polyglot worker.
 *
 * The Durable Workflow server uses an Avro generic-wrapper format on the
 * wire when `payload_codec` is `"avro"`. The wire layout is:
 *
 *     base64( 0x00 || avro_binary( record{ json: string, version: int } ) )
 *
 * The wrapper carries a single JSON document; class identity is not on
 * the wire. This encoder/decoder implements just that shape — it does not
 * cover typed-schema payloads (`0x01`) or any other Avro schema.
 */
final class Avro
{
    private const PREFIX_GENERIC_WRAPPER = "\x00";

    private const WRAPPER_VERSION = 1;

    /**
     * Encode a JSON-serializable value as a base64 Avro generic-wrapper blob.
     */
    public static function encode(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('failed to JSON-encode value for Avro: '.json_last_error_msg());
        }

        return base64_encode(
            self::PREFIX_GENERIC_WRAPPER
                .self::encodeAvroString($json)
                .self::encodeAvroLong(self::WRAPPER_VERSION)
        );
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

        $offset = 1;
        $json = self::readAvroString($raw, $offset);
        $version = self::readAvroLong($raw, $offset);

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

    private static function encodeAvroString(string $value): string
    {
        return self::encodeAvroLong(strlen($value)).$value;
    }

    /**
     * Avro `long` (and `int`) values use zigzag-encoded varints.
     *
     * Zigzag(n) is `(n << 1) ^ (n >> 63)` and is always non-negative, so
     * the varint emit loop only needs an arithmetic right shift.
     */
    private static function encodeAvroLong(int $value): string
    {
        $zz = ($value << 1) ^ ($value >> 63);

        $out = '';
        while (($zz & ~0x7F) !== 0) {
            $out .= chr(($zz & 0x7F) | 0x80);
            $zz >>= 7;
        }

        return $out.chr($zz & 0x7F);
    }

    private static function readAvroString(string $raw, int &$offset): string
    {
        $length = self::readAvroLong($raw, $offset);

        if ($length < 0) {
            throw new RuntimeException('Avro string length is negative after zigzag decode.');
        }

        if ($offset + $length > strlen($raw)) {
            throw new RuntimeException('Avro string runs past payload end.');
        }

        $value = substr($raw, $offset, $length);
        $offset += $length;

        return $value;
    }

    private static function readAvroLong(string $raw, int &$offset): int
    {
        $shift = 0;
        $result = 0;

        while (true) {
            if ($offset >= strlen($raw)) {
                throw new RuntimeException('Avro varint runs past payload end.');
            }

            $byte = ord($raw[$offset]);
            $offset++;
            $result |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                break;
            }

            $shift += 7;

            if ($shift >= 64) {
                throw new RuntimeException('Avro varint exceeds 64-bit length.');
            }
        }

        // Zigzag decode: (n >> 1) ^ -(n & 1)
        return ($result >> 1) ^ -($result & 1);
    }
}
