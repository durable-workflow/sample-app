"""Observe managed worker presence through the installed SDK and Server."""

from __future__ import annotations

import argparse
import asyncio
import json
from datetime import UTC, datetime
from uuid import uuid4

from durable_workflow import Client
from durable_workflow.client import WorkerDescription

from python_created_rust_schedule import activity_matches, required


async def find_worker(client: Client, runtime: str, worker_id: str, status: str | None = None) -> WorkerDescription | None:
    scope = await client.list_workers(task_queue=f"polyglot-{runtime}", status=status)
    if scope.stale_after_seconds != 9:
        raise RuntimeError("The Server did not advertise the fixture's nine-second stale window.")
    return next((worker for worker in scope.workers if worker.worker_id == worker_id), None)


def verify_registration(worker: WorkerDescription, runtime: str, version: str) -> None:
    if (
        worker.status != "active"
        or worker.runtime != runtime
        or (worker.sdk_version or "").rsplit("/", 1)[-1].lstrip("v") != version
        or not worker.registered_at
        or not worker.last_heartbeat_at
    ):
        raise RuntimeError(f"Unexpected {runtime} registration for {worker.worker_id}.")


async def active(client: Client, runtime: str, worker_id: str, version: str) -> dict:
    registered = None
    timestamps: list[datetime] = []
    async with asyncio.timeout(18):
        while len(timestamps) < 4:
            worker = await find_worker(client, runtime, worker_id)
            if worker is not None:
                verify_registration(worker, runtime, version)
                registered = registered or worker.registered_at
                if worker.registered_at != registered:
                    raise RuntimeError(f"{worker_id} acquired a different registration while idle.")
                heartbeat = datetime.fromisoformat(worker.last_heartbeat_at)
                if not timestamps or heartbeat > timestamps[-1]:
                    timestamps.append(heartbeat)
            await asyncio.sleep(0.2)

    gaps = [(after - before).total_seconds() for before, after in zip(timestamps, timestamps[1:])]
    if max(gaps) > 4:
        raise RuntimeError(f"{worker_id} heartbeat gaps {gaps!r} exceed the two-second fixture cadence tolerance.")
    return {
        "runtime": runtime, "sdk_version": version, "worker_id": worker_id,
        "registered_at": registered, "heartbeat_times": [value.isoformat() for value in timestamps],
        "gap_seconds": gaps,
    }


async def terminal(client: Client, runtime: str, worker_id: str, phase: str) -> dict | None:
    async with asyncio.timeout(15):
        while True:
            if phase == "deregistered":
                if (
                    await find_worker(client, runtime, worker_id) is None
                    and await find_worker(client, runtime, worker_id, status="stale") is None
                ):
                    return None
                await asyncio.sleep(0.2)
                continue
            worker = await find_worker(client, runtime, worker_id, status="stale")
            if worker is not None and worker.status == "stale":
                age = (datetime.now(UTC) - datetime.fromisoformat(worker.last_heartbeat_at)).total_seconds()
                if age < 8.9:
                    raise RuntimeError(f"{worker_id} became stale too early at {age:.3f} seconds.")
                return {"worker_id": worker_id, "last_heartbeat_at": worker.last_heartbeat_at, "observed_age_seconds": age}
            await asyncio.sleep(0.2)


async def workflow(client: Client, runtime: str, worker_id: str, version: str) -> dict:
    presence = await active(client, runtime, worker_id, version)
    marker = f"presence-{runtime}-{uuid4().hex[:12]}"
    request = marker if runtime == "rust" else {"name": marker, "locale": "en"}
    handle = await client.start_workflow(
        workflow_type=f"polyglot.{runtime}.greeter", workflow_id=marker,
        task_queue=f"polyglot-{runtime}", input=[request],
    )
    result = await handle.result(timeout=25, poll_interval=0.25)
    events = (await handle.get_history())["events"]
    kinds = [event["event_type"] for event in events]
    if (
        not handle.run_id or not isinstance(result, dict) or result.get("workflow_runtime") != runtime
        or result.get("request") != request or not activity_matches(runtime, result, marker)
        or kinds.count("WorkflowCompleted") != 1 or "ActivityCompleted" not in kinds
    ):
        raise RuntimeError(f"The {runtime} replacement did not return the authored workflow and activity result.")
    return {**presence, "workflow_id": marker, "run_id": handle.run_id, "result": result}


async def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("active", "deregistered", "stale", "workflow"))
    parser.add_argument("runtime", choices=("php", "python", "rust"))
    parser.add_argument("worker_id")
    parser.add_argument("version", nargs="?")
    args = parser.parse_args()
    if args.phase in ("active", "workflow") and not args.version:
        parser.error("the SDK version is required when verifying active workers")
    async with Client(
        required("DURABLE_WORKFLOW_SERVER_URL"), namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_AUTH_TOKEN"),
    ) as client:
        if args.phase == "active":
            result = await active(client, args.runtime, args.worker_id, args.version)
        elif args.phase == "workflow":
            result = await workflow(client, args.runtime, args.worker_id, args.version)
        else:
            result = await terminal(client, args.runtime, args.worker_id, args.phase)
    if result is not None:
        print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    asyncio.run(main())
