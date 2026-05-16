"""Polyglot smoke: Python-authored workflow scheduling PHP activities.

The workflow is registered by the long-running ``python-workflow-worker``
container, while its ``polyglot.python-to-php.*`` activities are
registered by the distinct ``php-activity-worker`` service. This proves
the reverse cross-language activity path from the PHP-to-Python scenario.
"""
from __future__ import annotations

import asyncio
import json
import os
import sys
import time
import uuid
from typing import Any

from durable_workflow import Client


WORKFLOW_TYPE = "polyglot.python-to-php.greeter"


async def wait_for_worker_runtime(
    client: Client,
    *,
    task_queue: str,
    runtime: str,
    service_name: str,
    timeout_seconds: float,
) -> None:
    """Block until a worker with the expected runtime is registered."""
    deadline = time.monotonic() + timeout_seconds
    last_error: Exception | None = None

    while time.monotonic() < deadline:
        try:
            roster = await client.list_workers(task_queue=task_queue)
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            await asyncio.sleep(1.0)
            continue

        for worker in getattr(roster, "workers", []) or []:
            if getattr(worker, "runtime", None) == runtime:
                return

        await asyncio.sleep(1.0)

    detail = f": {last_error}" if last_error is not None else ""
    raise RuntimeError(
        f"smoke: no {runtime} worker registered on task queue {task_queue!r} within "
        f"{timeout_seconds:.0f}s. The polyglot stack needs the {service_name} "
        f"service to be running for this scenario{detail}."
    )


async def run_scenario() -> dict[str, Any]:
    server_url = os.environ["DURABLE_WORKFLOW_SERVER_URL"]
    token = os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN", "test-token")
    namespace = os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default")
    workflow_task_queue = os.environ.get("POLYGLOT_PY_TASK_QUEUE", "polyglot-python")
    php_activity_task_queue = os.environ.get("POLYGLOT_PY2PHP_TASK_QUEUE", "polyglot-python-to-php")

    request: dict[str, Any] = {"name": "Grace", "locale": "en"}
    workflow_id = f"polyglot-py2php-{uuid.uuid4().hex[:8]}"

    async with Client(server_url, token=token, namespace=namespace) as client:
        await wait_for_worker_runtime(
            client,
            task_queue=workflow_task_queue,
            runtime="python",
            service_name="python-workflow-worker",
            timeout_seconds=60.0,
        )
        await wait_for_worker_runtime(
            client,
            task_queue=php_activity_task_queue,
            runtime="php",
            service_name="php-activity-worker",
            timeout_seconds=60.0,
        )

        handle = await client.start_workflow(
            workflow_type=WORKFLOW_TYPE,
            workflow_id=workflow_id,
            task_queue=workflow_task_queue,
            input=[request],
            memo={"sample": "polyglot.python_to_php", "smoke": True},
        )

        result = await handle.result(timeout=240.0, poll_interval=0.5)

    if not isinstance(result, dict):
        raise RuntimeError(f"smoke: expected dict result, got {type(result).__name__}")
    if result.get("workflow_runtime") != "python":
        raise RuntimeError(f"smoke: workflow_runtime != python: {result}")
    if result.get("activity_runtime") != "php":
        raise RuntimeError(f"smoke: activity_runtime != php: {result}")
    if result.get("request") != request:
        raise RuntimeError(f"smoke: request not echoed: {result}")

    marker = result.get("php_marker")
    if not isinstance(marker, dict) or marker.get("runtime") != "php":
        raise RuntimeError(f"smoke: marker activity did not run on php: {result}")
    if marker.get("marker") != "php-activity-worker":
        raise RuntimeError(f"smoke: marker did not identify the php activity worker: {marker}")
    if marker.get("name") != request["name"]:
        raise RuntimeError(f"smoke: marker.name mismatch: {marker}")

    description = result.get("php_description")
    if not isinstance(description, dict) or description.get("runtime") != "php":
        raise RuntimeError(f"smoke: describe activity did not run on php: {result}")
    if description.get("marker_runtime") != "php":
        raise RuntimeError(f"smoke: describe activity lost php marker runtime: {description}")

    return {
        "scenario": "python_to_php",
        "workflow_id": workflow_id,
        "workflow_type": WORKFLOW_TYPE,
        "status": "passed",
        "result": result,
    }


async def run() -> int:
    try:
        scenario = await run_scenario()
    except Exception as exc:  # noqa: BLE001
        print(str(exc), file=sys.stderr)
        return 1

    print(json.dumps(scenario, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(asyncio.run(run()))
