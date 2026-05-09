<?php

declare(strict_types=1);

namespace App\Sandbox;

/**
 * Opaque handle to a provisioned sandbox returned by SandboxProvider::provision()
 * and accepted by every other provider call. The id is the only field workflow
 * code stores between activity calls; metadata is provider-specific and may be
 * empty (LocalSandboxProvider) or hold connection details (E2bSandboxProvider).
 */
final class SandboxHandle
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            provider: (string) $data['provider'],
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }
}
