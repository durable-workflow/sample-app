"""Polyglot smoke: Python-authored workflow on a Python worker.

Drives the workflow defined in ``polyglot/python_workflow/workflow.py``
against the standalone Durable Workflow server. The workflow itself is
executed by the long-running ``python-workflow-worker`` container, so
this script only acts as a client: it waits for the Python-runtime
worker to register on the polyglot Python task queue, starts a workflow,
and asserts the result.

If the ``python-workflow-worker`` service is not running (or fails to
register), the wait below times out and the smoke fails — which is the
property "the polyglot stack actually has a Python workflow worker".
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


WORKFLOW_TYPE = "polyglot.python.greeter"


async def wait_for_python_worker(client: Client, task_queue: str, timeout_seconds: float) -> None:
    """Block until at least one Python-runtime worker is registered."""
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
            if getattr(worker, "runtime", None) == "python":
                return

        await asyncio.sleep(1.0)

    detail = f": {last_error}" if last_error is not None else ""
    raise RuntimeError(
        f"smoke: no Python worker registered on task queue {task_queue!r} within "
        f"{timeout_seconds:.0f}s. The polyglot stack needs the python-workflow-worker "
        f"service to be running for this scenario{detail}."
    )


async def run_scenario() -> dict[str, Any]:
    server_url = os.environ["DURABLE_WORKFLOW_SERVER_URL"]
    token = os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN", "test-token")
    namespace = os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default")
    task_queue = os.environ.get("POLYGLOT_PY_TASK_QUEUE", "polyglot-python")

    request: dict[str, Any] = {"name": "Ada", "locale": "en"}
    workflow_id = f"polyglot-py-smoke-{uuid.uuid4().hex[:8]}"

    async with Client(server_url, token=token, namespace=namespace) as client:
        await wait_for_python_worker(client, task_queue, timeout_seconds=60.0)

        handle = await client.start_workflow(
            workflow_type=WORKFLOW_TYPE,
            workflow_id=workflow_id,
            task_queue=task_queue,
            input=[request],
            memo={"sample": "polyglot.python", "smoke": True},
        )

        result = await handle.result(timeout=240.0, poll_interval=0.5)

    if not isinstance(result, dict):
        raise RuntimeError(f"smoke: expected dict result, got {type(result).__name__}")

    if result.get("workflow_runtime") != "python":
        raise RuntimeError(f"smoke: workflow_runtime != python: {result}")
    if result.get("request") != request:
        raise RuntimeError(f"smoke: request not echoed: {result}")

    greeting = result.get("greeting")
    if not isinstance(greeting, dict) or greeting.get("language") != "python":
        raise RuntimeError(f"smoke: greet activity did not run on python: {result}")
    if greeting.get("name") != request["name"]:
        raise RuntimeError(f"smoke: greeting.name mismatch: {greeting}")

    summary = result.get("summary")
    if not isinstance(summary, dict) or summary.get("language") != "python":
        raise RuntimeError(f"smoke: summarise activity did not run on python: {result}")
    if summary.get("name_length") != len(request["name"]):
        raise RuntimeError(f"smoke: summary.name_length mismatch: {summary}")

    return {
        "scenario": "python_same_language",
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
