"""Run the featured PHP-authored PolyglotWorkflow against three real workers."""

from __future__ import annotations

import asyncio
import json
import os
import re
import sys
import time
import uuid
from typing import Any

from durable_workflow import Client


WORKFLOW_TYPE = "polyglot.PolyglotWorkflow"
WORKFLOW_QUEUE = os.environ.get("POLYGLOT_WORKFLOW_TASK_QUEUE", "polyglot-workflow")
PYTHON_QUEUE = os.environ.get("POLYGLOT_PHP2PY_TASK_QUEUE", "polyglot-php-to-python")
RUST_QUEUE = os.environ.get("POLYGLOT_TO_RUST_TASK_QUEUE", "polyglot-to-rust")
SERVER_URL = os.environ["DURABLE_WORKFLOW_SERVER_URL"]
TOKEN = os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN", "test-token")
NAMESPACE = os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default")

WORKER_REQUIREMENTS = (
    {
        "service": "polyglot-workflow-worker",
        "task_queue": WORKFLOW_QUEUE,
        "runtime": "php",
        "workflow_type": WORKFLOW_TYPE,
        "worker_id": "polyglot-workflow-worker",
        "version_env": "DURABLE_WORKFLOW_PHP_SDK_VERSION",
    },
    {
        "service": "python-activity-worker",
        "task_queue": PYTHON_QUEUE,
        "runtime": "python",
        "activity_type": "polyglot.php-to-python.tally",
        "version_env": "DURABLE_WORKFLOW_PYTHON_SDK_VERSION",
    },
    {
        "service": "rust-activity-worker",
        "task_queue": RUST_QUEUE,
        "runtime": "rust",
        "activity_type": "polyglot.php-to-rust.receipt",
        "worker_id": "rust-activity-worker",
        "version_env": "DURABLE_WORKFLOW_RUST_SDK_VERSION",
    },
)


def semantic_version(value: str | None) -> str | None:
    if not value:
        return None
    match = re.search(
        r"\b\d+\.\d+\.\d+(?:(?:-[0-9A-Za-z.-]+)|(?:(?:a|b|rc)\d+))?(?:\+[0-9A-Za-z.-]+)?\b",
        value,
    )
    return match.group(0) if match else None


def versions_match(runtime: str, observed: str | None, expected: str) -> bool:
    observed_version = semantic_version(observed)
    if observed_version == expected:
        return True
    if runtime == "python" and observed_version is not None:
        pep440 = re.sub(
            r"-(alpha|beta|rc)\.",
            lambda match: {"alpha": "a", "beta": "b", "rc": "rc"}[match.group(1)],
            expected,
        )
        return observed_version == pep440
    return False


async def wait_for_worker(requirement: dict[str, str], timeout_seconds: float) -> dict[str, Any]:
    deadline = time.monotonic() + timeout_seconds
    expected_version = os.environ[requirement["version_env"]]
    last_error: Exception | None = None

    async with Client(SERVER_URL, token=TOKEN, namespace=NAMESPACE) as client:
        while time.monotonic() < deadline:
            try:
                roster = await client.list_workers(task_queue=requirement["task_queue"])
            except Exception as exc:  # noqa: BLE001
                last_error = exc
                await asyncio.sleep(1.0)
                continue

            for worker in getattr(roster, "workers", []) or []:
                if getattr(worker, "runtime", None) != requirement["runtime"]:
                    continue
                if requirement.get("worker_id") and getattr(worker, "worker_id", None) != requirement["worker_id"]:
                    continue
                workflow_types = set(getattr(worker, "supported_workflow_types", []) or [])
                activity_types = set(getattr(worker, "supported_activity_types", []) or [])
                if requirement.get("workflow_type") not in workflow_types and requirement.get("workflow_type"):
                    continue
                if requirement.get("activity_type") not in activity_types and requirement.get("activity_type"):
                    continue

                observed_version = getattr(worker, "sdk_version", None)
                if not versions_match(requirement["runtime"], observed_version, expected_version):
                    raise RuntimeError(
                        f"{requirement['service']} advertised SDK {observed_version!r}; "
                        f"expected current artifact {expected_version!r}"
                    )

                return {
                    "service": requirement["service"],
                    "status": "ready",
                    "runtime": requirement["runtime"],
                    "task_queue": requirement["task_queue"],
                    "worker_id": getattr(worker, "worker_id", None),
                    "sdk_version": observed_version,
                    "expected_sdk_version": expected_version,
                }

            await asyncio.sleep(1.0)

    detail = f": {last_error}" if last_error is not None else ""
    raise RuntimeError(
        f"{requirement['service']} did not register the required {requirement['runtime']} "
        f"handler on {requirement['task_queue']!r} within {timeout_seconds:.0f}s{detail}"
    )


async def wait_for_workers(timeout_seconds: float = 90.0) -> list[dict[str, Any]]:
    return list(await asyncio.gather(*(
        wait_for_worker(requirement, timeout_seconds)
        for requirement in WORKER_REQUIREMENTS
    )))


def validate_result(result: Any, request: dict[str, Any]) -> dict[str, Any]:
    if not isinstance(result, dict):
        raise RuntimeError(f"PolyglotWorkflow expected an object result, got {type(result).__name__}")

    expected_queues = {
        "workflow": WORKFLOW_QUEUE,
        "python_activity": PYTHON_QUEUE,
        "rust_activity": RUST_QUEUE,
    }
    if result.get("workflow") != "PolyglotWorkflow" or result.get("workflow_runtime") != "php":
        raise RuntimeError(f"PolyglotWorkflow did not report the PHP workflow runtime: {result}")
    if result.get("activity_runtimes") != {"calculation": "python", "receipt": "rust"}:
        raise RuntimeError(f"PolyglotWorkflow did not report both activity runtimes: {result}")
    if result.get("task_queues") != expected_queues:
        raise RuntimeError(f"PolyglotWorkflow task queue routing changed: {result}")
    if result.get("request") != request:
        raise RuntimeError(f"PolyglotWorkflow request changed across the service boundary: {result}")

    calculation = result.get("python_calculation")
    if not isinstance(calculation, dict):
        raise RuntimeError(f"PolyglotWorkflow omitted the Python calculation: {result}")
    if calculation.get("runtime") != "python" or calculation.get("operation") != "calculate_order_total":
        raise RuntimeError(f"PolyglotWorkflow Python activity evidence is incomplete: {calculation}")
    if calculation.get("item_count") != 2 or calculation.get("total_cents") != 7200:
        raise RuntimeError(f"PolyglotWorkflow Python calculation is incorrect: {calculation}")

    receipt = result.get("rust_receipt")
    if not isinstance(receipt, dict):
        raise RuntimeError(f"PolyglotWorkflow omitted the Rust receipt: {result}")
    if receipt.get("runtime") != "rust" or receipt.get("operation") != "format_receipt":
        raise RuntimeError(f"PolyglotWorkflow Rust activity evidence is incomplete: {receipt}")
    if receipt.get("calculation_runtime") != "python":
        raise RuntimeError(f"PolyglotWorkflow did not pass the Python result to Rust: {receipt}")
    if receipt.get("display_total") != "$72.00" or receipt.get("message") != "Ada: 2 items total $72.00":
        raise RuntimeError(f"PolyglotWorkflow Rust receipt is incorrect: {receipt}")
    if not isinstance(result.get("summary"), str) or receipt["message"] not in result["summary"]:
        raise RuntimeError(f"PolyglotWorkflow did not combine both activity outputs: {result}")

    return result


async def run_scenario() -> dict[str, Any]:
    readiness = await wait_for_workers()
    request = {
        "name": "Ada",
        "items": [
            {"quantity": 2, "unit_price_cents": 1500},
            {"quantity": 1, "unit_price_cents": 4200},
        ],
    }
    workflow_id = f"polyglot-workflow-{uuid.uuid4().hex[:8]}"

    async with Client(SERVER_URL, token=TOKEN, namespace=NAMESPACE) as client:
        handle = await client.start_workflow(
            workflow_type=WORKFLOW_TYPE,
            task_queue=WORKFLOW_QUEUE,
            workflow_id=workflow_id,
            input=[request],
            memo={"sample": "PolyglotWorkflow", "runtimes": ["php", "python", "rust"]},
        )
        result = validate_result(
            await handle.result(timeout=240.0, poll_interval=0.5),
            request,
        )

    return {
        "scenario": "PolyglotWorkflow",
        "workflow_id": workflow_id,
        "workflow_type": WORKFLOW_TYPE,
        "status": "passed",
        "server_artifact": os.environ["DURABLE_SERVER_IMAGE"],
        "worker_readiness": readiness,
        "result": result,
    }


async def run() -> int:
    try:
        scenario = await run_scenario()
    except Exception as exc:  # noqa: BLE001
        print(str(exc), file=sys.stderr)
        return 1

    print("PolyglotWorkflow completed: PHP workflow -> Python calculation -> Rust receipt")
    print(json.dumps(scenario, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(run()))
