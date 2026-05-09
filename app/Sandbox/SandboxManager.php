<?php

declare(strict_types=1);

namespace App\Sandbox;

use App\Sandbox\Providers\E2bSandboxProvider;
use App\Sandbox\Providers\LocalSandboxProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves a SandboxProvider by name, using config('sandbox') for connection
 * details. Activities call SandboxManager::driver() and let config decide which
 * concrete provider runs — swapping providers is a config edit, not a workflow
 * rewrite.
 */
class SandboxManager
{
    /**
     * @var array<string, callable(Container, array<string, mixed>): SandboxProvider>
     */
    private array $factories = [];

    /**
     * @var array<string, SandboxProvider>
     */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
        private readonly SandboxConfig $config,
    ) {
        $this->registerDefaults();
    }

    public function driver(?string $name = null): SandboxProvider
    {
        $name ??= $this->config->defaultDriver();

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (! isset($this->factories[$name])) {
            throw new InvalidArgumentException("Sandbox provider [{$name}] is not registered. Add it to config/sandbox.php or call SandboxManager::extend().");
        }

        $provider = ($this->factories[$name])($this->container, $this->config->driverConfig($name));

        return $this->resolved[$name] = $provider;
    }

    /**
     * @param callable(Container, array<string, mixed>): SandboxProvider $factory
     */
    public function extend(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
        unset($this->resolved[$name]);
    }

    private function registerDefaults(): void
    {
        $this->factories['local'] = static fn (Container $c, array $cfg): SandboxProvider
            => new LocalSandboxProvider(
                workspaceRoot: (string) ($cfg['workspace_root'] ?? sys_get_temp_dir().'/durable-sandbox'),
                snapshotRoot: (string) ($cfg['snapshot_root'] ?? sys_get_temp_dir().'/durable-sandbox-snapshots'),
            );

        $this->factories['e2b'] = static fn (Container $c, array $cfg): SandboxProvider
            => new E2bSandboxProvider(
                apiKey: (string) ($cfg['api_key'] ?? ''),
                template: (string) ($cfg['template'] ?? 'base'),
                baseUrl: (string) ($cfg['base_url'] ?? 'https://api.e2b.dev'),
                timeoutSeconds: (int) ($cfg['timeout_seconds'] ?? 300),
            );
    }
}
