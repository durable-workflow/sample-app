<?php

declare(strict_types=1);

namespace App\Sandbox;

/**
 * One agent-decided tool call to run inside a sandbox.
 *
 * Modelled as a small DTO rather than a class hierarchy so it serializes cleanly
 * through the v2 Avro codec when an activity argument crosses the worker boundary.
 */
final class SandboxToolCall
{
    /**
     * @param array<string, mixed> $args
     */
    public function __construct(
        public readonly string $type,
        public readonly array $args = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'args' => $this->args,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) $data['type'],
            args: is_array($data['args'] ?? null) ? $data['args'] : [],
        );
    }
}
