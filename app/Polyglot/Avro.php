<?php

declare(strict_types=1);

namespace App\Polyglot;

use RuntimeException;
use Workflow\Serializers\Avro as WorkflowAvro;
use Workflow\Serializers\CodecDecodeException;

/**
 * Polyglot worker adapter for the shared fixed Avro Value protocol.
 *
 * The embedded runtime owns the canonical schema, fingerprint registry, and
 * native-value policy so sample workers cannot drift to a different wire form.
 */
final class Avro
{
    public static function encode(mixed $value): string
    {
        return WorkflowAvro::serialize($value);
    }

    public static function decode(string $blob): mixed
    {
        try {
            return WorkflowAvro::unserialize($blob);
        } catch (CodecDecodeException $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }
    }

    /** @return array{codec: string, blob: string} */
    public static function envelope(mixed $value): array
    {
        return ['codec' => 'avro', 'blob' => self::encode($value)];
    }

    /** @param array<string, mixed>|string|null $envelope */
    public static function decodeEnvelope(array|string|null $envelope): mixed
    {
        if ($envelope === null) {
            return null;
        }
        if (is_string($envelope)) {
            return self::decode($envelope);
        }
        if (($envelope['codec'] ?? 'avro') !== 'avro') {
            throw new RuntimeException('Polyglot worker expected an `avro` payload envelope.');
        }
        if (! isset($envelope['blob']) || ! is_string($envelope['blob'])) {
            throw new RuntimeException('Avro envelope is missing a string `blob` field.');
        }

        return self::decode($envelope['blob']);
    }
}
