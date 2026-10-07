"""Exercise published PHP/Python/Rust workflow timers through the public API."""

from __future__ import annotations

import argparse
import asyncio
import json
import os
from datetime import datetime, timezone

from durable_workflow import Client


RUNTIMES = ("php", "python", "rust")
TIMER_SECONDS = 30


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before running the SDK timer experiment.")
    return value


def events(history: dict, event_type: str) -> list[dict]:
    return [event for event in history["events"] if event["event_type"] == event_type]


def timestamp(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def emit(**record) -> None:
    print(json.dumps(record, sort_keys=True), flush=True)


def original_pending(runtime: str, scenario: str) -> dict:
    records = [json.loads(line) for line in required("DURABLE_WORKFLOW_TIMER_PENDING").splitlines()]
    matches = [record for record in records if record.get("phase") == "pending"
               and record.get("runtime") == runtime and record.get("scenario") == scenario]
    if len(matches) != 1:
        raise RuntimeError("Expected exactly one original pending observation for this cell.")
    return matches[0]


def check_original(runtime: str, scenario: str, execution, scheduled: dict) -> None:
    original = original_pending(runtime, scenario)
    if (execution.workflow_id != original["workflow_id"] or execution.run_id != original["run_id"]
            or scheduled["payload"] != original["timer"]):
        raise RuntimeError("Run identity or original scheduled timer changed after the interruption.")


async def observe(client: Client, runtime: str, scenario: str):
    workflow_id = f"{required('DURABLE_WORKFLOW_TIMER_ID')}-{scenario}-{runtime}"
    handle = client.get_workflow_handle(workflow_id, workflow_type=f"polyglot.{runtime}.timer")
    execution = await handle.describe()
    if not execution.run_id:
        raise RuntimeError(f"No run ID for {workflow_id}.")
    history = await client.get_history(workflow_id, execution.run_id)
    return handle, execution, history


async def pending(client: Client, runtime: str, scenario: str) -> None:
    workflow_id = f"{required('DURABLE_WORKFLOW_TIMER_ID')}-{scenario}-{runtime}"
    await client.start_workflow(
        workflow_type=f"polyglot.{runtime}.timer",
        workflow_id=workflow_id,
        task_queue=f"polyglot-{runtime}",
        input=[workflow_id],
    )
    deadline = asyncio.get_running_loop().time() + 20
    while True:
        handle, execution, history = await observe(client, runtime, scenario)
        scheduled = events(history, "TimerScheduled")
        if scheduled:
            break
        if execution.status in ("failed", "cancelled", "terminated", "completed"):
            raise RuntimeError(f"{runtime} closed before scheduling its timer: {history!r}")
        if asyncio.get_running_loop().time() >= deadline:
            raise TimeoutError(f"{runtime} did not schedule its durable timer.")
        await asyncio.sleep(0.25)
    if len(scheduled) != 1 or events(history, "TimerFired") or execution.status != "waiting":
        raise RuntimeError(f"Expected one pending timer and public waiting status: {history!r}")
    timer = scheduled[0]["payload"]
    if timer.get("delay_seconds") != TIMER_SECONDS or not timer.get("timer_id") or not timer.get("fire_at"):
        raise RuntimeError(f"Invalid timer: {timer!r}")
    if timestamp(timer["fire_at"]) <= datetime.now(timezone.utc):
        raise RuntimeError("The pending timer's deadline has already passed.")
    emit(phase="pending", runtime=runtime, scenario=scenario, workflow_id=workflow_id,
         run_id=execution.run_id, status=execution.status, timer=timer)
    if scenario == "cancellation":
        first = await handle.request_cancellation(reason="timer conformance", cleanup_timeout_seconds=20)
        duplicate = await handle.request_cancellation(reason="duplicate request", cleanup_timeout_seconds=60)
        original, repeated = first["cancellation_request"], duplicate["cancellation_request"]
        if (first["duplicate"] or not duplicate["duplicate"]
                or original["request_id"] != repeated["request_id"]
                or original["cleanup_deadline_at"] != repeated["cleanup_deadline_at"]):
            raise RuntimeError(f"Cancellation identity or original deadline changed: {first!r}, {duplicate!r}")
        emit(phase="cancellation_requested", runtime=runtime, workflow_id=workflow_id,
             request_id=original["request_id"], cleanup_deadline_at=original["cleanup_deadline_at"])


async def fired(client: Client, runtime: str, scenario: str) -> None:
    deadline = asyncio.get_running_loop().time() + 60
    while True:
        _, execution, history = await observe(client, runtime, scenario)
        if events(history, "TimerFired"):
            if events(history, "WorkflowCompleted"):
                raise RuntimeError("Workflow completed while its SDK worker should be killed.")
            emit(phase="fired_without_worker", runtime=runtime, scenario=scenario,
                 workflow_id=execution.workflow_id, run_id=execution.run_id)
            return
        if asyncio.get_running_loop().time() >= deadline:
            raise TimeoutError(f"{runtime} timer did not fire while worker was absent.")
        await asyncio.sleep(0.25)


async def verify(client: Client, runtime: str, scenario: str) -> None:
    handle, execution, history = await observe(client, runtime, scenario)
    if scenario == "cancellation":
        deadline = asyncio.get_running_loop().time() + 60
        while True:
            scheduled = events(history, "TimerScheduled")
            due = timestamp(scheduled[0]["payload"]["fire_at"])
            if execution.status == "cancelled" and datetime.now(timezone.utc) > due:
                break
            if execution.status in ("failed", "terminated", "completed"):
                raise RuntimeError(f"Unexpected cancellation outcome: {history!r}")
            if asyncio.get_running_loop().time() >= deadline:
                raise TimeoutError(f"{runtime} cancellation did not converge.")
            await asyncio.sleep(0.25)
            handle, execution, history = await observe(client, runtime, scenario)
        for event_type in ("TimerScheduled", "TimerCancelled", "CooperativeCancellationRequested",
                           "CooperativeCancellationDelivered", "WorkflowCancelled"):
            if len(events(history, event_type)) != 1:
                raise RuntimeError(f"Expected one {event_type}: {history!r}")
        if events(history, "TimerFired") or events(history, "WorkflowCompleted"):
            raise RuntimeError(f"Cancelled timer fired or workflow completed: {history!r}")
        check_original(runtime, scenario, execution, scheduled[0])
        cancelled = events(history, "TimerCancelled")[0]["payload"]
        if cancelled["timer_id"] != scheduled[0]["payload"]["timer_id"]:
            raise RuntimeError("Cancelled timer identity differs from its scheduled identity.")
        result = None
    else:
        result = await handle.result(timeout=120, poll_interval=0.25)
        handle, execution, history = await observe(client, runtime, scenario)
        expected = {"workflow_runtime": runtime, "request": execution.workflow_id, "timer_seconds": TIMER_SECONDS}
        if not isinstance(result, dict) or any(result.get(key) != value for key, value in expected.items()):
            raise RuntimeError(f"Unexpected {runtime} result: {result!r}")
        if execution.status != "completed":
            raise RuntimeError(f"Workflow did not complete: {execution!r}")
        for event_type in ("TimerScheduled", "TimerFired", "WorkflowCompleted"):
            if len(events(history, event_type)) != 1:
                raise RuntimeError(f"Expected one {event_type}: {history!r}")
        scheduled = events(history, "TimerScheduled")[0]
        fired_event = events(history, "TimerFired")[0]
        check_original(runtime, scenario, execution, scheduled)
        if scheduled["payload"]["timer_id"] != fired_event["payload"]["timer_id"]:
            raise RuntimeError("Fired timer identity differs from its scheduled identity.")
        if timestamp(fired_event["payload"]["fired_at"]) < timestamp(scheduled["payload"]["fire_at"]):
            raise RuntimeError("Timer fired before its original deadline.")
        types = [event["event_type"] for event in history["events"]]
        if not types.index("TimerScheduled") < types.index("TimerFired") < types.index("WorkflowCompleted"):
            raise RuntimeError(f"Timer history is out of order: {types!r}")
        if scenario == "worker-restart":
            stopped = timestamp(required("DURABLE_WORKFLOW_WORKER_STOPPED_AT"))
            restarted = timestamp(required("DURABLE_WORKFLOW_WORKER_RESTART_AT"))
            if not stopped <= timestamp(fired_event["payload"]["fired_at"]) < restarted:
                raise RuntimeError("Timer did not fire while the SDK worker was absent.")
        if scenario == "server-restart":
            stopped = timestamp(required("DURABLE_WORKFLOW_SERVER_STOPPED_AT"))
            restarted = timestamp(required("DURABLE_WORKFLOW_SERVER_RESTART_AT"))
            due = timestamp(scheduled["payload"]["fire_at"])
            if not stopped < due < restarted:
                raise RuntimeError("Server downtime did not cross the original timer deadline.")
            if timestamp(fired_event["payload"]["fired_at"]) < restarted:
                raise RuntimeError("Timer fired before Server restart began.")
    emit(phase="verified", runtime=runtime, scenario=scenario, workflow_id=execution.workflow_id,
         run_id=execution.run_id, status=execution.status, result=result,
         interruption={key: required(f"DURABLE_WORKFLOW_{key.upper()}")
                       for key in ({"worker-restart": ("worker_stopped_at", "worker_restart_at"),
                                    "server-restart": ("server_stopped_at", "server_restart_at")}
                                   .get(scenario, ()))},
         history_events=[event["event_type"] for event in history["events"]],
         timer_events=[event for event in history["events"] if event["event_type"].startswith("Timer")])


async def main(phase: str, scenario: str) -> None:
    async with Client(
        required("DURABLE_WORKFLOW_SERVER_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_AUTH_TOKEN"),
    ) as client:
        action = {"start": pending, "fired": fired, "verify": verify}[phase]
        await asyncio.gather(*(action(client, runtime, scenario) for runtime in RUNTIMES))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("start", "fired", "verify"))
    parser.add_argument("scenario", choices=("completion", "worker-restart", "server-restart", "cancellation"))
    args = parser.parse_args()
    asyncio.run(main(args.phase, args.scenario))
