"""Python activities consumed by the polyglot PHP-authored workflow.

The PHP workflow worker schedules `polyglot.php-to-python.reverse` and
`polyglot.php-to-python.tally`; this Python container is the worker that
actually executes them. Both workers poll the same task queue against
one standalone Durable Workflow server, so each scheduled activity
crosses the language boundary on the wire.
"""
from __future__ import annotations

import asyncio
import logging
import os
import socket
import traceback
from typing import Any

from durable_workflow import Client, TransportRetryPolicy, Worker, activity, serializer

TASK_QUEUE = os.environ.get("POLYGLOT_PHP2PY_TASK_QUEUE", "polyglot-php-to-python")
POLL_TIMEOUT_SECONDS = float(os.environ.get("DURABLE_WORKFLOW_POLL_TIMEOUT_SECONDS", "90"))
PYTHON_TYPED_ERROR_ACTIVITY = "polyglot.php-to-python.typed-error"


@activity.defn(name="polyglot.php-to-python.reverse")
def reverse_string(value: str) -> dict[str, Any]:
    return {
        "runtime": "python",
        "input": value,
        "reversed": value[::-1],
        "length": len(value),
    }


@activity.defn(name="polyglot.php-to-python.tally")
def tally(items: list[dict[str, Any]]) -> dict[str, Any]:
    total = 0
    for item in items:
        total += int(item["quantity"]) * int(item["unit_price_cents"])
    return {
        "runtime": "python",
        "item_count": len(items),
        "total_cents": total,
    }


@activity.defn(name="polyglot.php-to-python.echo")
def echo_value(value: dict[str, Any]) -> dict[str, Any]:
    return {
        "runtime": "python",
        "value": value,
    }


async def run_typed_error_worker(client: Client, worker_id: str) -> None:
    await client.register_worker(
        worker_id=worker_id,
        task_queue=TASK_QUEUE,
        supported_activity_types=[PYTHON_TYPED_ERROR_ACTIVITY],
        max_concurrent_activity_tasks=1,
    )

    while True:
        task = await client.poll_activity_task(
            worker_id=worker_id,
            task_queue=TASK_QUEUE,
            timeout=POLL_TIMEOUT_SECONDS,
        )
        if task is None:
            continue

        activity_type = task.get("activity_type")
        task_id = task.get("task_id")
        attempt_id = task.get("activity_attempt_id")
        raw_args = task.get("arguments")
        args = (
            serializer.decode_envelope(raw_args, codec=task.get("payload_codec") or "avro")
            if raw_args is not None
            else []
        )
        request = args[0] if isinstance(args, list) and args else None

        if not isinstance(task_id, str) or not isinstance(attempt_id, str):
            continue

        if activity_type != PYTHON_TYPED_ERROR_ACTIVITY:
            await client.fail_activity_task(
                task_id=task_id,
                activity_attempt_id=attempt_id,
                lease_owner=worker_id,
                message=f"typed-error worker cannot handle {activity_type!r}",
                failure_type="UnknownPolyglotActivity",
                non_retryable=True,
            )
            continue

        await client.fail_activity_task(
            task_id=task_id,
            activity_attempt_id=attempt_id,
            lease_owner=worker_id,
            message="python activity planned typed failure",
            failure_type="PolyglotPythonTypedError",
            stack_trace="".join(traceback.format_stack(limit=8)),
            non_retryable=True,
            details={
                "origin": "python",
                "code": "PYTHON_TYPED_ERROR",
                "structured": {
                    "language": "python",
                    "request": request,
                },
            },
            activity_name=PYTHON_TYPED_ERROR_ACTIVITY,
        )


async def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(name)s %(levelname)s %(message)s")
    log = logging.getLogger("polyglot.python_worker")

    server_url = os.environ["DURABLE_WORKFLOW_SERVER_URL"]
    token = os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN", "test-token")
    namespace = os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default")
    typed_error_worker_id = os.environ.get(
        "POLYGLOT_PHP2PY_TYPED_ERROR_WORKER_ID",
        f"py-typed-error-worker-{socket.gethostname()}",
    )

    async with Client(
        server_url,
        token=token,
        namespace=namespace,
        timeout=POLL_TIMEOUT_SECONDS + 15,
        retry_policy=TransportRetryPolicy(max_attempts=1),
    ) as client:
        worker = Worker(
            client,
            task_queue=TASK_QUEUE,
            workflows=[],
            activities=[reverse_string, tally, echo_value],
            poll_timeout=POLL_TIMEOUT_SECONDS,
            shutdown_timeout=10.0,
        )
        log.info("polyglot python activity worker ready on queue %s", TASK_QUEUE)
        await asyncio.gather(worker.run(), run_typed_error_worker(client, typed_error_worker_id))

    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
