<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SampleTeachingMaterialTest extends TestCase
{
    public function test_readme_contains_replay_safety_do_and_do_not_examples(): void
    {
        $readme = file_get_contents(__DIR__.'/../../README.md');

        $this->assertIsString($readme);
        $this->assertStringContainsString('#### Replay-Safety Teaching Notes', $readme);
        $this->assertStringContainsString('$startedAt = sideEffect(fn () => now());', $readme);
        $this->assertStringContainsString('$startedAt = now();', $readme);
        $this->assertStringContainsString('replay can run the method again later', $readme);
    }

    public function test_workflow_entry_points_include_teaching_preambles(): void
    {
        $expectations = [
            'app/Workflows/Simple/SimpleWorkflow.php' => 'Smallest v2 shape',
            'app/Workflows/Elapsed/ElapsedTimeWorkflow.php' => 'Clock reads are non-deterministic',
            'app/Workflows/Microservice/MicroserviceWorkflow.php' => 'shares the queue/database contract',
            'app/Workflows/Playwright/CheckConsoleErrorsWorkflow.php' => 'Browser and FFmpeg work belongs in activities',
            'app/Workflows/Webhooks/WebhookWorkflow.php' => 'v2 signals are pull-style',
            'app/Workflows/Prism/PrismWorkflow.php' => 'workflow loop is replay-safe',
            'app/Workflows/Ai/AiWorkflow.php' => 'durable agent pattern',
        ];

        foreach ($expectations as $path => $needle) {
            $contents = file_get_contents(__DIR__.'/../../'.$path);

            $this->assertIsString($contents);
            $this->assertStringContainsString($needle, $contents, "{$path} is missing its teaching preamble.");
        }
    }
}
