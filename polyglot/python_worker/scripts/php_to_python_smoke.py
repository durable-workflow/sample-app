"""Polyglot smoke: PHP-authored workflow scheduling Python activities.

This script starts the workflow on the standalone Durable Workflow
server and waits for its completion. The actual workflow execution
happens on the **php-workflow-worker** container — a real PHP image
with Composer-installed `durable-workflow/workflow` plus the
PHP-authored class at `app/Workflows/Polyglot/PhpToPythonWorkflow.php`.
That worker registers the `polyglot.php-to-python.PhpToPythonWorkflow`
type on the `polyglot-php-to-python` task queue. Activities scheduled
by the PHP workflow are picked up by the **python-activity-worker**
container, so each run crosses the language boundary on the wire.

If the PHP worker container is not running (or refuses to register the
PHP-authored type), the workflow task never gets dispatched and the
describe poll below times out — the smoke fails. That property is the
test of "the polyglot stack actually has a PHP worker", not just a
Python script asserting Python truths.
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


WORKFLOW_TYPE = "polyglot.php-to-python.PhpToPythonWorkflow"


async def wait_for_php_worker(client: Client, task_queue: str, timeout_seconds: float) -> None:
    """Block until at least one PHP-runtime worker is registered.

    Without a PHP worker the workflow never dispatches; failing fast
    here gives a clearer error than the `describe` timeout below.
    """
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
            if getattr(worker, "runtime", None) == "php":
                return

        await asyncio.sleep(1.0)

    detail = f": {last_error}" if last_error is not None else ""
    raise RuntimeError(
        f"smoke: no PHP worker registered on task queue {task_queue!r} within "
        f"{timeout_seconds:.0f}s. The polyglot stack needs the php-workflow-worker "
        f"service to be running for this scenario{detail}."
    )


async def run_scenario() -> dict[str, Any]:
    server_url = os.environ["DURABLE_WORKFLOW_SERVER_URL"]
    token = os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN", "test-token")
    namespace = os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default")
    task_queue = os.environ.get("POLYGLOT_PHP2PY_TASK_QUEUE", "polyglot-php-to-python")

    workflow_input = "polyglot"
    workflow_id = f"polyglot-php2py-{uuid.uuid4().hex[:8]}"

    async with Client(server_url, token=token, namespace=namespace) as client:
        await wait_for_php_worker(client, task_queue, timeout_seconds=60.0)

        handle = await client.start_workflow(
            workflow_type=WORKFLOW_TYPE,
            task_queue=task_queue,
            workflow_id=workflow_id,
            input=[workflow_input],
            memo={"sample": "polyglot.php_to_python", "smoke": True},
        )

        result = await handle.result(timeout=240.0, poll_interval=0.5)

    if not isinstance(result, dict):
        raise RuntimeError(f"smoke: expected dict result, got {type(result).__name__}")

    if result.get("workflow_runtime") != "php":
        raise RuntimeError(f"smoke: workflow_runtime != php: {result}")
    if result.get("input") != workflow_input:
        raise RuntimeError(f"smoke: input not echoed: {result}")

    reverse = result.get("reverse")
    if not isinstance(reverse, dict) or reverse.get("runtime") != "python":
        raise RuntimeError(f"smoke: reverse activity did not run on python: {result}")
    if reverse.get("reversed") != workflow_input[::-1]:
        raise RuntimeError(f"smoke: reverse mismatch: {reverse}")

    tally = result.get("tally")
    if not isinstance(tally, dict) or tally.get("runtime") != "python":
        raise RuntimeError(f"smoke: tally activity did not run on python: {result}")
    expected_total = 2 * 1500 + 1 * 4200
    if tally.get("total_cents") != expected_total:
        raise RuntimeError(f"smoke: tally total_cents != {expected_total}: {tally}")

    return {
        "scenario": "php_to_python",
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
