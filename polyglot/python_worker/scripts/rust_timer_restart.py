"""Start and verify a published Rust SDK timer across a worker restart."""

from __future__ import annotations

import argparse
import asyncio
import json
import os
from datetime import datetime

from durable_workflow import Client


WORKFLOW_TYPE = "polyglot.rust.timer"
TIMER_SECONDS = 30


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before running the Rust timer experiment.")
    return value


def timer_events(history: dict, event_type: str) -> list[dict]:
    return [
        event
        for event in history["events"]
        if event.get("event_type") == event_type
        and event.get("payload", {}).get("timer_kind") not in ("condition_timeout", "signal_timeout")
    ]


async def start(client: Client, workflow_id: str, queue: str) -> None:
    handle = await client.start_workflow(
        workflow_type=WORKFLOW_TYPE,
        workflow_id=workflow_id,
        task_queue=queue,
        input=[workflow_id],
    )
    execution = await handle.describe()
    if not execution.run_id:
        raise RuntimeError("The started workflow has no run ID.")

    deadline = asyncio.get_running_loop().time() + 20
    while True:
        history = await client.get_history(workflow_id, execution.run_id)
        scheduled = timer_events(history, "TimerScheduled")
        if scheduled:
            break
        if asyncio.get_running_loop().time() >= deadline:
            raise TimeoutError("The Rust worker did not schedule its durable timer.")
        await asyncio.sleep(0.25)

    if len(scheduled) != 1 or timer_events(history, "TimerFired"):
        raise RuntimeError("The timer was not observed pending exactly once before restart.")
    payload = scheduled[0]["payload"]
    if payload.get("delay_seconds") != TIMER_SECONDS or not payload.get("timer_id") or not payload.get("fire_at"):
        raise RuntimeError(f"Unexpected TimerScheduled payload: {payload!r}")
    print(
        json.dumps(
            {
                "phase": "pending_before_worker_restart",
                "workflow_id": workflow_id,
                "run_id": execution.run_id,
                "timer_id": payload["timer_id"],
                "fire_at": payload["fire_at"],
                "history_events": [event["event_type"] for event in history["events"]],
            },
            sort_keys=True,
        ),
        flush=True,
    )


async def verify(client: Client, workflow_id: str) -> None:
    worker_stopped_at = datetime.fromisoformat(required("DURABLE_WORKFLOW_WORKER_STOPPED_AT"))
    worker_restart_at = datetime.fromisoformat(required("DURABLE_WORKFLOW_WORKER_RESTART_AT"))
    if worker_stopped_at >= worker_restart_at:
        raise RuntimeError("The worker restart window is invalid.")
    handle = client.get_workflow_handle(workflow_id, workflow_type=WORKFLOW_TYPE)
    result = await handle.result(timeout=120, poll_interval=0.5)
    if (
        not isinstance(result, dict)
        or result.get("workflow_runtime") != "rust"
        or result.get("request") != workflow_id
        or result.get("timer_seconds") != TIMER_SECONDS
        or result.get("codec", {}).get("implementation") != "apache-avro"
    ):
        raise RuntimeError(f"Unexpected Rust timer result: {result!r}")

    execution = await handle.describe()
    if not execution.run_id or execution.status != "completed":
        raise RuntimeError(f"Rust timer workflow did not complete: {execution!r}")
    history = await client.get_history(workflow_id, execution.run_id)
    scheduled = timer_events(history, "TimerScheduled")
    fired = timer_events(history, "TimerFired")
    if len(scheduled) != 1 or len(fired) != 1:
        raise RuntimeError(f"Expected exactly one scheduled and fired timer: {history!r}")
    scheduled_payload, fired_payload = scheduled[0]["payload"], fired[0]["payload"]
    if scheduled_payload.get("timer_id") != fired_payload.get("timer_id"):
        raise RuntimeError("The fired timer ID does not match the scheduled timer.")
    if not fired_payload.get("fired_at") or not scheduled_payload.get("fire_at"):
        raise RuntimeError("The timer history lacks its due or fired timestamp.")
    fired_at = datetime.fromisoformat(fired_payload["fired_at"])
    if fired_at < datetime.fromisoformat(scheduled_payload["fire_at"]):
        raise RuntimeError("The durable timer fired before its recorded deadline.")
    if not worker_stopped_at <= fired_at < worker_restart_at:
        raise RuntimeError("The timer did not fire while the Rust worker was stopped.")

    event_types = [event["event_type"] for event in history["events"]]
    for event_type in ("WorkflowStarted", "TimerScheduled", "TimerFired", "WorkflowCompleted"):
        if event_type not in event_types:
            raise RuntimeError(f"Missing {event_type} in workflow history: {event_types!r}")
    if not (
        event_types.index("TimerScheduled")
        < event_types.index("TimerFired")
        < event_types.index("WorkflowCompleted")
    ):
        raise RuntimeError(f"Timer history is out of order: {event_types!r}")

    print(
        json.dumps(
            {
                "phase": "completed_after_worker_restart",
                "workflow_id": workflow_id,
                "run_id": execution.run_id,
                "timer_id": scheduled_payload["timer_id"],
                "fire_at": scheduled_payload["fire_at"],
                "fired_at": fired_payload["fired_at"],
                "worker_stopped_at": worker_stopped_at.isoformat(),
                "worker_restart_at": worker_restart_at.isoformat(),
                "history_events": event_types,
                "result": result,
            },
            sort_keys=True,
        ),
        flush=True,
    )


async def main(phase: str) -> None:
    workflow_id = required("DURABLE_WORKFLOW_TIMER_ID")
    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_CLIENT_TOKEN"),
    ) as client:
        if phase == "start":
            await start(client, workflow_id, required("DURABLE_WORKFLOW_TASK_QUEUE"))
        else:
            await verify(client, workflow_id)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("start", "verify"))
    asyncio.run(main(parser.parse_args().phase))
