<?php

declare(strict_types=1);

namespace App\Workflows\Prism;

use Workflow\V2\Activity;

class ValidateUserActivity extends Activity
{
    public function handle(array $user): bool
    {
        if (empty($user['name']) || ! is_array($user['hobbies']) || count($user['hobbies']) === 0) {
            return false;
        }

        foreach ($user['hobbies'] as $hobby) {
            if (empty($hobby['name']) || empty($hobby['description'])) {
                return false;
            }
        }

        // Extra Validation: The user's name must start with a vowel.
        if (! in_array(strtoupper($user['name'][0]), ['A', 'E', 'I', 'O', 'U'], true)) {
            return false;
        }

        return true;
    }
}
