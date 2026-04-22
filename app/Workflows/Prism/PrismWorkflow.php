<?php

declare(strict_types=1);

namespace App\Workflows\Prism;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;

class PrismWorkflow extends Workflow
{
    public function handle(): array
    {
        // Keep model calls and validation side effects in activities; the
        // workflow loop is replay-safe because it only branches on recorded results.
        do {
            $user = activity(GenerateUserActivity::class);
            $valid = activity(ValidateUserActivity::class, $user);
        } while (! $valid);

        return $user;
    }
}
