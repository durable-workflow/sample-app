<?php

declare(strict_types=1);

namespace Tests\Unit\Sandbox;

use App\Sandbox\Providers\E2bSandboxProvider;
use App\Sandbox\Providers\LocalSandboxProvider;
use App\Sandbox\SandboxConfig;
use App\Sandbox\SandboxManager;
use App\Sandbox\SandboxProvider;
use Illuminate\Config\Repository as ConfigRepository;
use InvalidArgumentException;
use Tests\TestCase;

class SandboxManagerTest extends TestCase
{
    public function test_default_driver_resolves_from_config(): void
    {
        $manager = $this->makeManager([
            'default' => 'local',
            'drivers' => [
                'local' => [
                    'workspace_root' => sys_get_temp_dir().'/sbx-mgr-ws',
                    'snapshot_root' => sys_get_temp_dir().'/sbx-mgr-snap',
                ],
            ],
        ]);

        $this->assertInstanceOf(LocalSandboxProvider::class, $manager->driver());
    }

    public function test_e2b_driver_is_built_from_config(): void
    {
        $manager = $this->makeManager([
            'default' => 'local',
            'drivers' => [
                'local' => [],
                'e2b' => [
                    'api_key' => 'test-key',
                    'template' => 'coding-agent',
                    'base_url' => 'https://api.e2b.dev',
                    'timeout_seconds' => 60,
                ],
            ],
        ]);

        $this->assertInstanceOf(E2bSandboxProvider::class, $manager->driver('e2b'));
    }

    public function test_unknown_driver_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeManager(['default' => 'local', 'drivers' => []])
            ->driver('not-a-real-provider');
    }

    public function test_extend_registers_a_custom_provider_for_swap_in(): void
    {
        $manager = $this->makeManager(['default' => 'custom', 'drivers' => ['custom' => []]]);

        $provider = new class implements SandboxProvider {
            public function name(): string
            {
                return 'custom';
            }

            public function provision(array $options = []): \App\Sandbox\SandboxHandle
            {
                return new \App\Sandbox\SandboxHandle('id', $this->name());
            }

            public function execute(\App\Sandbox\SandboxHandle $h, \App\Sandbox\SandboxToolCall $c): \App\Sandbox\SandboxToolResult
            {
                return new \App\Sandbox\SandboxToolResult(0);
            }

            public function suspend(\App\Sandbox\SandboxHandle $h): \App\Sandbox\SandboxHandle
            {
                return $h;
            }

            public function resume(\App\Sandbox\SandboxHandle $h): \App\Sandbox\SandboxHandle
            {
                return $h;
            }

            public function snapshot(\App\Sandbox\SandboxHandle $h): string
            {
                return 'snap';
            }

            public function restore(string $id): \App\Sandbox\SandboxHandle
            {
                return new \App\Sandbox\SandboxHandle('id-restored', $this->name());
            }

            public function destroy(\App\Sandbox\SandboxHandle $h): void
            {
            }
        };

        $manager->extend('custom', static fn () => $provider);

        $this->assertSame($provider, $manager->driver('custom'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function makeManager(array $config): SandboxManager
    {
        $repo = new ConfigRepository(['sandbox' => $config]);

        return new SandboxManager($this->app, new SandboxConfig($repo));
    }
}
