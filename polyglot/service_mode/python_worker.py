"""Python activity worker for the evaluator-facing service-mode quickstart."""

from __future__ import annotations

import asyncio
import logging
import os
from typing import Any

from durable_workflow import Client, Worker, activity


TASK_QUEUE = os.environ.get("DURABLE_WORKFLOW_TASK_QUEUE", "sample-service-python")


@activity.defn(name="sample.service-mode.python.decorate")
def decorate_welcome(prepared: dict[str, Any]) -> dict[str, Any]:
    name = str(prepared.get("name", "Codespace"))
    return {
        "message": f"{prepared.get('greeting', f'Hello, {name}')} — Python joined the workflow.",
        "name": name,
        "php_runtime": prepared.get("runtime"),
        "runtime": "python",
    }


async def main() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(name)s %(levelname)s %(message)s",
    )
    async with Client(
        os.environ.get("DURABLE_WORKFLOW_ENDPOINT", "http://server:8080"),
        token=os.environ.get("DURABLE_WORKFLOW_TOKEN", "test-token"),
        namespace=os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default"),
    ) as client:
        worker = Worker(
            client,
            task_queue=TASK_QUEUE,
            workflows=[],
            activities=[decorate_welcome],
        )
        logging.getLogger("service_mode.python").info(
            "Python activity worker ready on task queue %s", TASK_QUEUE
        )
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
