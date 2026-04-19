<?php

declare(strict_types=1);

namespace App\Workflows\Playwright;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;

class CheckConsoleErrorsWorkflow extends Workflow
{
    public function handle(string $url): array
    {
        $result = activity(CheckConsoleErrorsActivity::class, $url);

        $mp4 = activity(ConvertVideoActivity::class, $result['video']);

        return [
            'errors' => $result['errors'],
            'mp4' => $mp4,
        ];
    }
}
