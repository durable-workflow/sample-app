"""Python-authored polyglot workflows + activities.

The worker hosts two Python-authored scenarios against the same
standalone server the PHP workflow runs against:

* ``polyglot.python.greeter`` stays inside Python — workflow code,
  activity code, and worker container are all Python.
* ``polyglot.python-to-php.greeter`` keeps workflow code in Python but
  schedules activities on the PHP activity-worker queue.

Running this module starts a long-lived worker that registers
both workflows (and the ``polyglot.python.greet`` /
``polyglot.python.summarise`` activities) on the polyglot Python task
queue. The PHP activity-worker queue is separate; the Python-to-PHP
workflow names it explicitly when scheduling ``polyglot.python-to-php.*``.
The polyglot smoke driver uses the running worker rather than spinning
up its own embedded poller, so the docker-compose stack is the actual
unit under test.
"""
from __future__ import annotations

import asyncio
import logging
import os
import socket
from typing import Any

from durable_workflow import Client, TransportRetryPolicy, Worker, activity, serializer, workflow
from durable_workflow.errors import ActivityFailed

TASK_QUEUE = os.environ.get("POLYGLOT_PY_TASK_QUEUE", "polyglot-python")
PHP_ACTIVITY_TASK_QUEUE = os.environ.get("POLYGLOT_PY2PHP_TASK_QUEUE", "polyglot-python-to-php")
RUST_ACTIVITY_TASK_QUEUE = os.environ.get("POLYGLOT_TO_RUST_TASK_QUEUE", "polyglot-to-rust")
POLL_TIMEOUT_SECONDS = float(os.environ.get("DURABLE_WORKFLOW_POLL_TIMEOUT_SECONDS", "90"))
POLYGLOT_SIGNAL_NAME = "polyglot-signal"
POLYGLOT_SIGNAL_CONDITION_KEY = f"polyglot.signal.{POLYGLOT_SIGNAL_NAME}"


@activity.defn(name="polyglot.python.greet")
def greet(name: str, locale: str) -> dict[str, Any]:
    return {
        "language": "python",
        "name": name,
        "locale": locale,
        "greeting": _localised_greeting(name, locale),
    }


@activity.defn(name="polyglot.python.summarise")
def summarise(payload: dict[str, Any]) -> dict[str, Any]:
    return {
        "language": "python",
        "input_keys": sorted(payload.keys()),
        "name_length": len(str(payload["name"])),
    }


@workflow.defn(name="polyglot.python.greeter")
class PythonGreeterWorkflow:
    def run(self, ctx, request):  # type: ignore[no-untyped-def]
        greeting = yield ctx.schedule_activity(
            "polyglot.python.greet",
            [request["name"], request["locale"]],
        )
        summary = yield ctx.schedule_activity(
            "polyglot.python.summarise",
            [greeting],
        )
        return {
            "workflow_runtime": "python",
            "greeting": greeting,
            "summary": summary,
            "request": request,
        }


@workflow.defn(name="polyglot.python-to-php.greeter")
class PythonToPhpGreeterWorkflow:
    def run(self, ctx, request):  # type: ignore[no-untyped-def]
        marker = yield ctx.schedule_activity(
            "polyglot.python-to-php.marker",
            [request],
            queue=PHP_ACTIVITY_TASK_QUEUE,
        )
        description = yield ctx.schedule_activity(
            "polyglot.python-to-php.describe",
            [marker],
            queue=PHP_ACTIVITY_TASK_QUEUE,
        )
        return {
            "workflow_runtime": "python",
            "activity_runtime": marker.get("runtime") if isinstance(marker, dict) else None,
            "php_marker": marker,
            "php_description": description,
            "request": request,
        }


@workflow.defn(name="polyglot.python-to-php.type-roundtrip")
class PythonToPhpTypeRoundtripWorkflow:
    def run(self, ctx, payload):  # type: ignore[no-untyped-def]
        echo = yield ctx.schedule_activity(
            "polyglot.python-to-php.echo",
            [payload],
            queue=PHP_ACTIVITY_TASK_QUEUE,
        )
        return {
            "workflow_runtime": "python",
            "activity_runtime": echo.get("runtime") if isinstance(echo, dict) else None,
            "input": payload,
            "echo": echo,
        }


@workflow.defn(name="polyglot.python-to-php.typed-error")
class PythonToPhpTypedErrorWorkflow:
    def run(self, ctx, request):  # type: ignore[no-untyped-def]
        try:
            yield ctx.schedule_activity(
                "polyglot.python-to-php.typed-error",
                [request],
                queue=PHP_ACTIVITY_TASK_QUEUE,
            )
        except ActivityFailed as exc:
            return {
                "workflow_runtime": "python",
                "activity_runtime": "php",
                "request": request,
                "failure": _activity_failure(exc),
            }
        return {
            "workflow_runtime": "python",
            "activity_runtime": "php",
            "request": request,
            "failure": None,
        }


@workflow.defn(name="polyglot.python-to-rust.greeter")
class PythonToRustGreeterWorkflow:
    def run(self, ctx, request):  # type: ignore[no-untyped-def]
        echo = yield ctx.schedule_activity(
            "polyglot.python-to-rust.echo",
            [request],
            queue=RUST_ACTIVITY_TASK_QUEUE,
        )
        return {
            "workflow_runtime": "python",
            "activity_runtime": echo.get("runtime") if isinstance(echo, dict) else None,
            "request": request,
            "echo": echo,
        }


@workflow.defn(name="polyglot.python-to-rust.type-roundtrip")
class PythonToRustTypeRoundtripWorkflow:
    def run(self, ctx, payload):  # type: ignore[no-untyped-def]
        echo = yield ctx.schedule_activity(
            "polyglot.python-to-rust.echo",
            [payload],
            queue=RUST_ACTIVITY_TASK_QUEUE,
        )
        return {
            "workflow_runtime": "python",
            "activity_runtime": echo.get("runtime") if isinstance(echo, dict) else None,
            "input": payload,
            "echo": echo,
        }


@workflow.defn(name="polyglot.python.signal-query")
class PythonSignalQueryWorkflow:
    def __init__(self) -> None:
        self.request: dict[str, Any] = {}
        self.signals: list[Any] = []
        self.stage = "created"

    @workflow.signal(POLYGLOT_SIGNAL_NAME)
    def on_polyglot_signal(self, payload):  # type: ignore[no-untyped-def]
        self.signals.append(payload)
        self.stage = "signaled"

    @workflow.query("state")
    def state(self) -> dict[str, Any]:
        return {
            "workflow_runtime": "python",
            "stage": self.stage,
            "signal_count": len(self.signals),
            "signals": list(self.signals),
            "request": self.request,
        }

    def run(self, ctx, request):  # type: ignore[no-untyped-def]
        self.request = request
        self.stage = "waiting"
        yield ctx.wait_condition(lambda: bool(self.signals), key=POLYGLOT_SIGNAL_CONDITION_KEY)
        yield ctx.wait_condition(lambda: len(self.signals) >= 2, key=POLYGLOT_SIGNAL_CONDITION_KEY)
        return {
            "workflow_runtime": "python",
            "request": request,
            "signal": self.signals[0],
        }


def _activity_failure(exc: ActivityFailed) -> dict[str, Any]:
    exception_payload = exc.exception_payload
    details = _decode_failure_details(exception_payload)

    return {
        "message": str(exc),
        "activity_type": exc.activity_type,
        "activity_execution_id": exc.activity_execution_id,
        "activity_attempt_id": exc.activity_attempt_id,
        "failure_id": exc.failure_id,
        "failure_category": exc.failure_category,
        "exception_type": exc.exception_type,
        "exception_class": exc.exception_class,
        "non_retryable": exc.non_retryable,
        "code": exc.code,
        "exception_payload": exception_payload,
        "details": details,
        "activity": exc.activity,
    }


def _decode_failure_details(exception_payload: dict[str, Any] | None) -> Any:
    if isinstance(exception_payload, dict):
        raw_details = exception_payload.get("details")
        details_codec = exception_payload.get("details_payload_codec")
        return _decode_details_value(raw_details, details_codec)
    return None


def _decode_details_value(value: Any, codec: Any = None, depth: int = 0) -> Any:
    if depth > 3:
        return value
    if isinstance(value, dict) and "codec" in value and "blob" in value:
        return _decode_details_value(serializer.decode_envelope(value), None, depth + 1)
    if isinstance(value, str) and isinstance(codec, str):
        return _decode_details_value(serializer.decode(value, codec=codec), None, depth + 1)
    if isinstance(value, list) and len(value) == 1:
        return _decode_details_value(value[0], codec, depth + 1)
    if isinstance(value, dict) and isinstance(value.get("payload"), dict):
        payload = value["payload"]
        if "codec" in payload and "blob" in payload:
            return _decode_details_value(payload, codec, depth + 1)
    return value


def _localised_greeting(name: str, locale: str) -> str:
    table = {
        "en": "hello",
        "fr": "bonjour",
        "ja": "こんにちは",
        "es": "hola",
    }
    return f"{table.get(locale, 'hello')}, {name}"


async def main() -> int:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(name)s %(levelname)s %(message)s",
    )
    log = logging.getLogger("polyglot.python_workflow_worker")

    server_url = os.environ["DURABLE_WORKFLOW_SERVER_URL"]
    token = os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN", "test-token")
    namespace = os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default")
    worker_id = os.environ.get(
        "POLYGLOT_PY_WORKER_ID",
        f"py-workflow-worker-{socket.gethostname()}",
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
            workflows=[
                PythonGreeterWorkflow,
                PythonToPhpGreeterWorkflow,
                PythonToPhpTypeRoundtripWorkflow,
                PythonToPhpTypedErrorWorkflow,
                PythonToRustGreeterWorkflow,
                PythonToRustTypeRoundtripWorkflow,
                PythonSignalQueryWorkflow,
            ],
            activities=[greet, summarise],
            worker_id=worker_id,
            poll_timeout=POLL_TIMEOUT_SECONDS,
            shutdown_timeout=10.0,
        )
        log.info(
            "polyglot python workflow worker starting: id=%s queue=%s types=[polyglot.python.greeter,polyglot.python-to-php.greeter,polyglot.python-to-php.type-roundtrip,polyglot.python-to-php.typed-error,polyglot.python-to-rust.greeter,polyglot.python-to-rust.type-roundtrip,polyglot.python.signal-query]",
            worker_id,
            TASK_QUEUE,
        )
        # `worker.run()` calls /api/worker/register, then the SDK emits its
        # own INFO log of the form "worker <id> registered on <queue>". The
        # polyglot CI greps that line as the registration regression test;
        # see polyglot-validation.yml.
        await worker.run()

    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
