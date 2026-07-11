"""Python activities consumed by the polyglot PHP-authored workflow.

The PHP workflow worker schedules `polyglot.php-to-python.reverse` and
`polyglot.php-to-python.tally`; this Python container is the worker that
actually executes them. Both workers poll the same task queue against
one standalone Durable Workflow server, so each scheduled activity
crosses the language boundary on the wire.
"""
from __future__ import annotations

import asyncio
import contextlib
import importlib.metadata
import logging
import os
import socket
import traceback
from typing import Any

from durable_workflow import Client, TransportRetryPolicy, Worker, activity, serializer

TASK_QUEUE = os.environ.get("POLYGLOT_PHP2PY_TASK_QUEUE", "polyglot-php-to-python")
POLL_TIMEOUT_SECONDS = float(os.environ.get("DURABLE_WORKFLOW_POLL_TIMEOUT_SECONDS", "90"))
TYPED_ERROR_HEARTBEAT_SECONDS = float(os.environ.get("POLYGLOT_TYPED_ERROR_HEARTBEAT_SECONDS", "30"))
PYTHON_TYPED_ERROR_ACTIVITY = "polyglot.php-to-python.typed-error"
LOG = logging.getLogger("polyglot.python_worker")


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
        "codec": _avro_observation(),
    }


@activity.defn(name="polyglot.rust-to-python.echo")
def echo_rust_value(value: dict[str, Any]) -> dict[str, Any]:
    return {
        "runtime": "python",
        "value": value,
        "codec": _avro_observation(),
    }


def _avro_observation() -> dict[str, str]:
    return {
        "codec": "avro",
        "implementation": "Apache Avro",
        "package": "avro",
        "version": importlib.metadata.version("avro"),
    }


async def run_typed_error_worker(client: Client, worker_id: str) -> None:
    ack = await client.register_worker(
        worker_id=worker_id,
        task_queue=TASK_QUEUE,
        supported_activity_types=[PYTHON_TYPED_ERROR_ACTIVITY],
        max_concurrent_activity_tasks=1,
    )
    heartbeat_seconds = typed_error_heartbeat_seconds(ack)
    heartbeat_task = asyncio.create_task(
        heartbeat_typed_error_worker(client, worker_id, heartbeat_seconds),
    )
    LOG.info(
        "polyglot python typed-error worker registered: id=%s queue=%s types=[%s]",
        worker_id,
        TASK_QUEUE,
        PYTHON_TYPED_ERROR_ACTIVITY,
    )

    try:
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
    finally:
        heartbeat_task.cancel()
        with contextlib.suppress(asyncio.CancelledError):
            await heartbeat_task


def typed_error_heartbeat_seconds(register_ack: Any) -> float:
    configured = max(1.0, TYPED_ERROR_HEARTBEAT_SECONDS)
    advertised = (
        register_ack.get("heartbeat_interval_seconds")
        if isinstance(register_ack, dict)
        else None
    )

    if isinstance(advertised, int) and advertised > 0:
        return max(1.0, min(configured, float(advertised)))

    return configured


async def heartbeat_typed_error_worker(client: Client, worker_id: str, interval_seconds: float) -> None:
    interval = max(1.0, interval_seconds)

    while True:
        await asyncio.sleep(interval)
        try:
            await client.heartbeat_worker(
                worker_id=worker_id,
                task_slots={"activity_available": 1},
            )
        except Exception as exc:  # noqa: BLE001
            LOG.warning("polyglot python typed-error worker heartbeat failed: %s", exc)


async def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(name)s %(levelname)s %(message)s")

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
            activities=[reverse_string, tally, echo_value, echo_rust_value],
            poll_timeout=POLL_TIMEOUT_SECONDS,
            shutdown_timeout=10.0,
        )
        LOG.info("polyglot python activity worker ready on queue %s", TASK_QUEUE)
        await asyncio.gather(worker.run(), run_typed_error_worker(client, typed_error_worker_id))

    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
