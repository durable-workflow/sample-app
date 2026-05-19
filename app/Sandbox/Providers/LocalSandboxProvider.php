<?php

declare(strict_types=1);

namespace App\Sandbox\Providers;

use App\Sandbox\Exceptions\SandboxGoneException;
use App\Sandbox\Exceptions\SandboxProvisionException;
use App\Sandbox\SandboxHandle;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxToolCall;
use App\Sandbox\SandboxToolResult;
use FilesystemIterator;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Subprocess-backed sandbox that runs each tool call against a local workspace
 * directory. Exists so the orchestration sample is runnable end-to-end without
 * external credentials and so the test suite can exercise the full lifecycle
 * (provision, execute, suspend, resume, snapshot, restore, destroy) without
 * mocking the provider boundary.
 *
 * Production users swap to a remote provider (E2B, Modal, Daytona, …) by
 * editing config/sandbox.php; the workflow code does not change.
 */
final class LocalSandboxProvider implements SandboxProvider
{
    public function __construct(
        private readonly string $workspaceRoot,
        private readonly string $snapshotRoot,
    ) {
        $this->ensureDir($this->workspaceRoot);
        $this->ensureDir($this->snapshotRoot);
    }

    public function name(): string
    {
        return 'local';
    }

    public function provision(array $options = []): SandboxHandle
    {
        $id = $options['restore_from'] ?? $this->generateId('sbx');
        $workspace = $this->workspacePath($id);

        if (! is_dir($workspace) && ! @mkdir($workspace, 0o755, true) && ! is_dir($workspace)) {
            throw new SandboxProvisionException("Failed to provision local sandbox workspace at {$workspace}");
        }

        return new SandboxHandle(
            id: $id,
            provider: $this->name(),
            metadata: ['workspace' => $workspace],
        );
    }

    public function execute(SandboxHandle $handle, SandboxToolCall $call): SandboxToolResult
    {
        $workspace = $this->requireWorkspace($handle);

        return match ($call->type) {
            'shell' => $this->runShell($workspace, $call),
            'write_file' => $this->writeFile($workspace, $call),
            'read_file' => $this->readFile($workspace, $call),
            'evict' => $this->evict($handle, $call),
            default => new SandboxToolResult(
                exitCode: 1,
                stderr: "Unsupported tool type: {$call->type}",
            ),
        };
    }

    public function suspend(SandboxHandle $handle): SandboxHandle
    {
        $this->requireWorkspace($handle);

        return $handle;
    }

    public function resume(SandboxHandle $handle): SandboxHandle
    {
        $this->requireWorkspace($handle);

        return $handle;
    }

    public function snapshot(SandboxHandle $handle): string
    {
        $workspace = $this->requireWorkspace($handle);
        $snapshotId = $this->generateId('snap');
        $snapshotFile = $this->snapshotPath($snapshotId);

        try {
            $tar = new PharData($snapshotFile);
            $tar->buildFromDirectory($workspace);
        } catch (Throwable $e) {
            throw new RuntimeException("Snapshot failed for sandbox {$handle->id}: {$e->getMessage()}", previous: $e);
        }

        return $snapshotId;
    }

    public function restore(string $snapshotId): SandboxHandle
    {
        $snapshotFile = $this->snapshotPath($snapshotId);

        if (! is_file($snapshotFile)) {
            throw new RuntimeException("Snapshot {$snapshotId} not found at {$snapshotFile}");
        }

        $handle = $this->provision();
        $workspace = $this->requireWorkspace($handle);

        try {
            $tar = new PharData($snapshotFile);
            $tar->extractTo($workspace, overwrite: true);
        } catch (Throwable $e) {
            throw new RuntimeException("Restore failed for snapshot {$snapshotId}: {$e->getMessage()}", previous: $e);
        }

        return $handle;
    }

    public function destroy(SandboxHandle $handle): void
    {
        $workspace = $this->workspacePath($handle->id);

        if (! is_dir($workspace)) {
            return;
        }

        $this->deleteRecursive($workspace);
    }

    private function runShell(string $workspace, SandboxToolCall $call): SandboxToolResult
    {
        $command = $call->args['command'] ?? null;
        $timeout = $call->args['timeout'] ?? 30;

        if (! is_string($command) || $command === '') {
            return new SandboxToolResult(exitCode: 1, stderr: 'shell tool requires a command argument');
        }

        $process = Process::fromShellCommandline($command, $workspace, null, null, (float) $timeout);

        try {
            $process->run();
        } catch (ProcessFailedException $e) {
            return new SandboxToolResult(
                exitCode: $process->getExitCode() ?? 1,
                stdout: (string) $process->getOutput(),
                stderr: (string) $process->getErrorOutput(),
            );
        }

        return new SandboxToolResult(
            exitCode: $process->getExitCode() ?? 0,
            stdout: (string) $process->getOutput(),
            stderr: (string) $process->getErrorOutput(),
        );
    }

    private function writeFile(string $workspace, SandboxToolCall $call): SandboxToolResult
    {
        $relative = $this->safeRelativePath($call->args['path'] ?? null);
        $contents = $call->args['contents'] ?? '';

        if ($relative === null) {
            return new SandboxToolResult(exitCode: 1, stderr: 'write_file requires a safe relative path');
        }

        $absolute = $workspace.DIRECTORY_SEPARATOR.$relative;
        $this->ensureDir(dirname($absolute));

        if (file_put_contents($absolute, (string) $contents) === false) {
            return new SandboxToolResult(exitCode: 1, stderr: "Failed to write {$relative}");
        }

        return new SandboxToolResult(
            exitCode: 0,
            stdout: "wrote {$relative} ".strlen((string) $contents).' bytes',
        );
    }

    private function readFile(string $workspace, SandboxToolCall $call): SandboxToolResult
    {
        $relative = $this->safeRelativePath($call->args['path'] ?? null);

        if ($relative === null) {
            return new SandboxToolResult(exitCode: 1, stderr: 'read_file requires a safe relative path');
        }

        $absolute = $workspace.DIRECTORY_SEPARATOR.$relative;

        if (! is_file($absolute)) {
            return new SandboxToolResult(exitCode: 1, stderr: "File not found: {$relative}");
        }

        $contents = file_get_contents($absolute);

        if ($contents === false) {
            return new SandboxToolResult(exitCode: 1, stderr: "Failed to read {$relative}");
        }

        return new SandboxToolResult(exitCode: 0, stdout: $contents);
    }

    private function evict(SandboxHandle $handle, SandboxToolCall $call): SandboxToolResult
    {
        $workspace = $this->requireWorkspace($handle);
        $reason = (string) ($call->args['reason'] ?? 'local sandbox evicted');

        $this->deleteRecursive($workspace);

        return new SandboxToolResult(
            exitCode: 0,
            stdout: "evicted {$handle->id}: {$reason}",
        );
    }

    private function requireWorkspace(SandboxHandle $handle): string
    {
        $workspace = $this->workspacePath($handle->id);

        if (! is_dir($workspace)) {
            throw new SandboxGoneException("Local sandbox {$handle->id} is gone (workspace removed).");
        }

        return $workspace;
    }

    private function workspacePath(string $id): string
    {
        return $this->workspaceRoot.DIRECTORY_SEPARATOR.$id;
    }

    private function snapshotPath(string $id): string
    {
        return $this->snapshotRoot.DIRECTORY_SEPARATOR.$id.'.tar';
    }

    private function safeRelativePath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $normalized = ltrim($value, '/');

        if (str_contains($normalized, '..') || str_contains($normalized, "\0")) {
            return null;
        }

        return $normalized;
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: {$dir}");
        }
    }

    private function deleteRecursive(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $path) {
            $real = (string) $path;

            if (is_dir($real) && ! is_link($real)) {
                @rmdir($real);
            } else {
                @unlink($real);
            }
        }

        @rmdir($dir);
    }

    private function generateId(string $prefix): string
    {
        return $prefix.'_'.bin2hex(random_bytes(8));
    }
}
