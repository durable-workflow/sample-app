from __future__ import annotations

import asyncio
import json
import os
from typing import Any

from durable_workflow import Client, Worker, activity, workflow


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before starting the worker.")
    return value


SCENARIO: dict[str, Any] = json.loads(required("SAMPLE_APP_PLAYGROUND_SCENARIO"))


@activity.defn(name=SCENARIO["activity_type"])
def greet() -> dict[str, str]:
    expected = SCENARIO["expected_result"]
    return {
        "greeting": expected["greeting"],
        "activity_runtime": "python",
    }


@workflow.defn(name=SCENARIO["workflow_type"])
class PlaygroundWorkflow:
    def run(self, context):
        activity_result = yield context.schedule_activity(SCENARIO["activity_type"], [])
        return {**activity_result, "workflow_runtime": "python"}


async def main() -> None:
    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        worker_token=required("DURABLE_WORKFLOW_WORKER_TOKEN"),
    ) as client:
        worker = Worker(
            client,
            task_queue=required("DURABLE_WORKFLOW_TASK_QUEUE"),
            worker_id=required("DURABLE_WORKFLOW_WORKER_ID"),
            workflows=[PlaygroundWorkflow],
            activities=[greet],
        )
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
