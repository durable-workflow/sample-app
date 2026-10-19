<?php

declare(strict_types=1);

use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;

function originalTimerApplication(WorkflowContext $context, bool $concurrent): array
{
    $version = $context->getVersion('sample-timer-application', 1, 1);

    return [$version, $concurrent ? [60, 30, 45] : [30]];
}

function upgradedTimerApplication(WorkflowContext $context, bool $concurrent): array
{
    $version = $context->getVersion('sample-timer-application', 1, 2);
    $delays = $concurrent ? [60, 30, 45] : [30];
    if ($version === 2) {
        $delays = array_map(static fn (int $delay): int => $delay + 15, $delays);
    }

    return [$version, $delays];
}

function registerTimerUpgradeApplications(Worker $worker, string $revision): void
{
    $application = match ($revision) {
        'v1' => originalTimerApplication(...),
        'v2' => upgradedTimerApplication(...),
        default => throw new InvalidArgumentException('Unknown timer application revision.'),
    };
    foreach ([false, true] as $concurrent) {
        $type = 'polyglot.php.upgrade-'.($concurrent ? 'concurrent-timers' : 'timer');
        $worker->registerWorkflow($type, static function (WorkflowContext $context, string $request) use ($application, $revision, $concurrent): array {
            [$version, $delays] = $application($context, $concurrent);
            if ($concurrent) {
                $context->all(array_map($context->deferTimer(...), $delays));
            } else {
                $context->sleep($delays[0]);
            }

            return ['workflow_runtime' => 'php', 'request' => $request, 'application_revision' => $revision,
                'version' => $version, 'timer_seconds' => $concurrent ? $delays : $delays[0]];
        });
    }
}
