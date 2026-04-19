<?php

declare(strict_types=1);

namespace App\Workflows\Prism;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Workflow\V2\Activity;

class GenerateUserActivity extends Activity
{
    public function handle(): array
    {
        $schema = new ObjectSchema(
            name: 'user',
            description: 'A user profile with their hobbies',
            properties: [
                new StringSchema('name', 'The user\'s full name'),
                new ArraySchema(
                    name: 'hobbies',
                    description: 'The user\'s list of hobbies',
                    items: new ObjectSchema(
                        name: 'hobby',
                        description: 'A detailed hobby entry',
                        properties: [
                            new StringSchema('name', 'The name of the hobby'),
                            new StringSchema('description', 'A brief description of the hobby'),
                        ],
                        requiredFields: ['name', 'description']
                    )
                ),
            ],
            requiredFields: ['name', 'hobbies']
        );

        $response = Prism::structured()
            ->using(Provider::OpenAI, 'gpt-4o')
            ->withSchema($schema)
            ->withPrompt('Use names from many languages and vary first initials.')
            ->asStructured();

        return $response->structured;
    }
}
