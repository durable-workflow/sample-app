"""Polyglot smoke: PHP-authored workflow scheduling PHP activities.

This is the PHP same-language sanity check that pairs with
``python_workflow_smoke.py``. The workflow is registered by a PHP
workflow worker and its activities are registered by a separate PHP
activity worker on the same task queue, so the server still has to route
workflow and activity tasks by registered capability rather than by
runtime alone.
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


WORKFLOW_TYPE = "polyglot.php.greeter"
MARKER_ACTIVITY = "polyglot.php.marker"
DESCRIBE_ACTIVITY = "polyglot.php.describe"


async def wait_for_php_worker_capabilities(
    client: Client,
    *,
    task_queue: str,
    timeout_seconds: float,
) -> None:
    """Block until PHP workers for the workflow and activity types exist."""
    deadline = time.monotonic() + timeout_seconds
    last_error: Exception | None = None

    while time.monotonic() < deadline:
        try:
            roster = await client.list_workers(task_queue=task_queue)
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            await asyncio.sleep(1.0)
            continue

        has_workflow = False
        has_activities = False
        for worker in getattr(roster, "workers", []) or []:
            if getattr(worker, "runtime", None) != "php":
                continue

            workflow_types = getattr(worker, "supported_workflow_types", None) or []
            activity_types = getattr(worker, "supported_activity_types", None) or []
            has_workflow = has_workflow or WORKFLOW_TYPE in workflow_types
            has_activities = has_activities or (
                MARKER_ACTIVITY in activity_types and DESCRIBE_ACTIVITY in activity_types
            )

        if has_workflow and has_activities:
            return

        await asyncio.sleep(1.0)

    detail = f": {last_error}" if last_error is not None else ""
    raise RuntimeError(
        f"smoke: PHP workflow/activity workers were not both registered on "
        f"task queue {task_queue!r} within {timeout_seconds:.0f}s{detail}."
    )


async def run_scenario() -> dict[str, Any]:
    server_url = os.environ["DURABLE_WORKFLOW_SERVER_URL"]
    token = os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN", "test-token")
    namespace = os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default")
    task_queue = os.environ.get("POLYGLOT_PHP_TASK_QUEUE", "polyglot-php")

    request: dict[str, Any] = {"name": "Rasmus", "locale": "en"}
    workflow_id = f"polyglot-php-smoke-{uuid.uuid4().hex[:8]}"

    async with Client(server_url, token=token, namespace=namespace) as client:
        await wait_for_php_worker_capabilities(
            client,
            task_queue=task_queue,
            timeout_seconds=60.0,
        )

        handle = await client.start_workflow(
            workflow_type=WORKFLOW_TYPE,
            workflow_id=workflow_id,
            task_queue=task_queue,
            input=[request],
            memo={"sample": "polyglot.php", "smoke": True},
        )

        result = await handle.result(timeout=240.0, poll_interval=0.5)

    if not isinstance(result, dict):
        raise RuntimeError(f"smoke: expected dict result, got {type(result).__name__}")
    if result.get("workflow_runtime") != "php":
        raise RuntimeError(f"smoke: workflow_runtime != php: {result}")
    if result.get("activity_runtime") != "php":
        raise RuntimeError(f"smoke: activity_runtime != php: {result}")
    if result.get("request") != request:
        raise RuntimeError(f"smoke: request not echoed: {result}")

    marker = result.get("php_marker")
    if not isinstance(marker, dict) or marker.get("runtime") != "php":
        raise RuntimeError(f"smoke: marker activity did not run on php: {result}")
    if marker.get("activity") != MARKER_ACTIVITY:
        raise RuntimeError(f"smoke: marker activity mismatch: {marker}")
    if marker.get("name") != request["name"]:
        raise RuntimeError(f"smoke: marker.name mismatch: {marker}")

    description = result.get("php_description")
    if not isinstance(description, dict) or description.get("runtime") != "php":
        raise RuntimeError(f"smoke: describe activity did not run on php: {result}")
    if description.get("activity") != DESCRIBE_ACTIVITY:
        raise RuntimeError(f"smoke: describe activity mismatch: {description}")
    if description.get("marker_runtime") != "php":
        raise RuntimeError(f"smoke: describe activity lost php marker runtime: {description}")

    return {
        "scenario": "php_same_language",
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
