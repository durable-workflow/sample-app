from __future__ import annotations

import asyncio
import os

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
async def undo_second(marker: str) -> dict[str, str]:
    return {"step": "second", "marker": marker, "runtime": "python"}


@workflow.defn(name="sample-app.saga.python.compensate-rust")
class PythonSagaWorkflow:
    def run(self, context, marker: str):
        saga = context.saga()
        try:
            for step in ("first", "second"):
                yield context.schedule_activity(
                    f"sample-app.saga.reserve-{step}", [marker]
                )
                saga.add_compensation(f"sample-app.saga.rust.undo-{step}", [marker])

            yield context.schedule_activity(
                "sample-app.saga.decline", [], retry_policy={"max_attempts": 1}
            )
            return {"unexpected_success": True}
        except ActivityFailed as failure:
            yield from saga.compensate(failure)
            return {
                "status": "compensated",
                "workflow_runtime": "python",
                "compensation_runtime": "rust",
                "marker": marker,
                "initiating_failure": str(failure),
            }


@workflow.defn(name="sample-app.saga.python.restart-compensate-rust")
class PythonSagaRestartWorkflow:
    def __init__(self) -> None:
        self.released = False

    @workflow.signal("sample-app.saga.restart-continue")
    def resume(self) -> None:
        self.released = True

    def run(self, context, marker: str):
        saga = context.saga()
        try:
            yield context.schedule_activity("sample-app.saga.reserve-first", [marker])
            saga.add_compensation("sample-app.saga.rust.undo-first", [marker])
            yield context.wait_condition(lambda: self.released, key="saga-restart-continue")
            yield context.schedule_activity("sample-app.saga.reserve-second", [marker])
            saga.add_compensation("sample-app.saga.rust.undo-second", [marker])
            yield context.schedule_activity(
                "sample-app.saga.decline", [], retry_policy={"max_attempts": 1}
            )
            return {"unexpected_success": True}
        except ActivityFailed as failure:
            yield from saga.compensate(failure)
            return {
                "status": "compensated",
                "workflow_runtime": "python",
                "compensation_runtime": "rust",
                "marker": marker,
                "initiating_failure": str(failure),
            }


async def main() -> None:
    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        worker_token=required("DURABLE_WORKFLOW_WORKER_TOKEN"),
    ) as client:
        worker = Worker(
            client,
            task_queue=required("DURABLE_WORKFLOW_TASK_QUEUE"),
            worker_id=f"sample-saga-python-{os.getpid()}",
            workflows=[PythonSagaWorkflow, PythonSagaRestartWorkflow],
            activities=[undo_first, undo_second],
        )
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
