<?php

declare(strict_types=1);

namespace App\Workflows\ServiceMode;

use DurableWorkflow\Attribute\Activity;
use DurableWorkflow\Worker\ActivityContext;
use Psr\Log\LoggerInterface;

final class PrepareWelcomeActivity
{
    public const TYPE = 'sample.service-mode.php.prepare';

    public function __construct(private readonly LoggerInterface $logger) {}

    /** @return array{greeting: string, name: string, runtime: string} */
    #[Activity(self::TYPE)]
    public function prepare(ActivityContext $context, string $name): array
    {
        $context->heartbeat(['phase' => 'preparing_welcome']);
        $this->logger->info('service_mode.php_activity_completed', [
            'activity_type' => self::TYPE,
            'task_id' => $context->taskId,
        ]);

        return [
            'greeting' => "Hello, {$name}",
            'name' => $name,
            'runtime' => 'php',
        ];
    }
}
