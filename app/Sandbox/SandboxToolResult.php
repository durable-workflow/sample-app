<?php

declare(strict_types=1);

namespace App\Sandbox;

/**
 * Result of dispatching a SandboxToolCall through SandboxProvider::execute().
 *
 * exit_code follows process convention (0 success, non-zero failure). stdout/stderr
 * are returned verbatim so the workflow can fold them into the agent transcript;
 * truncate at the provider layer if they would otherwise blow up workflow history.
 */
final class SandboxToolResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
    ) {
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'exit_code' => $this->exitCode,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            exitCode: (int) ($data['exit_code'] ?? 0),
            stdout: (string) ($data['stdout'] ?? ''),
            stderr: (string) ($data['stderr'] ?? ''),
        );
    }
}
