from __future__ import annotations

import asyncio
import os

from durable_workflow import Client, Worker, activity


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
            workflows=[],
            activities=[undo_first, undo_second],
        )
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
