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
 * needs: register, long-poll workflow/activity tasks, complete tasks,
 * and fail tasks.
 *
 * Headers and timeouts mirror the published worker-protocol contract
 * (`X-Durable-Workflow-Protocol-Version`, namespace header, bearer
 * token), so this is the same wire shape an SDK-shipped polling worker
 * would emit. The protocol version is read from the installed
 * embedded `durable-workflow/workflow` engine so this Laravel-only adapter
 * advertises the version the rest of the package targets.
 */
final class ServerClient
{
    private const HEARTBEAT_TIMEOUT_SECONDS = 2;

    private readonly string $protocolVersion;

    private readonly int $processStartedAt;

    /**
     * @var array<string, string>
     */
    private array $pollRequestIds = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $namespace,
        ?string $protocolVersion = null,
    ) {
        $this->protocolVersion = $protocolVersion ?? WorkerProtocolVersion::VERSION;
        $this->processStartedAt = time();
    }

    /**
     * @param list<string> $supportedWorkflowTypes
     * @param list<string> $supportedActivityTypes
     * @param list<string> $capabilities
     * @return array<string, mixed>|null
     */
    public function registerWorker(
        string $workerId,
        string $taskQueue,
        array $supportedWorkflowTypes = [],
        array $supportedActivityTypes = [],
        array $capabilities = [],
        string $runtime = 'php',
        string $sdkVersion = 'durable-workflow-php/polyglot-sample',
    ): ?array {
        return $this->workerPost('/api/worker/register', [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'runtime' => $runtime,
            'sdk_version' => $sdkVersion,
            'supported_workflow_types' => $supportedWorkflowTypes,
            'supported_activity_types' => $supportedActivityTypes,
            'capabilities' => $capabilities,
            'process_metrics' => $this->processMetrics(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function heartbeatWorker(string $workerId): ?array
    {
        try {
            return $this->workerPost('/api/worker/heartbeat', [
                'worker_id' => $workerId,
                'process_metrics' => $this->processMetrics(),
            ], requestTimeoutSeconds: self::HEARTBEAT_TIMEOUT_SECONDS);
        } catch (ConnectionException $exception) {
            if ($this->isHttpTimeout($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Long-poll for the next workflow task, or return null on poll timeout.
     *
     * @return array<string, mixed>|null
     */
    public function pollWorkflowTask(string $workerId, string $taskQueue, int $timeoutSeconds = 30): ?array
    {
        return $this->pollTask('/api/worker/workflow-tasks/poll', $workerId, $taskQueue, $timeoutSeconds);
    }

    /**
     * Long-poll for the next activity task, or return null on poll timeout.
     *
     * @return array<string, mixed>|null
     */
    public function pollActivityTask(string $workerId, string $taskQueue, int $timeoutSeconds = 30): ?array
    {
        return $this->pollTask('/api/worker/activity-tasks/poll', $workerId, $taskQueue, $timeoutSeconds);
    }

    /**
     * Long-poll for the next query task, or return null on poll timeout.
     *
     * @return array<string, mixed>|null
     */
    public function pollQueryTask(string $workerId, string $taskQueue, int $timeoutSeconds = 30): ?array
    {
        return $this->pollTask('/api/worker/query-tasks/poll', $workerId, $taskQueue, $timeoutSeconds);
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

    public function completeActivityTask(
        string $taskId,
        string $leaseOwner,
        string $activityAttemptId,
        mixed $result,
    ): void {
        $this->workerPost("/api/worker/activity-tasks/{$taskId}/complete", [
            'activity_attempt_id' => $activityAttemptId,
            'lease_owner' => $leaseOwner,
            'result' => Avro::envelope($result),
        ]);
    }

    public function failActivityTask(
        string $taskId,
        string $leaseOwner,
        string $activityAttemptId,
        string $message,
        ?string $failureType = null,
        bool $nonRetryable = false,
        mixed $details = null,
    ): void {
        $failure = ['message' => $message];

        if ($failureType !== null) {
            $failure['type'] = $failureType;
        }

        if ($nonRetryable) {
            $failure['non_retryable'] = true;
        }

        if ($details !== null) {
            $failure['details'] = Avro::envelope($details);
        }

        $this->workerPost("/api/worker/activity-tasks/{$taskId}/fail", [
            'activity_attempt_id' => $activityAttemptId,
            'lease_owner' => $leaseOwner,
            'failure' => $failure,
        ]);
    }

    public function completeQueryTask(
        string $queryTaskId,
        string $leaseOwner,
        int $queryTaskAttempt,
        mixed $result,
    ): void {
        $response = $this->workerPost("/api/worker/query-tasks/{$queryTaskId}/complete", [
            'lease_owner' => $leaseOwner,
            'query_task_attempt' => $queryTaskAttempt,
            'result' => $result,
            'result_envelope' => Avro::envelope($result),
        ]);

        $this->ensureWorkerOutcome($response, 'completed', 'complete query task', $queryTaskId);
    }

    public function failQueryTask(
        string $queryTaskId,
        string $leaseOwner,
        int $queryTaskAttempt,
        string $message,
        string $reason = 'query_rejected',
        ?string $failureType = null,
    ): void {
        $failure = [
            'message' => $message,
            'reason' => $reason,
        ];

        if ($failureType !== null) {
            $failure['type'] = $failureType;
        }

        $response = $this->workerPost("/api/worker/query-tasks/{$queryTaskId}/fail", [
            'lease_owner' => $leaseOwner,
            'query_task_attempt' => $queryTaskAttempt,
            'failure' => $failure,
        ]);

        $this->ensureWorkerOutcome($response, 'failed', 'fail query task', $queryTaskId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pollTask(string $path, string $workerId, string $taskQueue, int $timeoutSeconds): ?array
    {
        $pollTimeoutSeconds = WorkerProtocolVersion::clampLongPollTimeout($timeoutSeconds);
        $pollRequestKey = $this->pollRequestKey($path, $workerId, $taskQueue);
        $pollRequestId = $this->pollRequestIds[$pollRequestKey]
            ?? 'polyglot-poll-'.bin2hex(random_bytes(16));
        $this->pollRequestIds[$pollRequestKey] = $pollRequestId;

        try {
            $response = $this->workerPost($path, [
                'worker_id' => $workerId,
                'task_queue' => $taskQueue,
                'poll_request_id' => $pollRequestId,
                'timeout_seconds' => $pollTimeoutSeconds,
            ], requestTimeoutSeconds: $this->requestTimeoutForPoll($pollTimeoutSeconds));
        } catch (ConnectionException $exception) {
            if ($this->isHttpTimeout($exception)) {
                return null;
            }

            unset($this->pollRequestIds[$pollRequestKey]);

            throw $exception;
        }

        unset($this->pollRequestIds[$pollRequestKey]);

        if ($response === null) {
            return null;
        }

        $task = $response['task'] ?? null;

        return is_array($task) ? $task : null;
    }

    private function requestTimeoutForPoll(int $pollTimeoutSeconds): int
    {
        if ($pollTimeoutSeconds === 0) {
            return 1;
        }

        // Keep the client-side timeout comfortably above the server-held
        // long-poll window. Under loaded conformance runs, request handling can
        // overrun the nominal poll timeout; if the HTTP client gives up first,
        // the server can still lease a task to a worker that never receives it.
        return $pollTimeoutSeconds + 15;
    }

    /**
     * @return array<string, int|string>
     */
    private function processMetrics(): array
    {
        return [
            'process_id' => getmypid() ?: 0,
            'host' => gethostname() ?: 'unknown',
            'process_started_at' => (string) $this->processStartedAt,
        ];
    }

    private function pollRequestKey(string $path, string $workerId, string $taskQueue): string
    {
        return implode('|', [$path, $workerId, $taskQueue]);
    }

    /**
     * @param array<string, mixed>|null $response
     */
    private function ensureWorkerOutcome(
        ?array $response,
        string $expectedOutcome,
        string $operation,
        string $taskId,
    ): void {
        if (($response['outcome'] ?? null) === $expectedOutcome) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Durable Workflow server rejected %s for %s: %s',
            $operation,
            $taskId,
            json_encode($response, JSON_UNESCAPED_SLASHES) ?: 'empty response',
        ));
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
