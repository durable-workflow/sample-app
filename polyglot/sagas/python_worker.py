from __future__ import annotations

import asyncio
import json
import os
import socket
from urllib.request import Request, urlopen

from durable_workflow import ActivityFailed, Client, Worker, activity, workflow


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before starting the worker.")
    return value


@activity.defn(name="sample-app.saga.python.undo-first")
async def undo_first(marker: str) -> dict[str, str]:
    return {"step": "first", "marker": marker, "runtime": "python"}


@activity.defn(name="sample-app.saga.python.undo-second")
async def undo_second(marker: str, recover_effect: bool = False) -> dict[str, str]:
    if marker.startswith("saga-fail-compensation-"):
        raise RuntimeError("planned undo-second compensation failure")
    if recover_effect:
        info = activity.context().info
        request = Request(
            required("ACTIVITY_EFFECTS_URL") + "/effects",
            data=json.dumps({
                "operation_key": marker + ":undo-second",
                "attempt_id": info.activity_attempt_id,
                "input": {"operation": "undo-second", "marker": marker},
            }).encode(),
            headers={"Content-Type": "application/json"},
        )

        def apply_effect():
            with urlopen(request, timeout=5) as response:
                return json.load(response)

        receipt = await asyncio.to_thread(apply_effect)
        if receipt["reused"] is not (info.attempt_number == 2):
            raise RuntimeError("Unexpected downstream compensation receipt.")
        if info.attempt_number == 1:
            await asyncio.sleep(45)
            raise RuntimeError("Fixture did not stop the first compensation worker.")
    return {"step": "second", "marker": marker, "runtime": "python"}


class SagaWorkflow:
    compensation_runtime: str
    wait_for_restart = False

    def __init__(self) -> None:
        self.released = False

    def run(self, context, marker: str, scenario: str = ""):
        saga = context.saga()
        try:
            for step in ("first", "second"):
                yield context.schedule_activity(
                    f"sample-app.saga.reserve-{step}", [marker]
                )
                recover_step = scenario == "recover-effect" and step == "second"
                options = {"retry_policy": {"max_attempts": 1}}
                if recover_step:
                    options = {
                        "start_to_close_timeout": 20,
                        "retry_policy": {"max_attempts": 2, "backoff_seconds": [1]},
                    }
                saga.add_compensation(
                    f"sample-app.saga.{self.compensation_runtime}.undo-{step}",
                    [marker, True] if recover_step else [marker],
                    **options,
                )
                if self.wait_for_restart and step == "first":
                    yield context.wait_condition(lambda: self.released, key="saga-restart-continue")

            yield context.schedule_activity(
                "sample-app.saga.decline", [], retry_policy={"max_attempts": 1}
            )
            return {"unexpected_success": True}
        except ActivityFailed as failure:
            yield from saga.compensate(failure)
            return {
                "status": "compensated",
                "workflow_runtime": "python",
                "compensation_runtime": self.compensation_runtime,
                "marker": marker,
                "initiating_failure": str(failure),
            }


class SagaRestartWorkflow(SagaWorkflow):
    wait_for_restart = True

    @workflow.signal("sample-app.saga.restart-continue")
    def resume(self) -> None:
        self.released = True


@workflow.defn(name="sample-app.saga.python.compensate-php")
class PhpCompensation(SagaWorkflow):
    compensation_runtime = "php"


@workflow.defn(name="sample-app.saga.python.compensate-python")
class PythonCompensation(SagaWorkflow):
    compensation_runtime = "python"


@workflow.defn(name="sample-app.saga.python.compensate-rust")
class RustCompensation(SagaWorkflow):
    compensation_runtime = "rust"


@workflow.defn(name="sample-app.saga.python.restart-compensate-php")
class PhpCompensationAfterRestart(SagaRestartWorkflow):
    compensation_runtime = "php"


@workflow.defn(name="sample-app.saga.python.restart-compensate-python")
class PythonCompensationAfterRestart(SagaRestartWorkflow):
    compensation_runtime = "python"


@workflow.defn(name="sample-app.saga.python.restart-compensate-rust")
class RustCompensationAfterRestart(SagaRestartWorkflow):
    compensation_runtime = "rust"


async def main() -> None:
    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        worker_token=required("DURABLE_WORKFLOW_WORKER_TOKEN"),
    ) as client:
        worker = Worker(
            client,
            task_queue=required("DURABLE_WORKFLOW_TASK_QUEUE"),
            worker_id=f"sample-saga-python-{socket.gethostname()}-{os.getpid()}",
            workflows=[
                PhpCompensation, PythonCompensation, RustCompensation,
                PhpCompensationAfterRestart, PythonCompensationAfterRestart, RustCompensationAfterRestart,
            ],
            activities=[undo_first, undo_second],
        )
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
