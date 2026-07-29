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
import base64
import json
import logging
import os
import socket
import sys
from pathlib import Path
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


class _PythonTypeRoundtripWorkflow:
    activity_type = ""
    activity_queue = ""

    def run(self, ctx, payload):  # type: ignore[no-untyped-def]
        wire_payload = _native_binary_payload(payload)
        echo = yield ctx.schedule_activity(
            self.activity_type,
            [wire_payload],
            queue=self.activity_queue,
        )
        normalized_echo, binary_evidence = _complete_native_binary_roundtrip(payload, echo)
        return {
            "workflow_runtime": "python",
            "activity_runtime": echo.get("runtime") if isinstance(echo, dict) else None,
            "input": payload,
            "echo": normalized_echo,
            "binary_evidence": binary_evidence,
        }


@workflow.defn(name="polyglot.python-to-php.type-roundtrip")
class PythonToPhpTypeRoundtripWorkflow(_PythonTypeRoundtripWorkflow):
    activity_type = "polyglot.python-to-php.echo"
    activity_queue = PHP_ACTIVITY_TASK_QUEUE


@workflow.defn(name="polyglot.python-to-php.binary-type-roundtrip")
class PythonToPhpBinaryTypeRoundtripWorkflow(_PythonTypeRoundtripWorkflow):
    activity_type = "polyglot.python-to-php.binary-echo"
    activity_queue = PHP_ACTIVITY_TASK_QUEUE


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
class PythonToRustTypeRoundtripWorkflow(_PythonTypeRoundtripWorkflow):
    activity_type = "polyglot.python-to-rust.echo"
    activity_queue = RUST_ACTIVITY_TASK_QUEUE


@workflow.defn(name="polyglot.python-to-rust.binary-type-roundtrip")
class PythonToRustBinaryTypeRoundtripWorkflow(_PythonTypeRoundtripWorkflow):
    activity_type = "polyglot.python-to-rust.binary-echo"
    activity_queue = RUST_ACTIVITY_TASK_QUEUE


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


def _native_binary_payload(payload: dict[str, Any]) -> dict[str, Any]:
    encoded = payload.get("binary_base64")
    text = payload.get("binary_text")
    if not isinstance(encoded, str) or not isinstance(text, str):
        raise TypeError("binary_base64 and binary_text must be strings")

    binary = base64.b64decode(encoded, validate=True)
    if binary == text.encode("utf-8"):
        raise ValueError("binary fixture must be distinct from its UTF-8 text companion")

    return {
        **payload,
        "binary_native": binary,
    }


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
        raise ValueError("native Python bytes changed across the workflow boundary")
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


def _complete_native_binary_roundtrip(
    payload: dict[str, Any],
    echo: Any,
) -> tuple[dict[str, Any], dict[str, Any]]:
    if not isinstance(echo, dict) or not isinstance(echo.get("value"), dict):
        raise TypeError("activity did not return the native binary payload")
    activity_evidence = echo.get("binary_evidence")
    if not isinstance(activity_evidence, dict):
        activity_evidence = _native_binary_evidence(echo["value"])
        if isinstance(echo.get("runtime"), str):
            activity_evidence["runtime"] = echo["runtime"]

    workflow_evidence = _native_binary_evidence(echo["value"])
    normalized_echo = dict(echo)
    normalized_echo["value"] = payload
    normalized_echo["binary_evidence"] = activity_evidence

    return normalized_echo, {
        "activity": activity_evidence,
        "workflow": workflow_evidence,
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


def verify_replay_fixtures() -> int:
    from durable_workflow.workflow import CompleteWorkflow, Replayer, ScheduleActivity

    fixture_path = Path(__file__).with_name("replay_fixtures") / "pre-binary-activity-split.json"
    fixture = json.loads(fixture_path.read_text(encoding="utf-8"))
    replayer = Replayer(
        workflows=[
            PythonToPhpTypeRoundtripWorkflow,
            PythonToRustTypeRoundtripWorkflow,
        ],
    )

    for case in fixture["cases"]:
        outcome = replayer.replay(
            case["history"],
            case["input"],
            workflow_type=case["workflow_type"],
            workflow_id=f"upgrade-{case['direction']}",
            run_id=f"pre-binary-split-{case['direction']}",
            payload_codec=case["payload_codec"],
        )
        if case["expected_state"] == "waiting":
            if len(outcome.commands) != 1 or not isinstance(
                outcome.commands[0],
                ScheduleActivity,
            ):
                raise RuntimeError(f"{case['name']}: unresolved activity did not replay as pending")
            continue

        if len(outcome.commands) != 1 or not isinstance(outcome.commands[0], CompleteWorkflow):
            raise RuntimeError(f"{case['name']}: completed history did not replay to completion")
        output = outcome.commands[0].result
        if output.get("activity_runtime") != case["expected_activity_runtime"]:
            raise RuntimeError(f"{case['name']}: replayed activity runtime changed")
        if (
            output.get("binary_evidence", {}).get("workflow", {}).get("base64")
            != case["input"][0]["binary_base64"]
        ):
            raise RuntimeError(f"{case['name']}: replayed native bytes changed")

    print(f"python upgrade replay fixtures passed: {len(fixture['cases'])}")
    return 0


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
                PythonToPhpBinaryTypeRoundtripWorkflow,
                PythonToPhpTypedErrorWorkflow,
                PythonToRustGreeterWorkflow,
                PythonToRustTypeRoundtripWorkflow,
                PythonToRustBinaryTypeRoundtripWorkflow,
                PythonSignalQueryWorkflow,
            ],
            activities=[greet, summarise],
            worker_id=worker_id,
            poll_timeout=POLL_TIMEOUT_SECONDS,
            shutdown_timeout=10.0,
        )
        log.info(
            "polyglot python workflow worker starting: id=%s queue=%s types=[polyglot.python.greeter,polyglot.python-to-php.greeter,polyglot.python-to-php.type-roundtrip,polyglot.python-to-php.binary-type-roundtrip,polyglot.python-to-php.typed-error,polyglot.python-to-rust.greeter,polyglot.python-to-rust.type-roundtrip,polyglot.python-to-rust.binary-type-roundtrip,polyglot.python.signal-query]",
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
    if "--replay-fixtures" in sys.argv:
        raise SystemExit(verify_replay_fixtures())
    raise SystemExit(asyncio.run(main()))
