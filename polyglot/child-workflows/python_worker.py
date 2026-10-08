from __future__ import annotations

import asyncio
import os
import sys
from pathlib import Path

from durable_workflow import Client, Worker

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "python_workflow"))
from child_workflows import CHILD_WORKFLOWS


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before starting the worker.")
    return value


async def main() -> None:
    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        worker_token=required("DURABLE_WORKFLOW_WORKER_TOKEN"),
    ) as client:
        worker = Worker(
            client,
            task_queue=required("DURABLE_WORKFLOW_TASK_QUEUE"),
            worker_id=f"sample-child-matrix-python-{os.getpid()}",
            workflows=list(CHILD_WORKFLOWS),
            activities=[],
        )
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
