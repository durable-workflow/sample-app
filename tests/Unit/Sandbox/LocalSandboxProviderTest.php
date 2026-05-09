<?php

declare(strict_types=1);

namespace Tests\Unit\Sandbox;

use App\Sandbox\Exceptions\SandboxGoneException;
use App\Sandbox\Providers\LocalSandboxProvider;
use App\Sandbox\SandboxToolCall;
use Tests\TestCase;

class LocalSandboxProviderTest extends TestCase
{
    private string $workspaceRoot;

    private string $snapshotRoot;

    private LocalSandboxProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspaceRoot = sys_get_temp_dir().'/durable-sandbox-test-'.bin2hex(random_bytes(4));
        $this->snapshotRoot = sys_get_temp_dir().'/durable-sandbox-snapshots-test-'.bin2hex(random_bytes(4));

        $this->provider = new LocalSandboxProvider($this->workspaceRoot, $this->snapshotRoot);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->workspaceRoot);
        $this->deleteDir($this->snapshotRoot);

        parent::tearDown();
    }

    public function test_provision_then_execute_then_destroy_round_trips_a_workspace(): void
    {
        $handle = $this->provider->provision();

        $write = $this->provider->execute(
            $handle,
            new SandboxToolCall('write_file', ['path' => 'a.txt', 'contents' => 'hello']),
        );
        $this->assertSame(0, $write->exitCode);

        $read = $this->provider->execute(
            $handle,
            new SandboxToolCall('read_file', ['path' => 'a.txt']),
        );
        $this->assertSame('hello', $read->stdout);

        $shell = $this->provider->execute(
            $handle,
            new SandboxToolCall('shell', ['command' => 'ls']),
        );
        $this->assertSame(0, $shell->exitCode);
        $this->assertStringContainsString('a.txt', $shell->stdout);

        $this->provider->destroy($handle);

        $this->expectException(SandboxGoneException::class);
        $this->provider->execute($handle, new SandboxToolCall('shell', ['command' => 'ls']));
    }

    public function test_snapshot_restore_round_trips_workspace_state(): void
    {
        $handle = $this->provider->provision();

        $this->provider->execute(
            $handle,
            new SandboxToolCall('write_file', ['path' => 'state.txt', 'contents' => 'durable']),
        );

        $snapshotId = $this->provider->snapshot($handle);
        $this->provider->destroy($handle);

        $restored = $this->provider->restore($snapshotId);
        $read = $this->provider->execute(
            $restored,
            new SandboxToolCall('read_file', ['path' => 'state.txt']),
        );

        $this->assertSame('durable', $read->stdout);
        $this->assertNotSame($handle->id, $restored->id, 'restore should produce a fresh sandbox id');
    }

    public function test_destroy_is_idempotent(): void
    {
        $handle = $this->provider->provision();
        $this->provider->destroy($handle);
        $this->provider->destroy($handle); // second call must not raise
        $this->assertTrue(true);
    }

    public function test_write_file_rejects_path_traversal(): void
    {
        $handle = $this->provider->provision();
        $result = $this->provider->execute(
            $handle,
            new SandboxToolCall('write_file', ['path' => '../escape.txt', 'contents' => 'no']),
        );

        $this->assertSame(1, $result->exitCode);
    }

    public function test_unsupported_tool_type_returns_error_result_not_throw(): void
    {
        $handle = $this->provider->provision();
        $result = $this->provider->execute($handle, new SandboxToolCall('drop_database'));
        $this->assertSame(1, $result->exitCode);
        $this->assertStringContainsString('Unsupported tool type', $result->stderr);
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $path) {
            if (is_dir((string) $path) && ! is_link((string) $path)) {
                @rmdir((string) $path);
            } else {
                @unlink((string) $path);
            }
        }

        @rmdir($dir);
    }
}
