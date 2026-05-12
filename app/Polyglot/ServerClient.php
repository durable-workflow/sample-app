<?php

declare(strict_types=1);

namespace App\Polyglot;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Workflow\V2\Support\WorkerProtocolVersion;

/**
 * Worker-plane HTTP client for a standalone Durable Workflow server.
 *
 * Wraps the small slice of the worker-plane API the polyglot PHP worker
 * needs: register, long-poll workflow tasks, complete workflow tasks,
 * and fail workflow tasks.
 *
 * Headers and timeouts mirror the published worker-protocol contract
 * (`X-Durable-Workflow-Protocol-Version`, namespace header, bearer
 * token), so this is the same wire shape an SDK-shipped polling worker
 * would emit. The protocol version is read from the installed
 * `durable-workflow/workflow` SDK so the worker always advertises the
 * version the rest of the package targets.
 */
final class ServerClient
{
    private readonly string $protocolVersion;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $namespace,
        ?string $protocolVersion = null,
    ) {
        $this->protocolVersion = $protocolVersion ?? WorkerProtocolVersion::VERSION;
    }

    /**
     * @param list<string> $supportedWorkflowTypes
     * @param list<string> $supportedActivityTypes
     */
    public function registerWorker(
        string $workerId,
        string $taskQueue,
        array $supportedWorkflowTypes = [],
        array $supportedActivityTypes = [],
        string $runtime = 'php',
        string $sdkVersion = 'durable-workflow-php/polyglot-sample',
    ): void {
        $this->workerPost('/api/worker/register', [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'runtime' => $runtime,
            'sdk_version' => $sdkVersion,
            'supported_workflow_types' => $supportedWorkflowTypes,
            'supported_activity_types' => $supportedActivityTypes,
        ]);
    }

    /**
     * Long-poll for the next workflow task, or return null on poll timeout.
     *
     * @return array<string, mixed>|null
     */
    public function pollWorkflowTask(string $workerId, string $taskQueue, int $timeoutSeconds = 30): ?array
    {
        $pollTimeoutSeconds = WorkerProtocolVersion::clampLongPollTimeout($timeoutSeconds);
        $requestTimeoutSeconds = max(
            $pollTimeoutSeconds,
            WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
        ) + 5;

        try {
            $response = $this->workerPost('/api/worker/workflow-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => $taskQueue,
                'timeout_seconds' => $pollTimeoutSeconds,
            ], requestTimeoutSeconds: $requestTimeoutSeconds);
        } catch (ConnectionException $exception) {
            if ($this->isHttpTimeout($exception)) {
                return null;
            }

            throw $exception;
        }

        if ($response === null) {
            return null;
        }

        $task = $response['task'] ?? null;

        return is_array($task) ? $task : null;
    }

    /**
     * @param list<array<string, mixed>> $commands
     */
    public function completeWorkflowTask(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        array $commands,
    ): void {
        $this->workerPost("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => $leaseOwner,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'commands' => $commands,
        ]);
    }

    public function failWorkflowTask(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        string $message,
        ?string $failureType = null,
    ): void {
        $failure = ['message' => $message];

        if ($failureType !== null) {
            $failure['type'] = $failureType;
        }

        $this->workerPost("/api/worker/workflow-tasks/{$taskId}/fail", [
            'lease_owner' => $leaseOwner,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'failure' => $failure,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function workerPost(string $path, array $body, ?int $requestTimeoutSeconds = null): ?array
    {
        $response = $this->http
            ->withHeaders($this->workerHeaders())
            ->timeout($requestTimeoutSeconds ?? 30)
            ->post($this->baseUrl.$path, $body);

        $this->ensureOk($response, $path);

        return $response->json();
    }

    /**
     * @return array<string, string>
     */
    private function workerHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Namespace' => $this->namespace,
            'X-Durable-Workflow-Protocol-Version' => $this->protocolVersion,
        ];
    }

    private function ensureOk(Response $response, string $path): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Durable Workflow server request to %s failed with HTTP %d: %s',
            $path,
            $response->status(),
            (string) $response->body(),
        ));
    }

    private function isHttpTimeout(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28');
    }
}
