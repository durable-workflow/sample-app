"""Two authored application revisions, selected before a worker starts."""

from durable_workflow import workflow

current_application = None
current_revision = None
current_introduced_application = None


def original_application(ctx, concurrent):
    version = yield ctx.get_version("sample-timer-application", 1, 1)
    return version, [60, 30, 45] if concurrent else [30]


def upgraded_application(ctx, concurrent):
    version = yield ctx.get_version("sample-timer-application", 1, 2)
    delays = [60, 30, 45] if concurrent else [30]
    return version, [delay + 15 for delay in delays] if version == 2 else delays


def unversioned_application(ctx, concurrent):
    # Keep the generator helper interface without issuing a version command.
    yield from ()
    return -1, [60, 30, 45] if concurrent else [30]


def introduced_application(ctx, concurrent):
    version = yield ctx.get_version("sample-timer-application", -1, 2)
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


@workflow.defn(name="polyglot.python.class-upgrade-timer")
class OriginalSingleTimer:
    def run(self, ctx, request):
        return (yield from execute(ctx, request, original_application, "v1", False))


@workflow.defn(name="polyglot.python.class-upgrade-timer")
class UpgradedSingleTimer:
    def run(self, ctx, request):
        return (yield from execute(ctx, request, upgraded_application, "v2", False))


@workflow.defn(name="polyglot.python.class-upgrade-concurrent-timers")
class OriginalConcurrentTimers:
    def run(self, ctx, request):
        return (yield from execute(ctx, request, original_application, "v1", True))


@workflow.defn(name="polyglot.python.class-upgrade-concurrent-timers")
class UpgradedConcurrentTimers:
    def run(self, ctx, request):
        return (yield from execute(ctx, request, upgraded_application, "v2", True))


@workflow.defn(name="polyglot.python.upgrade-timer")
class HelperSingleTimer:
    def run(self, ctx, request):
        return (yield from execute(ctx, request, current_application, current_revision, False))


@workflow.defn(name="polyglot.python.upgrade-concurrent-timers")
class HelperConcurrentTimers:
    def run(self, ctx, request):
        return (yield from execute(ctx, request, current_application, current_revision, True))


@workflow.defn(name="polyglot.python.unversioned-upgrade-timer")
class IntroductionSingleTimer:
    def run(self, ctx, request):
        return (yield from execute(ctx, request, current_introduced_application, current_revision, False))


@workflow.defn(name="polyglot.python.unversioned-upgrade-concurrent-timers")
class IntroductionConcurrentTimers:
    def run(self, ctx, request):
        return (yield from execute(ctx, request, current_introduced_application, current_revision, True))


def applications(revision):
    # Select business helpers before Worker construction; registered wrapper classes stay identical.
    global current_application, current_revision, current_introduced_application
    if revision == "v1":
        current_application = original_application
        current_introduced_application = unversioned_application
        classes = [OriginalSingleTimer, OriginalConcurrentTimers]
    elif revision == "v2":
        current_application = upgraded_application
        current_introduced_application = introduced_application
        classes = [UpgradedSingleTimer, UpgradedConcurrentTimers]
    else:
        raise ValueError("Unknown timer application revision.")
    current_revision = revision
    return [*classes, HelperSingleTimer, HelperConcurrentTimers, IntroductionSingleTimer, IntroductionConcurrentTimers]
