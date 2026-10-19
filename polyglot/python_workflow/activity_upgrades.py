"""A stable registered wrapper with replaceable versioned business logic."""

import asyncio
import importlib.metadata
import os
import socket

from durable_workflow import Client, Worker, activity, workflow

REVISION = None


def original_application(context):
    return (yield context.get_version("sample-activity-application", 1, 1))


def upgraded_application(context):
    return (yield context.get_version("sample-activity-application", 1, 2))


APPLICATION = None


@workflow.defn(name="polyglot.python.activity-upgrade")
class ActivityUpgrade:
    def run(self, context, request):
        version = yield from APPLICATION(context)
        result = yield context.schedule_activity(
            f"polyglot.{request['activity_runtime']}.upgrade-activity",
            [{"case_id": request["case_id"], "version": version, "value": request["value"]}],
            queue=f"polyglot-{request['activity_runtime']}",
            retry_policy=workflow.ActivityRetryPolicy(max_attempts=version + 1,
                                                     backoff_seconds=[1] if version == 1 else [1, 2]),
            start_to_close_timeout=30,
            schedule_to_close_timeout=120 if version == 1 else 180,
        )
        return {"workflow_runtime": "python", "application_revision": REVISION,
                "version": version, "activity": result}


@activity.defn(name="polyglot.python.upgrade-activity")
def upgraded_activity(request):
    return {"activity_runtime": "python", "application_revision": REVISION,
            "sdk_version": "durable-workflow-python/" + importlib.metadata.version("durable-workflow"),
            "attempt_number": activity.context().info.attempt_number, "request": request}


async def main():
    global REVISION, APPLICATION
    REVISION = os.environ["POLYGLOT_TIMER_APPLICATION"]
    if REVISION not in {"v1", "v2"}:
        raise ValueError("Select activity application v1 or v2.")
    APPLICATION = original_application if REVISION == "v1" else upgraded_application
    async with Client(os.environ["DURABLE_WORKFLOW_SERVER_URL"],
                      token=os.environ["DURABLE_WORKFLOW_AUTH_TOKEN"], namespace="default") as client:
        worker = Worker(client, task_queue="polyglot-python", workflows=[ActivityUpgrade],
                        activities=[] if REVISION == "v1" else [upgraded_activity],
                        worker_id="activity-upgrade-python-" + socket.gethostname(), poll_timeout=2,
                        shutdown_timeout=10)
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
