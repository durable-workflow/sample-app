"""Python activities consumed by PHP and Rust authored workflows.

The featured PHP-authored PolyglotWorkflow routes its order calculation here
on the dedicated Python activity queue. Directional conformance workflows also
use the reverse, tally, echo, and typed-error handlers. Every call reaches this
Python process through the standalone Durable Workflow server.
"""
from __future__ import annotations

import asyncio
import base64
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
_MISSING_CODEC = object()


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
        "operation": "calculate_order_total",
        "item_count": len(items),
        "total_cents": total,
    }


@activity.defn(name="polyglot.php-to-python.echo")
def echo_value(value: dict[str, Any]) -> dict[str, Any]:
    return _echo_value(value)


@activity.defn(name="polyglot.rust-to-python.echo")
def echo_rust_value(value: dict[str, Any]) -> dict[str, Any]:
    return _echo_value(value)


@activity.defn(name="polyglot.php-to-python.binary-echo")
def echo_native_binary_value(value: dict[str, Any]) -> dict[str, Any]:
    return _echo_native_binary_value(value)


@activity.defn(name="polyglot.rust-to-python.binary-echo")
def echo_rust_native_binary_value(value: dict[str, Any]) -> dict[str, Any]:
    return _echo_native_binary_value(value)


def _echo_value(value: dict[str, Any]) -> dict[str, Any]:
    return {
        "runtime": "python",
        "value": value,
        "codec": _avro_observation(),
    }


def _echo_native_binary_value(value: dict[str, Any]) -> dict[str, Any]:
    binary_evidence = _native_binary_evidence(value)
    result = _echo_value(value)
    result["binary_evidence"] = binary_evidence
    return result


def _native_binary_evidence(value: dict[str, Any]) -> dict[str, Any]:
    binary = value.get("binary_native")
    text = value.get("binary_text")
    encoded = value.get("binary_base64")
    if type(binary) is not bytes:
        raise TypeError(f"expected native Python bytes, received {type(binary).__name__}")
    if type(text) is not str:
        raise TypeError(f"expected UTF-8 text as str, received {type(text).__name__}")
    if not isinstance(encoded, str):
        raise TypeError("expected the binary fixture base64 to be text")

    expected = base64.b64decode(encoded, validate=True)
    if binary != expected:
        raise ValueError("native Python bytes changed across the activity boundary")
    if binary == text.encode("utf-8"):
        raise ValueError("native Python bytes collapsed into the UTF-8 text value")

    return {
        "runtime": "python",
        "native_type": "bytes",
        "base64": base64.b64encode(binary).decode("ascii"),
        "byte_length": len(binary),
        "matches_expected": True,
        "text_type": "str",
        "text_value": text,
        "text_and_bytes_distinct": True,
    }


def _avro_observation() -> dict[str, str]:
    return {
        "codec": "avro",
        "implementation": "fastavro",
        "package": "fastavro",
        "version": importlib.metadata.version("fastavro"),
        "schema": "durable_workflow.protocol.Value",
        "fingerprint": "e2a33dff55802237",
        "framing": "single_object",
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

            await handle_typed_error_task(client, worker_id, task)
    finally:
        heartbeat_task.cancel()
        with contextlib.suppress(asyncio.CancelledError):
            await heartbeat_task


def validate_task_payload_codec(task: dict[str, Any]) -> str:
    codec = task.get("payload_codec", _MISSING_CODEC)
    if codec == "avro" and isinstance(codec, str):
        return codec

    rendered = "missing" if codec is _MISSING_CODEC else repr(codec)
    raise ValueError(
        "unsupported_payload_codec: worker task payload_codec "
        f"{rendered} is not supported by Durable Workflow 2.0; use "
        'payload_codec="avro" with the fixed Avro Value schema and single-object '
        "framing. JSON remains the HTTP document transport, not a workflow payload codec."
    )


async def handle_typed_error_task(client: Client, worker_id: str, task: dict[str, Any]) -> str:
    task_id = task.get("task_id")
    attempt_id = task.get("activity_attempt_id")
    try:
        codec = validate_task_payload_codec(task)
    except ValueError as exc:
        if isinstance(task_id, str) and isinstance(attempt_id, str):
            await client.fail_activity_task(
                task_id=task_id,
                activity_attempt_id=attempt_id,
                lease_owner=worker_id,
                message=str(exc),
                failure_type=type(exc).__name__,
                non_retryable=True,
            )
        return "unsupported_payload_codec"

    activity_type = task.get("activity_type")
    raw_args = task.get("arguments")
    args = serializer.decode_envelope(raw_args, codec=codec) if raw_args is not None else []
    request = args[0] if isinstance(args, list) and args else None

    if not isinstance(task_id, str) or not isinstance(attempt_id, str):
        return "malformed_task"

    if activity_type != PYTHON_TYPED_ERROR_ACTIVITY:
        await client.fail_activity_task(
            task_id=task_id,
            activity_attempt_id=attempt_id,
            lease_owner=worker_id,
            message=f"typed-error worker cannot handle {activity_type!r}",
            failure_type="UnknownPolyglotActivity",
            non_retryable=True,
        )
        return "unknown_activity"

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
    return "handled"


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
            activities=[
                reverse_string,
                tally,
                echo_value,
                echo_rust_value,
                echo_native_binary_value,
                echo_rust_native_binary_value,
            ],
            poll_timeout=POLL_TIMEOUT_SECONDS,
            shutdown_timeout=10.0,
        )
        LOG.info("polyglot python activity worker ready on queue %s", TASK_QUEUE)
        await asyncio.gather(worker.run(), run_typed_error_worker(client, typed_error_worker_id))

    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
