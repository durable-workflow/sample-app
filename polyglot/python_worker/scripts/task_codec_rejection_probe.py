"""Exercise published Python worker task boundaries with hostile root codec tags."""
from __future__ import annotations

import asyncio
import importlib.metadata
import json
import sys
from pathlib import Path
from typing import Any

from durable_workflow import Worker, activity, serializer, workflow

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import activities as manual_activities


@workflow.defn(name="probe.workflow")
class ProbeWorkflow:
    workflow_calls = 0
    query_calls = 0

    def run(self, _context: object, value: object = None) -> object:
        type(self).workflow_calls += 1
        return value

    @workflow.query("status")
    def status(self, value: object = None) -> object:
        type(self).query_calls += 1
        return value


ACTIVITY_CALLS = 0


@activity.defn(name="probe.activity")
def probe_activity(value: object = None) -> object:
    global ACTIVITY_CALLS
    ACTIVITY_CALLS += 1
    return value


class ProbeClient:
    external_storage = None
    external_storage_threshold_bytes = None
    external_storage_cache = None
    metrics = None
    payload_size_warning_config = None
    namespace = "default"

    def __init__(self) -> None:
        self.workflow_failures: list[dict[str, Any]] = []
        self.workflow_completions: list[dict[str, Any]] = []
        self.activity_failures: list[dict[str, Any]] = []
        self.activity_completions: list[dict[str, Any]] = []
        self.query_failures: list[dict[str, Any]] = []
        self.query_completions: list[dict[str, Any]] = []

    async def fail_workflow_task(self, **arguments: Any) -> dict[str, bool]:
        self.workflow_failures.append(arguments)
        return {"acknowledged": True}

    async def complete_workflow_task(self, **arguments: Any) -> dict[str, bool]:
        self.workflow_completions.append(arguments)
        return {"acknowledged": True}

    async def fail_activity_task(self, **arguments: Any) -> dict[str, bool]:
        self.activity_failures.append(arguments)
        return {"acknowledged": True}

    async def complete_activity_task(self, **arguments: Any) -> dict[str, bool]:
        self.activity_completions.append(arguments)
        return {"acknowledged": True}

    async def fail_query_task(self, **arguments: Any) -> dict[str, bool]:
        self.query_failures.append(arguments)
        return {"acknowledged": True}

    async def complete_query_task(self, **arguments: Any) -> dict[str, bool]:
        self.query_completions.append(arguments)
        return {"acknowledged": True}


CODEC_CASES: tuple[tuple[str, bool, object], ...] = (
    ("missing", False, None),
    ("empty", True, ""),
    ("json", True, "json"),
    ("unknown", True, "custom"),
    ("wrong_case", True, "Avro"),
    ("null", True, None),
    ("non_string", True, ["avro"]),
    ("malformed", True, "avro\0"),
)


def task(path: str, present: bool, codec: object, arguments: object) -> dict[str, Any]:
    if path == "workflow":
        value = {
            "task_id": "probe-workflow",
            "workflow_task_attempt": 1,
            "lease_owner": "probe-worker",
            "workflow_id": "probe-workflow-id",
            "run_id": "probe-workflow-run",
            "workflow_type": "probe.workflow",
            "arguments": arguments,
            "history_events": [],
        }
    elif path == "activity":
        value = {
            "task_id": "probe-activity",
            "activity_attempt_id": "probe-activity-attempt",
            "lease_owner": "probe-worker",
            "activity_type": "probe.activity",
            "arguments": arguments,
        }
    elif path == "query":
        value = {
            "query_task_id": "probe-query",
            "query_task_attempt": 1,
            "lease_owner": "probe-worker",
            "workflow_id": "probe-query-id",
            "run_id": "probe-query-run",
            "workflow_type": "probe.workflow",
            "query_name": "status",
            "workflow_arguments": arguments,
            "query_arguments": arguments,
            "history_events": [],
        }
    else:
        raise ValueError(f"unknown Python SDK probe path {path!r}")
    if present:
        value["payload_codec"] = codec
    return value


def worker(client: ProbeClient) -> Worker:
    return Worker(
        client,  # type: ignore[arg-type]
        task_queue="probe-queue",
        worker_id="probe-worker",
        workflows=[ProbeWorkflow],
        activities=[probe_activity],
    )


def diagnostic(failures: list[dict[str, Any]]) -> tuple[bool, bool]:
    document = json.dumps(failures, default=repr)
    return "unsupported_payload_codec" in document, "decode-must-not-run" in document


async def run_sdk_path(path: str, payload: dict[str, Any]) -> None:
    runtime = worker(payload["client"])
    if path == "workflow":
        await runtime._run_workflow_task(payload["task"])
    elif path == "activity":
        await runtime._run_activity_task(payload["task"])
    else:
        await runtime._run_query_task(payload["task"])


def sdk_observation(path: str, client: ProbeClient) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    if path == "workflow":
        return client.workflow_failures, client.workflow_completions
    if path == "activity":
        return client.activity_failures, client.activity_completions
    return client.query_failures, client.query_completions


async def sdk_rejection(path: str, name: str, present: bool, codec: object) -> dict[str, Any]:
    global ACTIVITY_CALLS
    ACTIVITY_CALLS = 0
    ProbeWorkflow.workflow_calls = 0
    ProbeWorkflow.query_calls = 0
    client = ProbeClient()
    hostile = task(
        path,
        present,
        codec,
        {"codec": "avro", "blob": "decode-must-not-run"},
    )
    await run_sdk_path(path, {"client": client, "task": hostile})
    failures, completions = sdk_observation(path, client)
    unsupported, poison_observed = diagnostic(failures)
    handler_calls = ACTIVITY_CALLS + ProbeWorkflow.workflow_calls + ProbeWorkflow.query_calls
    passed = (
        len(failures) == 1
        and not completions
        and unsupported
        and not poison_observed
        and handler_calls == 0
    )
    return {
        "runtime": "python",
        "worker": "sdk",
        "path": path,
        "codec_case": name,
        "status": "rejected_before_decode_or_handler" if passed else "failed",
        "failure_count": len(failures),
        "completion_count": len(completions),
        "unsupported_diagnostic": unsupported,
        "poison_decode_diagnostic": poison_observed,
        "handler_calls": handler_calls,
    }


async def sdk_valid(path: str) -> dict[str, Any]:
    global ACTIVITY_CALLS
    ACTIVITY_CALLS = 0
    ProbeWorkflow.workflow_calls = 0
    ProbeWorkflow.query_calls = 0
    client = ProbeClient()
    arguments = serializer.envelope(["input"], codec="avro")
    await run_sdk_path(path, {"client": client, "task": task(path, True, "avro", arguments)})
    failures, completions = sdk_observation(path, client)
    handler_calls = ACTIVITY_CALLS + ProbeWorkflow.workflow_calls + ProbeWorkflow.query_calls
    passed = not failures and len(completions) == 1 and handler_calls > 0
    return {
        "runtime": "python",
        "worker": "sdk",
        "path": path,
        "codec_case": "avro",
        "status": "decoded_and_handled" if passed else "failed",
        "failure_count": len(failures),
        "completion_count": len(completions),
        "handler_calls": handler_calls,
    }


async def manual_rejection(name: str, present: bool, codec: object) -> dict[str, Any]:
    client = ProbeClient()
    manual_task = {
        "task_id": "manual-probe",
        "activity_attempt_id": "manual-probe-attempt",
        "activity_type": manual_activities.PYTHON_TYPED_ERROR_ACTIVITY,
        "arguments": {"codec": "avro", "blob": "decode-must-not-run"},
    }
    if present:
        manual_task["payload_codec"] = codec
    outcome = await manual_activities.handle_typed_error_task(
        client,  # type: ignore[arg-type]
        "manual-probe-worker",
        manual_task,
    )
    unsupported, poison_observed = diagnostic(client.activity_failures)
    passed = (
        outcome == "unsupported_payload_codec"
        and len(client.activity_failures) == 1
        and not client.activity_completions
        and unsupported
        and not poison_observed
        and "details" not in client.activity_failures[0]
    )
    return {
        "runtime": "python",
        "worker": "manual",
        "path": "activity",
        "codec_case": name,
        "status": "rejected_before_decode_or_handler" if passed else "failed",
        "failure_count": len(client.activity_failures),
        "completion_count": len(client.activity_completions),
        "unsupported_diagnostic": unsupported,
        "poison_decode_diagnostic": poison_observed,
    }


async def manual_valid() -> dict[str, Any]:
    client = ProbeClient()
    customer_payload = {"codec": "json", "payload_codec": "customer-owned"}
    outcome = await manual_activities.handle_typed_error_task(
        client,  # type: ignore[arg-type]
        "manual-probe-worker",
        {
            "task_id": "manual-probe",
            "activity_attempt_id": "manual-probe-attempt",
            "activity_type": manual_activities.PYTHON_TYPED_ERROR_ACTIVITY,
            "payload_codec": "avro",
            "arguments": serializer.envelope([customer_payload], codec="avro"),
        },
    )
    handled_failure = client.activity_failures[0] if client.activity_failures else {}
    request = (((handled_failure.get("details") or {}).get("structured") or {}).get("request"))
    passed = outcome == "handled" and request == customer_payload
    return {
        "runtime": "python",
        "worker": "manual",
        "path": "activity",
        "codec_case": "avro",
        "status": "decoded_and_handled" if passed else "failed",
        "handler_calls": 1 if passed else 0,
    }


async def main() -> int:
    rejections = []
    for name, present, codec in CODEC_CASES:
        for path in ("workflow", "activity", "query"):
            rejections.append(await sdk_rejection(path, name, present, codec))
        rejections.append(await manual_rejection(name, present, codec))
    valid_controls = [await sdk_valid(path) for path in ("workflow", "activity", "query")]
    valid_controls.append(await manual_valid())
    failures = [item for item in [*rejections, *valid_controls] if item["status"] == "failed"]
    evidence = {
        "schema": "durable-workflow.sample-app.task-codec-rejection-probe",
        "version": 1,
        "runtime": "python",
        "artifact": {
            "name": "durable-workflow",
            "version": importlib.metadata.version("durable-workflow"),
        },
        "rejection_outcomes": rejections,
        "valid_controls": valid_controls,
        "summary": {
            "status": "passed" if not failures else "failed",
            "rejection_count": len(rejections),
            "valid_control_count": len(valid_controls),
            "failed_count": len(failures),
        },
    }
    print(json.dumps(evidence, indent=2, sort_keys=True))
    return 0 if not failures else 1


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
