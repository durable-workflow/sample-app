<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

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

        $this->info('Updating README.md with Codespace URL...');
        $this->updateReadme();

        $this->info('Installing npm dependencies...');
        Process::run('npm install');

        $this->info('Installing Playwright components...');
        $this->installPlaywright();

        $this->info('Running migrations...');
        Artisan::call('migrate');

        $this->info('Done!');
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
        }

        // If .env exists but APP_KEY is empty or missing, run key:generate
        $envContents = file_exists($envFile) ? file_get_contents($envFile) : '';
        $hasKey = preg_match('/^APP_KEY=(.+)$/m', $envContents, $matches) && !empty(trim($matches[1]));

        if (! $hasKey) {
            Artisan::call('key:generate', ['--ansi' => true]);
            $this->info('Application key generated.');
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
            $this->error('Missing required GitHub Codespaces environment variables.');
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
            $this->error('Missing required GitHub Codespaces environment variables.');
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
