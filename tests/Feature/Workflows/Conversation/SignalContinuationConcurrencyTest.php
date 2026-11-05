<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows\Conversation;

use Composer\InstalledVersions;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use ReflectionFunction;
use RuntimeException;
use Tests\Fixtures\V2\TestSignalContinuationWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class SignalContinuationConcurrencyTest extends TestCase
{
    // Each actor needs committed rows, without RefreshDatabase's enclosing transaction.
    use DatabaseMigrations;

    public function test_named_storage_continuation_and_unrelated_signal_finish_without_retry(): void
    {
        foreach (['pcntl_fork', 'pcntl_alarm', 'posix_kill', 'stream_socket_pair'] as $function) {
            $this->assertTrue(function_exists($function), "The MySQL CI executor requires {$function}.");
        }
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
            'workflows.storage.transaction_attempts' => 1,
            'workflows.storage.connection' => 'shared',
            'cache.default' => 'array',
        ]);
        $this->assertTrue($this->app->isBooted());
        $this->configureSession();
        $metadata = $this->packageMetadata();

        $runs = [];
        for ($index = 0; $index < 3; $index++) {
            $stub = WorkflowStub::make(TestSignalContinuationWorkflow::class);
            $stub->start(0);
            $this->executeReadyTask($stub->runId());
            $runs[] = $stub;
        }
        // The unrelated run must lie between the two signal-index bounds.
        usort($runs, static fn (WorkflowStub $left, WorkflowStub $right): int => strcmp($left->runId(), $right->runId()));
        [$continuing, $signalled, $anchor] = $runs;
        $this->assertTrue($continuing->signal('resume')->accepted());
        $this->assertTrue($anchor->signal('resume')->accepted());
        $task = $this->readyTask($continuing->runId());

        $result = $this->overlap($task, $continuing->runId(), $signalled);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertContinuation($continuing);
        $signal = WorkflowSignal::query()->where('workflow_command_id', $result['signal']['command_id'])->sole();
        $original = $signal->getAttributes();
        $this->assertSame(SignalStatus::Received, $signal->status);
        $this->executeReadyTask($signalled->runId());
        $this->assertContinuation($signalled);
        $signal->refresh();
        $this->assertSame(SignalStatus::Applied, $signal->status);
        foreach (['id', 'workflow_command_id', 'command_sequence', 'arguments', 'payload_codec', 'received_at'] as $field) {
            $this->assertSame($original[$field], $signal->getRawOriginal($field));
        }
        $this->assertSame(1, WorkflowSignal::query()->where('workflow_command_id', $result['signal']['command_id'])->count());
        $this->assertSame('REPEATABLE-READ', $this->sessionFacts()['isolation']);
        $this->assertSame(0, $this->storage()->transactionLevel());

        fwrite(STDOUT, json_encode([
            'case' => 'named storage continuation and unrelated signal',
            'package' => $metadata,
            'parent' => $result['parent'],
            'signal_actor' => $result['signal'],
            'outcome' => 'both actors committed; signal applied once',
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /** @return array<string, mixed> */
    private function overlap(WorkflowTask $task, string $runId, WorkflowStub $signalled): array
    {
        $previousAlarm = pcntl_alarm(0);
        if ($previousAlarm > 0) {
            pcntl_alarm($previousAlarm);
            $this->fail('The case requires its own 30-second work alarm.');
        }
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge($connection);
        }
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($sockets[0]);
            $this->signalActor($sockets[1], $signalled);
        }
        if ($pid < 0) {
            fclose($sockets[0]);
            fclose($sockets[1]);
            $this->fail('The CI executor could not fork the signal actor.');
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 8);
        $reaped = false;
        $armed = true;
        $previousAsync = pcntl_async_signals();
        pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        try {
            pcntl_signal(SIGALRM, function () use ($pid, &$reaped): never {
                if (! $reaped) {
                    $this->stopChild($pid);
                    $reaped = true;
                }
                throw new RuntimeException('The concurrent app-kernel case exceeded 30 seconds.');
            });
            pcntl_alarm(30);
            $parent = $this->configureSession();
            DB::listen(function (QueryExecuted $query) use (&$armed, &$parent, $runId, $sockets): void {
                if (! $armed || ! str_starts_with($query->sql, 'update `workflow_runs` set `status`')
                    || ! in_array('continued', $query->bindings, true)
                    || ! in_array($runId, $query->bindings, true)) {
                    return;
                }
                $parent['barrier'] = $this->barrierFacts($query);
                $armed = false;
                fwrite($sockets[0], "continue-ready\n");
                $this->assertSame("history-locked\n", fgets($sockets[0]));
                fwrite($sockets[0], "proceed\n");
            });
            $error = null;
            try {
                $this->app->call([new RunWorkflowTask($task->id), 'handle']);
            } catch (Throwable $exception) {
                $error = $exception;
            }
            $line = fgets($sockets[0]);
            $this->assertIsString($line, $error === null ? 'The signal actor did not finish.' : $error->getMessage());
            $signal = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $status = $this->reap($pid);
            $reaped = true;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertNull($error, $error === null ? '' : $error::class.': '.$error->getMessage());
            $this->assertTrue($signal['accepted'] ?? false, json_encode($signal, JSON_THROW_ON_ERROR));
            $this->assertSame(1, $signal['attempts'], 'Signal admission must not conceal the cycle with a retry.');
            $this->assertNotSame($parent['connection_id'], $signal['session']['connection_id']);
            $this->assertFalse($armed, 'The SQL interleaving barrier was not reached.');

            return ['parent' => $parent, 'signal' => $signal];
        } finally {
            $armed = false;
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
            if (! $reaped) {
                $this->stopChild($pid);
            }
            fclose($sockets[0]);
            DB::purge('shared');
        }
    }

    /** @param resource $socket */
    private function signalActor($socket, WorkflowStub $stub): never
    {
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_alarm(20);
        stream_set_timeout($socket, 8);
        $attempts = 0;
        try {
            $session = $this->configureSession();
            $this->assertSame("continue-ready\n", fgets($socket));
            DB::listen(function (QueryExecuted $query) use ($socket, $stub, &$attempts, &$session): void {
                if (str_contains($query->sql, 'from `workflow_history_events`')
                    && str_contains($query->sql, 'for update') && in_array($stub->runId(), $query->bindings, true)) {
                    $session['barrier'] = $this->barrierFacts($query);
                    $attempts++;
                    fwrite($socket, "history-locked\n");
                    $this->assertSame("proceed\n", fgets($socket));
                }
            });
            $receipt = WorkflowStub::load($stub->id())->signal('resume');
            $result = [
                'accepted' => $receipt->accepted(),
                'command_id' => $receipt->commandId(),
                'attempts' => $attempts,
                'session' => $session,
            ];
        } catch (Throwable $error) {
            $result = ['accepted' => false, 'attempts' => $attempts, 'error' => $error::class.': '.$error->getMessage()];
        }
        fwrite($socket, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
        DB::disconnect('shared');
        fclose($socket);
        exit(0);
    }

    /** @return array<string, int|string|bool> */
    private function barrierFacts(QueryExecuted $query): array
    {
        $this->assertSame($this->storage(), $query->connection);
        $this->assertSame('shared', $query->connection->getName());
        $this->assertGreaterThan(0, $query->connection->transactionLevel());
        $this->assertTrue($query->connection->getPdo()->inTransaction());

        return [
            'connection' => $query->connection->getName(),
            'transaction_level' => $query->connection->transactionLevel(),
            'pdo_in_transaction' => $query->connection->getPdo()->inTransaction(),
        ];
    }

    /** @return array<string, int|string> */
    private function configureSession(): array
    {
        $storage = $this->storage();
        $this->assertSame('shared', $storage->getName());
        $this->assertSame('mysql', $storage->getDriverName(), 'This case requires the existing MySQL CI service.');
        $this->assertSame(0, $storage->transactionLevel());
        $storage->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $storage->statement('SET SESSION innodb_lock_wait_timeout = 5');

        return $this->sessionFacts();
    }

    /** @return array<string, int|string> */
    private function sessionFacts(): array
    {
        $storage = $this->storage();
        $row = $storage->selectOne('SELECT VERSION() AS mysql_version, @@transaction_isolation AS isolation, CONNECTION_ID() AS connection_id');
        $this->assertFalse(str_contains(strtolower($row->mysql_version), 'mariadb'));
        $this->assertSame('REPEATABLE-READ', $row->isolation);

        return [
            'connection' => $storage->getName(),
            'driver' => $storage->getDriverName(),
            'mysql_version' => $row->mysql_version,
            'isolation' => $row->isolation,
            'connection_id' => (int) $row->connection_id,
        ];
    }

    /** @return array<string, string> */
    private function packageMetadata(): array
    {
        $lock = json_decode(file_get_contents(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);
        $record = array_values(array_filter($lock['packages'], static fn (array $package): bool => $package['name'] === 'durable-workflow/workflow'))[0];
        $this->assertSame($record['version'], InstalledVersions::getPrettyVersion('durable-workflow/workflow'));
        $this->assertSame($record['source']['reference'], InstalledVersions::getReference('durable-workflow/workflow'));
        $package = realpath(InstalledVersions::getInstallPath('durable-workflow/workflow'));
        $this->assertIsString($package);
        foreach ([(new ReflectionClass(RunWorkflowTask::class))->getFileName(), (new ReflectionFunction('Workflow\\V2\\continueAsNew'))->getFileName()] as $source) {
            $this->assertIsString($source);
            $this->assertStringStartsWith($package.'/src/', realpath($source));
        }

        return [
            'core' => $record['version'],
            'reference' => $record['source']['reference'],
            'php' => PHP_VERSION,
            'laravel' => $this->app->version(),
            'fixture_sha256' => hash_file('sha256', base_path('tests/Fixtures/V2/TestSignalContinuationWorkflow.php')),
        ];
    }

    private function assertContinuation(WorkflowStub $stub): void
    {
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $this->assertSame('continued', $run->closed_reason);
        $this->assertSame(2, WorkflowRun::query()->where('workflow_instance_id', $stub->id())->count());
        foreach ([HistoryEventType::WorkflowContinuedAsNew, HistoryEventType::SignalApplied] as $type) {
            $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where('event_type', $type->value)->count());
        }
    }

    private function executeReadyTask(string $runId): void
    {
        $this->app->call([new RunWorkflowTask($this->readyTask($runId)->id), 'handle']);
    }

    private function readyTask(string $runId): WorkflowTask
    {
        return WorkflowTask::query()->where('workflow_run_id', $runId)->where('status', TaskStatus::Ready->value)->sole();
    }

    private function storage(): Connection
    {
        return DB::connection(config('workflows.storage.connection'));
    }

    private function reap(int $pid): int
    {
        $deadline = microtime(true) + 5;
        do {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result === $pid) {
                return $status;
            }
            if ($result < 0) {
                throw new RuntimeException('Cannot reap the owned signal actor.');
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('The owned signal actor did not exit within five seconds.');
    }

    private function stopChild(int $pid): void
    {
        $result = pcntl_waitpid($pid, $status, WNOHANG);
        if ($result === $pid) {
            return;
        }
        if ($result !== 0) {
            throw new RuntimeException('Cannot verify ownership of the signal actor before cleanup.');
        }
        posix_kill($pid, SIGKILL);
        $this->reap($pid);
    }
}
