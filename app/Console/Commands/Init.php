<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

class Init extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:init
                            {--schema-path= : Optional schema dump used to bootstrap an empty database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize the Laravel Workflow Sample App';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $this->info('Ensuring .env file exists...');
            $this->ensureEnv();

            $this->info('Seeding .env with recommended defaults...');
            $this->seedEnvDefaults();

            $this->info('Ensuring APP_KEY is set...');
            $this->ensureApplicationKey();

            $this->info('Running migrations...');
            $migrationOptions = [
                '--force' => true,
                '--no-interaction' => true,
            ];

            if (is_string($schemaPath = $this->option('schema-path')) && $schemaPath !== '') {
                if (! is_file($schemaPath)) {
                    throw new RuntimeException("Schema dump not found at {$schemaPath}.");
                }

                $migrationOptions['--schema-path'] = $schemaPath;
            }

            $migrationStatus = $this->call('migrate', $migrationOptions);

            if ($migrationStatus !== self::SUCCESS) {
                throw new RuntimeException('MySQL migrations failed. Check the MySQL container health and credentials.');
            }

            $this->verifyDependencies();

            $this->info('Installing locked npm dependencies...');
            $this->runProcess(
                'npm ci --no-audit --no-fund',
                'Locked npm dependency installation',
                300,
            );

            $this->info('Verifying the preinstalled Playwright browser...');
            $this->runProcess(
                'node docker/playwright-smoke.js',
                'Playwright Chromium verification',
                60,
            );

            $this->info('Done!');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error("Setup failed: {$exception->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Seed the environment file with a set of recommended default values.
     */
    protected function seedEnvDefaults(): void
    {
        $defaults = [
            'DB_HOST' => $this->runtimeEnvironmentValue('DB_HOST', 'mysql'),
            'DB_DATABASE' => $this->runtimeEnvironmentValue('DB_DATABASE', 'sample'),
            'DB_USERNAME' => $this->runtimeEnvironmentValue('DB_USERNAME', 'laravel'),
            'DB_PASSWORD' => $this->runtimeEnvironmentValue('DB_PASSWORD', 'password'),
            'QUEUE_CONNECTION' => 'redis',
            'CACHE_STORE' => 'redis',
            'REDIS_HOST' => 'redis',
            'SHARED_DB_HOST' => $this->runtimeEnvironmentValue('SHARED_DB_HOST', 'mysql'),
            'SHARED_DB_PORT' => '3306',
            'SHARED_DB_DATABASE' => $this->runtimeEnvironmentValue('SHARED_DB_DATABASE', 'sample'),
            'SHARED_DB_USERNAME' => $this->runtimeEnvironmentValue('SHARED_DB_USERNAME', 'laravel'),
            'SHARED_DB_PASSWORD' => $this->runtimeEnvironmentValue('SHARED_DB_PASSWORD', 'password'),
        ];

        foreach ($defaults as $key => $value) {
            $this->setEnvVariable($key, $value);
        }

        $this->reloadEnvConfig();
    }

    protected function runtimeEnvironmentValue(string $key, string $default): string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
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

        $config->set('app.key', $pairs['APP_KEY'] ?? $config->get('app.key'));
        $config->set('cache.default', $pairs['CACHE_STORE'] ?? $config->get('cache.default'));
        $config->set('database.default', $pairs['DB_CONNECTION'] ?? $config->get('database.default'));
        $config->set('queue.default', $pairs['QUEUE_CONNECTION'] ?? $config->get('queue.default'));
        $config->set('session.driver', $pairs['SESSION_DRIVER'] ?? $config->get('session.driver'));

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

        foreach (['default', 'cache'] as $connection) {
            $config->set("database.redis.{$connection}.host", $pairs['REDIS_HOST'] ?? $config->get("database.redis.{$connection}.host"));
            $config->set("database.redis.{$connection}.port", $pairs['REDIS_PORT'] ?? $config->get("database.redis.{$connection}.port"));
        }

        DB::purge('mysql');
        DB::purge('shared');
    }

    /**
     * Ensure an .env file exists by copying the tracked example when needed.
     */
    protected function ensureEnv(): void
    {
        $envFile = base_path('.env');
        $exampleFile = base_path('.env.example');

        if (! file_exists($envFile) && file_exists($exampleFile)) {
            if (! copy($exampleFile, $envFile)) {
                throw new RuntimeException('Unable to create .env from .env.example. Check checkout permissions.');
            }

            $this->info('.env created from .env.example');
        }

        if (! file_exists($envFile)) {
            throw new RuntimeException('.env.example is missing; unable to create the Laravel environment.');
        }
    }

    /**
     * Generate an application key only when the environment does not have one.
     */
    protected function ensureApplicationKey(): void
    {
        $envFile = $this->laravel->environmentFilePath();
        $envContents = file_get_contents($envFile);

        if (! is_string($envContents)) {
            throw new RuntimeException('Unable to read .env while checking APP_KEY.');
        }

        if (preg_match('/^APP_KEY=(.+)$/m', $envContents, $matches) === 1 && trim($matches[1]) !== '') {
            $this->reloadEnvConfig();

            return;
        }

        if (Artisan::call('key:generate', ['--ansi' => true]) !== self::SUCCESS) {
            throw new RuntimeException('Laravel could not generate APP_KEY. Check .env permissions.');
        }

        $this->reloadEnvConfig();
        $this->info('Application key generated.');
    }

    /**
     * Set a given key-value pair in the .env file.
     */
    protected function setEnvVariable(string $key, string $value): void
    {
        $envFile = $this->laravel->environmentFilePath();
        $envContents = file_get_contents($envFile);

        if (! is_string($envContents)) {
            throw new RuntimeException("Unable to read {$envFile} while setting {$key}.");
        }

        $pattern = "/^{$key}=.*/m";
        $replacement = "{$key}={$value}";

        if (preg_match($pattern, $envContents)) {
            $envContents = preg_replace($pattern, $replacement, $envContents);
        } else {
            $envContents .= "\n{$replacement}";
        }

        if (file_put_contents($envFile, $envContents) === false) {
            throw new RuntimeException("Unable to update {$key} in {$envFile}.");
        }
    }

    protected function verifyDependencies(): void
    {
        $migrationTable = config('database.migrations.table', 'migrations');
        $migrationCount = DB::connection('mysql')->table($migrationTable)->count();
        $this->info("MySQL is ready; {$migrationCount} migrations are recorded.");

        $reply = Redis::connection()->command('ping');

        if ($reply !== true && $reply !== 'PONG') {
            throw new RuntimeException('Redis returned an unexpected response to PING.');
        }

        $this->info('Redis is ready; PING returned PONG.');
    }

    protected function runProcess(string $command, string $label, int $timeout): void
    {
        $result = Process::timeout($timeout)->run($command);

        if ($result->successful()) {
            return;
        }

        $details = trim($result->errorOutput());

        if ($details === '') {
            $details = trim($result->output());
        }

        $suffix = $details === '' ? '' : " Output: {$details}";

        throw new RuntimeException("{$label} failed with exit code {$result->exitCode()}.{$suffix}");
    }
}
