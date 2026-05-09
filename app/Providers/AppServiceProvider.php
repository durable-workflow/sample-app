<?php

namespace App\Providers;

use App\Sandbox\SandboxConfig;
use App\Sandbox\SandboxManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SandboxConfig::class, fn ($app): SandboxConfig
            => new SandboxConfig($app->make('config')));

        $this->app->singleton(SandboxManager::class, fn ($app): SandboxManager
            => new SandboxManager($app, $app->make(SandboxConfig::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
