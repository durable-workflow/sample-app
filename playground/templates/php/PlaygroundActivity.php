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

    /**
     * @param  array{name: string}  $input
     * @return array{greeting: string, input: array{name: string}, activity_runtime: string}
     */
    #[Activity(Scenario::ACTIVITY_TYPE)]
    public function greet(ActivityContext $context, array $input): array
    {
        $context->heartbeat(['phase' => 'sample_app_playground_activity']);
        $result = $this->config->get('sample-app-playground.activity_result');
        if (! is_array($result)) {
            throw new \RuntimeException('The Sample App playground activity result is not configured.');
        }
        if ($input !== ($result['input'] ?? null)) {
            throw new \RuntimeException('The Sample App playground activity did not receive the authored input.');
        }

        $this->logger->info('sample_app.playground.php_activity_completed', [
            'activity_type' => Scenario::ACTIVITY_TYPE,
            'task_id' => $context->taskId,
        ]);

        return [
            'greeting' => (string) ($result['greeting'] ?? ''),
            'input' => $input,
            'activity_runtime' => (string) ($result['activity_runtime'] ?? ''),
        ];
    }
}
