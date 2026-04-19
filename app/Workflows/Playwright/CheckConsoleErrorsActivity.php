<?php

declare(strict_types=1);

namespace App\Workflows\Playwright;

use Illuminate\Support\Facades\Process;
use Workflow\V2\Activity;

class CheckConsoleErrorsActivity extends Activity
{
    public function handle(string $url): array
    {
        $result = Process::run([
            'node', base_path('playwright-script.js'), $url,
        ])->throw();

        return json_decode($result->output(), true);
    }
}
