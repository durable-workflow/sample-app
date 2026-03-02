<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;

class Init extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize the Laravel Workflow Sample App';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Ensuring .env file exists and APP_KEY is set...');
        $this->ensureEnv();

        $this->info('Setting ASSET_URL...');
        $this->setAssetUrl();

        $this->info('Seeding .env with recommended defaults...');
        $this->seedEnvDefaults();

        $this->info('Running migrations...');
        
        Artisan::call('migrate', ['--force' => true]);

        $this->info('Updating README.md with Codespace URL...');
        $this->updateReadme();

        $this->info('Installing npm dependencies...');
        Process::run('npm install');

        $this->info('Installing Playwright components...');
        $this->installPlaywright();

        $this->info('Done!');
    }

    /**
     * Seed the environment file with a set of recommended default values.
     */
    protected function seedEnvDefaults(): void
    {
        $defaults = [
            'DB_HOST' => 'mysql',
            'DB_DATABASE' => 'sample',
            'DB_USERNAME' => 'laravel',
            'DB_PASSWORD' => 'password',
            'QUEUE_CONNECTION' => 'redis',
            'CACHE_STORE' => 'redis',
            'REDIS_HOST' => 'redis',
            'SHARED_DB_HOST' => 'mysql',
            'SHARED_DB_PORT' => '3306',
            'SHARED_DB_DATABASE' => 'sample',
            'SHARED_DB_USERNAME' => 'laravel',
            'SHARED_DB_PASSWORD' => 'password',
        ];

        // Ensure APP_KEY exists in the file; if key:generate already ran, keep it.
        $envFile = $this->laravel->environmentFilePath();
        $envContents = file_exists($envFile) ? file_get_contents($envFile) : '';

        if (preg_match('/^APP_KEY=(.+)$/m', $envContents, $matches) && !empty(trim($matches[1]))) {
            $defaults['APP_KEY'] = trim($matches[1]);
        } else {
            // If APP_KEY wasn't generated for some reason, leave it blank so existing logic can generate it.
            $defaults['APP_KEY'] = '';
        }

        foreach ($defaults as $key => $value) {
            // Don't overwrite an existing non-empty value for APP_KEY
            if ($key === 'APP_KEY' && $value === '') {
                continue;
            }

            $this->setEnvVariable($key, $value);
        }

        // After modifying the .env file, reload env and update runtime config values
        $this->reloadEnvConfig();
    }

    /**
     * Reload environment variables and update runtime config from env values.
     */
    protected function reloadEnvConfig(): void
    {
        $envFile = base_path('.env');
        $pairs = [];

        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0) {
                    continue;
                }

                if (! str_contains($line, '=')) {
                    continue;
                }

                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);

                // Strip surrounding quotes if present
                if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                    $v = substr($v, 1, -1);
                }

                $pairs[$k] = $v;

                // Populate runtime env
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
            }
        }

        $config = $this->laravel->make('config');

        // Use parsed values (fallback to existing config)
        $config->set('database.default', $pairs['DB_CONNECTION'] ?? $config->get('database.default'));

        $config->set('database.connections.mysql.host', $pairs['DB_HOST'] ?? $config->get('database.connections.mysql.host'));
        $config->set('database.connections.mysql.port', $pairs['DB_PORT'] ?? $config->get('database.connections.mysql.port'));
        $config->set('database.connections.mysql.database', $pairs['DB_DATABASE'] ?? $config->get('database.connections.mysql.database'));
        $config->set('database.connections.mysql.username', $pairs['DB_USERNAME'] ?? $config->get('database.connections.mysql.username'));
        $config->set('database.connections.mysql.password', $pairs['DB_PASSWORD'] ?? $config->get('database.connections.mysql.password'));

        $config->set('database.connections.shared.host', $pairs['SHARED_DB_HOST'] ?? $config->get('database.connections.shared.host'));
        $config->set('database.connections.shared.port', $pairs['SHARED_DB_PORT'] ?? $config->get('database.connections.shared.port'));
        $config->set('database.connections.shared.database', $pairs['SHARED_DB_DATABASE'] ?? $config->get('database.connections.shared.database'));
        $config->set('database.connections.shared.username', $pairs['SHARED_DB_USERNAME'] ?? $config->get('database.connections.shared.username'));
        $config->set('database.connections.shared.password', $pairs['SHARED_DB_PASSWORD'] ?? $config->get('database.connections.shared.password'));

        try {
            DB::purge('mysql');
            DB::purge('shared');
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Ensure an .env file exists (copy from .env.example if needed) and generate APP_KEY.
     */
    protected function ensureEnv(): void
    {
        $envFile = base_path('.env');
        $exampleFile = base_path('.env.example');

        if (! file_exists($envFile) && file_exists($exampleFile)) {
            copy($exampleFile, $envFile);
            $this->info('.env created from .env.example');
            try {
                if (class_exists(Dotenv::class)) {
                    Dotenv::createImmutable(base_path())->safeLoad();
                }
            } catch (\Throwable $e) {
                // If we can't reload env here, continue; later steps may still work.
            }
        }

        // If .env exists but APP_KEY is empty or missing, run key:generate
        $envContents = file_exists($envFile) ? file_get_contents($envFile) : '';
        $hasKey = preg_match('/^APP_KEY=(.+)$/m', $envContents, $matches) && !empty(trim($matches[1]));

        if (! $hasKey) {
            Artisan::call('key:generate', ['--ansi' => true]);
            $this->info('Application key generated.');
            try {
                if (class_exists(Dotenv::class)) {
                    Dotenv::createImmutable(base_path())->safeLoad();
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Set ASSET_URL in env based on codespace name.
     */
    protected function setAssetUrl()
    {
        $codespaceName = env('CODESPACE_NAME');
        $portDomain = env('GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN');

        if (!$codespaceName || !$portDomain) {
            $this->info('GitHub Codespaces variables not found; skipping ASSET_URL setup.');
            return;
        }

        $assetUrl = "https://{$codespaceName}-80.{$portDomain}";

        if ($this->setEnvVariable('ASSET_URL', $assetUrl)) {
            $this->info('ASSET_URL set successfully in .env file.');
        }
    }

    /**
     * Update README.md with the correct Codespace URL.
     */
    protected function updateReadme()
    {
        $codespaceName = env('CODESPACE_NAME');
        $portDomain = env('GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN');

        if (!$codespaceName || !$portDomain) {
            $this->info('GitHub Codespaces variables not found; skipping README Codespace URL update.');
            return;
        }

        $realUrl = "https://{$codespaceName}-80.{$portDomain}";

        $readmeFile = base_path('README.md');
        if (!file_exists($readmeFile)) {
            $this->error('README.md file not found.');
            return;
        }

        $readmeContents = file_get_contents($readmeFile);
        $updatedReadme = preg_replace(
            '/https:\/\/\[your-codespace-name\]-80\.preview\.app\.github\.dev/',
            $realUrl,
            $readmeContents
        );

        file_put_contents($readmeFile, $updatedReadme);

        $this->info('README.md updated successfully.');
    }

    /**
     * Set a given key-value pair in the .env file.
     *
     * @param string $key
     * @param string $value
     * @return bool
     */
    protected function setEnvVariable($key, $value)
    {
        $envFile = $this->laravel->environmentFilePath();
        $envContents = file_get_contents($envFile);
        $pattern = "/^{$key}=.*/m";
        $replacement = "{$key}={$value}";

        if (preg_match($pattern, $envContents)) {
            $envContents = preg_replace($pattern, $replacement, $envContents);
        } else {
            $envContents .= "\n{$replacement}";
        }

        file_put_contents($envFile, $envContents);
        return true;
    }

    /**
     * Install Playwright components with progress tracking.
     */
    protected function installPlaywright()
    {
        $totalSteps = 5;
        $bar = $this->output->createProgressBar($totalSteps);
        $bar->start();

        $completedSteps = 0;
        $components = 5;
        $stepsPerComponent = $totalSteps / $components;

        Process::run('npx playwright install', function (string $type, string $output) use ($bar, &$completedSteps, $stepsPerComponent) {
            if ($type === 'out') {
                if (preg_match('/\|\s+(\d+)%\s+of/', $output, $matches)) {
                    $percent = (int) $matches[1];
                    $progressWithinComponent = ($percent / 100) * $stepsPerComponent;
                    $newProgress = min($completedSteps + (int)$progressWithinComponent, 100);
                    $bar->setProgress($newProgress);
                }

                if (preg_match('/downloaded to/', $output)) {
                    $completedSteps += $stepsPerComponent;
                    $bar->setProgress($completedSteps);
                }
            }
        });

        $bar->finish();
        $this->newLine();
    }
}
