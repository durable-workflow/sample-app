<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workflows\Playwright\CheckConsoleErrorsWorkflow;
use Illuminate\Console\Command;
use Workflow\V2\WorkflowStub;

class Playwright extends Command
{
    protected $signature = 'app:playwright {url=https://example.com : URL to inspect with Playwright}';

    protected $description = 'Runs a playwright workflow';

    public function handle(): int
    {
        $url = (string) $this->argument('url');

        $workflow = WorkflowStub::make(CheckConsoleErrorsWorkflow::class);
        $workflow->start($url);
        while ($workflow->refresh()->running()) {
            usleep(100_000);
        }

        if ($workflow->failed()) {
            $this->error('Playwright workflow failed.');

            return self::FAILURE;
        }

        $output = $workflow->output();
        $errors = $output['errors'] ?? [];

        if (is_array($errors) && $errors !== []) {
            $this->error('Playwright captured console errors:');
            foreach ($errors as $error) {
                $this->line('- '.(string) $error);
            }

            return self::FAILURE;
        }

        $mp4 = (string) ($output['mp4'] ?? '');

        if ($mp4 === '' || ! is_file($mp4)) {
            $this->error('Playwright did not produce an MP4 artifact.');

            return self::FAILURE;
        }

        $this->info($mp4);

        return self::SUCCESS;
    }
}
