<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows\ServiceMode;

use App\Workflows\ServiceMode\PrepareWelcomeActivity;
use App\Workflows\ServiceMode\WelcomeWorkflow;
use DurableWorkflow\Bridge\Laravel\WorkerFactory;
use DurableWorkflow\Codec\AvroPayloadCodec;
use DurableWorkflow\Testing\WorkerTestHarness;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class WelcomeWorkflowTest extends TestCase
{
    public function test_laravel_bridge_resolves_handlers_and_routes_the_python_activity(): void
    {
        $this->app->instance(LoggerInterface::class, new NullLogger);
        $worker = $this->app->make(WorkerFactory::class)->make(WelcomeWorkflow::PHP_TASK_QUEUE);
        $harness = new WorkerTestHarness($worker);
        $codec = new AvroPayloadCodec;

        $this->assertSame([WelcomeWorkflow::TYPE], $worker->contracts()['workflows']);
        $this->assertSame([PrepareWelcomeActivity::TYPE], $worker->contracts()['activities']);
        $harness->assertActivityResult(PrepareWelcomeActivity::TYPE, [
            'greeting' => 'Hello, Ada',
            'name' => 'Ada',
            'runtime' => 'php',
        ], ['Ada']);

        $first = $harness->runWorkflow(WelcomeWorkflow::TYPE, ['Ada']);
        $this->assertSame(PrepareWelcomeActivity::TYPE, $first->commands[0]['activity_type'] ?? null);
        $this->assertSame(WelcomeWorkflow::PHP_TASK_QUEUE, $first->commands[0]['queue'] ?? null);

        $prepared = ['greeting' => 'Hello, Ada', 'name' => 'Ada', 'runtime' => 'php'];
        $afterPhp = $harness->runWorkflow(WelcomeWorkflow::TYPE, ['Ada'], [
            [
                'event_type' => 'ActivityScheduled',
                'payload' => ['sequence' => 1, 'activity_type' => PrepareWelcomeActivity::TYPE],
            ],
            [
                'event_type' => 'ActivityCompleted',
                'payload' => ['sequence' => 1, 'result' => $codec->envelope($prepared)],
            ],
        ]);

        $this->assertSame('sample.service-mode.python.decorate', $afterPhp->commands[0]['activity_type'] ?? null);
        $this->assertSame(WelcomeWorkflow::PYTHON_TASK_QUEUE, $afterPhp->commands[0]['queue'] ?? null);

        $decorated = [
            'message' => 'Hello, Ada — Python joined the workflow.',
            'name' => 'Ada',
            'php_runtime' => 'php',
            'runtime' => 'python',
        ];
        $completed = $harness->runWorkflow(WelcomeWorkflow::TYPE, ['Ada'], [
            [
                'event_type' => 'ActivityScheduled',
                'payload' => ['sequence' => 1, 'activity_type' => PrepareWelcomeActivity::TYPE],
            ],
            [
                'event_type' => 'ActivityCompleted',
                'payload' => ['sequence' => 1, 'result' => $codec->envelope($prepared)],
            ],
            [
                'event_type' => 'ActivityScheduled',
                'payload' => ['sequence' => 2, 'activity_type' => 'sample.service-mode.python.decorate'],
            ],
            [
                'event_type' => 'ActivityCompleted',
                'payload' => ['sequence' => 2, 'result' => $codec->envelope($decorated)],
            ],
        ]);

        $this->assertSame('complete_workflow', $completed->commands[0]['type'] ?? null);
        $this->assertSame([
            'message' => $decorated['message'],
            'php_activity' => $prepared,
            'python_activity' => $decorated,
        ], $codec->decodeEnvelope($completed->commands[0]['result']));
    }
}
