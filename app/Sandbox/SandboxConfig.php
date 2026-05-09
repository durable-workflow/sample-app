<?php

declare(strict_types=1);

namespace App\Sandbox;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Thin facade over Laravel config so SandboxManager and tests can read the
 * sandbox configuration without each one re-walking config keys.
 */
final class SandboxConfig
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    public function defaultDriver(): string
    {
        return (string) $this->config->get('sandbox.default', 'local');
    }

    /**
     * @return array<string, mixed>
     */
    public function driverConfig(string $name): array
    {
        $config = $this->config->get("sandbox.drivers.{$name}");

        return is_array($config) ? $config : [];
    }
}
