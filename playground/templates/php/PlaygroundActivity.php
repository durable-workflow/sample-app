<?php

declare(strict_types=1);

namespace SampleAppPlayground;

use DurableWorkflow\Attribute\Activity;
use DurableWorkflow\Worker\ActivityContext;
use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;

final class PlaygroundActivity
{
    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array{greeting: string, activity_runtime: string} */
    #[Activity(Scenario::ACTIVITY_TYPE)]
    public function greet(ActivityContext $context): array
    {
        $context->heartbeat(['phase' => 'sample_app_playground_activity']);
        $result = $this->config->get('sample-app-playground.activity_result');
        if (! is_array($result)) {
            throw new \RuntimeException('The Sample App playground activity result is not configured.');
        }

        $this->logger->info('sample_app.playground.php_activity_completed', [
            'activity_type' => Scenario::ACTIVITY_TYPE,
            'task_id' => $context->taskId,
        ]);

        return [
            'greeting' => (string) ($result['greeting'] ?? ''),
            'activity_runtime' => (string) ($result['activity_runtime'] ?? ''),
        ];
    }
}
