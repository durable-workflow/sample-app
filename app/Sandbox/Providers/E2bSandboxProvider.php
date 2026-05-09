<?php

declare(strict_types=1);

namespace App\Sandbox\Providers;

use App\Sandbox\Exceptions\SandboxGoneException;
use App\Sandbox\Exceptions\SandboxProvisionException;
use App\Sandbox\SandboxHandle;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxToolCall;
use App\Sandbox\SandboxToolResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * E2B Cloud sandbox provider.
 *
 * Wraps the E2B sandbox HTTP API behind the SandboxProvider contract so the
 * orchestration sample can run against a real ephemeral sandbox service. Network
 * errors map onto retryable exceptions that the activity layer retries; an
 * explicit 404 from E2B means the sandbox is gone, which the workflow recovers
 * from by re-provisioning and restoring from the latest snapshot.
 *
 * The HTTP wire format intentionally stays a thin pass-through; users with a
 * different E2B SDK version or an alternate provider (Modal, Daytona) replace
 * this class with their own SandboxProvider implementation and update
 * config/sandbox.php — workflow code does not change.
 */
final class E2bSandboxProvider implements SandboxProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $template,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly ?HttpFactory $http = null,
    ) {
    }

    public function name(): string
    {
        return 'e2b';
    }

    public function provision(array $options = []): SandboxHandle
    {
        if ($this->apiKey === '') {
            throw new SandboxProvisionException('E2B sandbox provider requires an API key (config/sandbox.php drivers.e2b.api_key).');
        }

        $payload = [
            'template' => $this->template,
            'metadata' => $options['metadata'] ?? [],
        ];

        if (isset($options['restore_from'])) {
            $payload['snapshot_id'] = (string) $options['restore_from'];
        }

        $response = $this->client()->post('/sandboxes', $payload);

        if (! $response->successful()) {
            throw new SandboxProvisionException("E2B provision failed: HTTP {$response->status()} {$response->body()}");
        }

        $body = (array) $response->json();
        $id = (string) ($body['sandbox_id'] ?? $body['id'] ?? '');

        if ($id === '') {
            throw new SandboxProvisionException('E2B provision response did not include a sandbox id.');
        }

        return new SandboxHandle(
            id: $id,
            provider: $this->name(),
            metadata: array_filter([
                'host' => $body['host'] ?? null,
                'template' => $this->template,
            ]),
        );
    }

    public function execute(SandboxHandle $handle, SandboxToolCall $call): SandboxToolResult
    {
        $response = $this->call(
            'POST',
            "/sandboxes/{$handle->id}/commands",
            ['type' => $call->type, 'args' => $call->args],
            $handle,
        );

        $body = (array) $response->json();

        return new SandboxToolResult(
            exitCode: (int) ($body['exit_code'] ?? 0),
            stdout: (string) ($body['stdout'] ?? ''),
            stderr: (string) ($body['stderr'] ?? ''),
        );
    }

    public function suspend(SandboxHandle $handle): SandboxHandle
    {
        $this->call('POST', "/sandboxes/{$handle->id}/suspend", [], $handle);

        return $handle;
    }

    public function resume(SandboxHandle $handle): SandboxHandle
    {
        $this->call('POST', "/sandboxes/{$handle->id}/resume", [], $handle);

        return $handle;
    }

    public function snapshot(SandboxHandle $handle): string
    {
        $response = $this->call('POST', "/sandboxes/{$handle->id}/snapshots", [], $handle);
        $body = (array) $response->json();
        $id = (string) ($body['snapshot_id'] ?? $body['id'] ?? '');

        if ($id === '') {
            throw new RuntimeException('E2B snapshot response did not include a snapshot id.');
        }

        return $id;
    }

    public function restore(string $snapshotId): SandboxHandle
    {
        return $this->provision(['restore_from' => $snapshotId]);
    }

    public function destroy(SandboxHandle $handle): void
    {
        try {
            $response = $this->client()->delete("/sandboxes/{$handle->id}");
        } catch (ConnectionException) {
            return;
        }

        // 404 is fine: the sandbox is already gone, which is what destroy() needs.
        if ($response->status() === 404) {
            return;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function call(string $method, string $path, array $payload, SandboxHandle $handle): \Illuminate\Http\Client\Response
    {
        try {
            $response = match (strtoupper($method)) {
                'GET' => $this->client()->get($path, $payload),
                'DELETE' => $this->client()->delete($path),
                default => $this->client()->send($method, $path, ['json' => $payload]),
            };
        } catch (ConnectionException $e) {
            throw new RuntimeException("E2B {$method} {$path} failed: {$e->getMessage()}", previous: $e);
        }

        if ($response->status() === 404) {
            throw new SandboxGoneException("E2B sandbox {$handle->id} no longer exists (404 on {$method} {$path}).");
        }

        if (! $response->successful()) {
            throw new RuntimeException("E2B {$method} {$path} failed: HTTP {$response->status()} {$response->body()}");
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        $factory = $this->http ?? Http::getFacadeRoot();

        /** @var HttpFactory $factory */
        return $factory
            ->baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson();
    }
}
