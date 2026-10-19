"""Two authored application revisions, selected before a worker starts."""

from durable_workflow import workflow


def original_application(ctx, concurrent):
    version = yield ctx.get_version("sample-timer-application", 1, 1)
    return version, [60, 30, 45] if concurrent else [30]


def upgraded_application(ctx, concurrent):
    version = yield ctx.get_version("sample-timer-application", 1, 2)
    delays = [60, 30, 45] if concurrent else [30]
    return version, [delay + 15 for delay in delays] if version == 2 else delays


def execute(ctx, request, application, revision, concurrent):
    version, delays = yield from application(ctx, concurrent)
    if concurrent:
        yield [ctx.sleep(delay) for delay in delays]
    else:
        yield ctx.sleep(delays[0])
    return {"workflow_runtime": "python", "request": request, "application_revision": revision,
            "version": version, "timer_seconds": delays if concurrent else delays[0]}


@workflow.defn(name="polyglot.python.upgrade-timer")
class OriginalSingleTimer:
    def run(self, ctx, request):
        return (yield from execute(ctx, request, original_application, "v1", False))


@workflow.defn(name="polyglot.python.upgrade-timer")
class UpgradedSingleTimer:
    def run(self, ctx, request):
        return (yield from execute(ctx, request, upgraded_application, "v2", False))


@workflow.defn(name="polyglot.python.upgrade-concurrent-timers")
class OriginalConcurrentTimers:
    def run(self, ctx, request):
        return (yield from execute(ctx, request, original_application, "v1", True))


@workflow.defn(name="polyglot.python.upgrade-concurrent-timers")
class UpgradedConcurrentTimers:
    def run(self, ctx, request):
        return (yield from execute(ctx, request, upgraded_application, "v2", True))


def applications(revision):
    if revision == "v1":
        return [OriginalSingleTimer, OriginalConcurrentTimers]
    if revision == "v2":
        return [UpgradedSingleTimer, UpgradedConcurrentTimers]
    raise ValueError("Unknown timer application revision.")
