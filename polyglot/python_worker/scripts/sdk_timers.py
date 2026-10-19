"""Exercise published PHP/Python/Rust workflow timers through the public API."""

from __future__ import annotations

import argparse
import asyncio
import json
import os
from datetime import datetime, timezone
from urllib.request import Request, urlopen

from durable_workflow import Client


RUNTIMES = ("php", "python", "rust")
TIMER_SECONDS = 30
CONCURRENT_SECONDS = [60, 30, 45]
CONCURRENT_SCENARIOS = {
    "concurrent": "completion",
    "concurrent-worker-restart": "worker-restart",
    "concurrent-server-restart": "server-restart",
    "concurrent-cancellation": "cancellation",
}


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before running the SDK timer experiment.")
    return value


def events(history: dict, event_type: str) -> list[dict]:
    return [event for event in history["events"] if event["event_type"] == event_type]


def timestamp(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def same_json_value(left, right) -> bool:
    # Python equality conflates booleans, integers and floats, including in nested paths.
    return (json.dumps(left, sort_keys=True, allow_nan=False)
            == json.dumps(right, sort_keys=True, allow_nan=False))


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
            or not same_json_value(scheduled["payload"], original["timer"])):
        raise RuntimeError("Run identity or original scheduled timer changed after the interruption.")


async def observe(client: Client, runtime: str, scenario: str):
    workflow_id = f"{required('DURABLE_WORKFLOW_TIMER_ID')}-{scenario}-{runtime}"
    suffix = "concurrent-timers" if scenario in CONCURRENT_SCENARIOS else "timer"
    handle = client.get_workflow_handle(workflow_id, workflow_type=f"polyglot.{runtime}.{suffix}")
    execution = await handle.describe()
    if not execution.run_id:
        raise RuntimeError(f"No run ID for {workflow_id}.")
    history = await client.get_history(workflow_id, execution.run_id)
    return handle, execution, history


async def pending(client: Client, runtime: str, scenario: str) -> None:
    workflow_id = f"{required('DURABLE_WORKFLOW_TIMER_ID')}-{scenario}-{runtime}"
    concurrent = scenario in CONCURRENT_SCENARIOS
    suffix = "concurrent-timers" if concurrent else "timer"
    delays = CONCURRENT_SECONDS if concurrent else [TIMER_SECONDS]
    await client.start_workflow(
        workflow_type=f"polyglot.{runtime}.{suffix}",
        workflow_id=workflow_id,
        task_queue=f"polyglot-{runtime}",
        input=[workflow_id],
    )
    deadline = asyncio.get_running_loop().time() + 20
    while True:
        handle, execution, history = await observe(client, runtime, scenario)
        scheduled = events(history, "TimerScheduled")
        if len(scheduled) >= len(delays) and execution.status == "waiting":
            break
        if execution.status in ("failed", "cancelled", "terminated", "completed"):
            raise RuntimeError(f"{runtime} closed before scheduling its timer: {history!r}")
        if asyncio.get_running_loop().time() >= deadline:
            raise TimeoutError(f"{runtime} did not schedule its durable timer.")
        await asyncio.sleep(0.25)
    if len(scheduled) != len(delays) or events(history, "TimerFired") or execution.status != "waiting":
        raise RuntimeError(f"Expected {len(delays)} pending timers and public waiting status: {history!r}")
    timers = [item["payload"] for item in scheduled]
    for timer, delay in zip(timers, delays, strict=True):
        if timer.get("delay_seconds") != delay or not timer.get("timer_id") or not timer.get("fire_at"):
            raise RuntimeError(f"Invalid timer: {timer!r}")
        if timestamp(timer["fire_at"]) <= datetime.now(timezone.utc):
            raise RuntimeError("The pending timer's deadline has already passed.")
    if len({timer["timer_id"] for timer in timers}) != len(delays):
        raise RuntimeError("Pending timer identities are not distinct.")
    if concurrent:
        check_parallel_timers(timers)
    observation = {"timers": timers} if concurrent else {"timer": timers[0]}
    emit(phase="pending", runtime=runtime, scenario=scenario, workflow_id=workflow_id,
         run_id=execution.run_id, status=execution.status, **observation)
    if CONCURRENT_SCENARIOS.get(scenario, scenario) == "cancellation":
        first = await handle.request_cancellation(reason="timer conformance", cleanup_timeout_seconds=20)
        duplicate = await handle.request_cancellation(reason="duplicate request", cleanup_timeout_seconds=60)
        original, repeated = first["cancellation_request"], duplicate["cancellation_request"]
        if (first["duplicate"] or not duplicate["duplicate"]
                or original["request_id"] != repeated["request_id"]
                or original["cleanup_deadline_at"] != repeated["cleanup_deadline_at"]):
            raise RuntimeError(f"Cancellation identity or original deadline changed: {first!r}, {duplicate!r}")
        emit(phase="cancellation_requested", runtime=runtime, scenario=scenario,
             workflow_id=workflow_id, run_id=execution.run_id,
             request_id=original["request_id"], cleanup_deadline_at=original["cleanup_deadline_at"])


async def fired(client: Client, runtime: str, scenario: str) -> None:
    concurrent = scenario in CONCURRENT_SCENARIOS
    deadline = asyncio.get_running_loop().time() + (90 if concurrent else 60)
    while True:
        _, execution, history = await observe(client, runtime, scenario)
        resolved = events(history, "TimerFired")
        if concurrent:
            original = original_parallel_timers(runtime, scenario, execution, history)
            check_resolved_members(original, resolved, "fired", complete=False)
        if resolved and events(history, "WorkflowCompleted"):
            raise RuntimeError("Workflow completed while its SDK worker should be killed.")
        if resolved and (not concurrent or len(resolved) == len(CONCURRENT_SECONDS)):
            emit(phase="fired_without_worker", runtime=runtime, scenario=scenario,
                 workflow_id=execution.workflow_id, run_id=execution.run_id, timer_events=resolved)
            return
        if asyncio.get_running_loop().time() >= deadline:
            raise TimeoutError(f"{runtime} timer did not fire while worker was absent.")
        await asyncio.sleep(0.25)


def check_parallel_timers(timers: list[dict]) -> None:
    if len({timestamp(timer["fire_at"]) for timer in timers}) != 3:
        raise RuntimeError("Concurrent timers must have distinct original deadlines.")
    group_id = timers[0].get("parallel_group_id")
    base = timers[0].get("parallel_group_base_sequence")
    if not isinstance(group_id, str) or not group_id or type(base) is not int:
        raise RuntimeError("Timers do not describe one original parallel barrier.")
    for index, timer in enumerate(timers):
        expected = {"parallel_group_id": group_id, "parallel_group_kind": "timer",
                    "parallel_group_base_sequence": base, "parallel_group_size": 3,
                    "parallel_group_index": index, "sequence": base + index}
        if (timer.get("parallel_group_mode", "all") != "all"
                or any(type(timer.get(key)) is not type(value) or timer.get(key) != value
                       for key, value in expected.items())):
            raise RuntimeError("Timer members do not belong to one all/parallel barrier.")


def check_timer_metadata(original: dict, resolved: dict, outcome: str) -> None:
    fields = ("sequence", "delay_seconds", "fire_at", "parallel_group_id", "parallel_group_kind",
              "parallel_group_base_sequence", "parallel_group_size", "parallel_group_index",
              "parallel_group_mode", "parallel_group_path")
    original_fields = {field: original.get(field) for field in fields}
    resolved_fields = {field: resolved.get(field) for field in fields}
    if not same_json_value(resolved_fields, original_fields):
        raise RuntimeError(f"{outcome.capitalize()} timer metadata differs from its original scheduled member.")
    if outcome == "fired" and timestamp(resolved["fired_at"]) < timestamp(original["fire_at"]):
        raise RuntimeError("A concurrent timer fired before its original deadline.")


def original_parallel_timers(runtime: str, scenario: str, execution, history: dict) -> list[dict]:
    scheduled = events(history, "TimerScheduled")
    original = original_pending(runtime, scenario)
    if (execution.workflow_id != original["workflow_id"] or execution.run_id != original["run_id"]
            or not same_json_value([item["payload"] for item in scheduled], original["timers"])):
        raise RuntimeError("Run identity or original scheduled timers changed.")
    timers = [item["payload"] for item in scheduled]
    if [timer["delay_seconds"] for timer in timers] != CONCURRENT_SECONDS:
        raise RuntimeError("Authored concurrent timer durations changed.")
    check_parallel_timers(timers)
    return timers


def check_resolved_members(timers: list[dict], resolved: list[dict], outcome: str, *, complete: bool = True) -> None:
    members = {timer["timer_id"]: timer for timer in timers}
    identities = [item["payload"]["timer_id"] for item in resolved]
    if (len(members) != 3 or len(set(identities)) != len(identities) or not set(identities) <= set(members)
            or (complete and set(identities) != set(members))):
        raise RuntimeError(f"Scheduled and {outcome} timer identities differ or repeat.")
    for item in resolved:
        payload = item["payload"]
        check_timer_metadata(members[payload["timer_id"]], payload, outcome)


def check_interruption(scenario: str, scheduled: dict, fired: dict) -> None:
    lifecycle = CONCURRENT_SCENARIOS.get(scenario, scenario)
    if lifecycle == "worker-restart":
        stopped = timestamp(required("DURABLE_WORKFLOW_WORKER_STOPPED_AT"))
        restarted = timestamp(required("DURABLE_WORKFLOW_WORKER_RESTART_AT"))
        if not stopped <= timestamp(fired["fired_at"]) < restarted:
            raise RuntimeError("Timer did not fire while the SDK worker was absent.")
    if lifecycle == "server-restart":
        stopped = timestamp(required("DURABLE_WORKFLOW_SERVER_STOPPED_AT"))
        restarted = timestamp(required("DURABLE_WORKFLOW_SERVER_RESTART_AT"))
        if not stopped < timestamp(scheduled["fire_at"]) < restarted:
            raise RuntimeError("Server downtime did not cross the original timer deadline.")
        if timestamp(fired["fired_at"]) < restarted:
            raise RuntimeError("Timer fired before Server restart began.")


def interruption_record(scenario: str) -> dict:
    fields = {"worker-restart": ("worker_stopped_at", "worker_restart_at"),
              "server-restart": ("server_stopped_at", "server_restart_at")}
    return {key: required(f"DURABLE_WORKFLOW_{key.upper()}")
            for key in fields.get(CONCURRENT_SCENARIOS.get(scenario, scenario), ())}


async def cancelled_parallel_history(client: Client, runtime: str, scenario: str):
    original = original_pending(runtime, scenario)
    due = max(timestamp(timer["fire_at"]) for timer in original["timers"])
    deadline = asyncio.get_running_loop().time() + 90
    while True:
        handle, execution, history = await observe(client, runtime, scenario)
        original_parallel_timers(runtime, scenario, execution, history)
        if execution.status == "cancelled" and datetime.now(timezone.utc) > due:
            return handle, execution, history
        if execution.status in ("failed", "terminated", "completed"):
            raise RuntimeError(f"Unexpected concurrent cancellation outcome: {history!r}")
        if asyncio.get_running_loop().time() >= deadline:
            raise TimeoutError(f"{runtime} concurrent cancellation did not converge beyond every original deadline.")
        await asyncio.sleep(0.25)


def check_cancelled_parallel(history: dict, timers: list[dict]) -> None:
    for kind in ("CooperativeCancellationRequested", "CooperativeCancellationDelivered", "WorkflowCancelled"):
        if len(events(history, kind)) != 1:
            raise RuntimeError(f"Expected one {kind}: {history!r}")
    if events(history, "TimerFired") or events(history, "WorkflowCompleted"):
        raise RuntimeError("Cancelled concurrent timers fired or the workflow completed.")
    check_resolved_members(timers, events(history, "TimerCancelled"), "cancelled")
    kinds = [item["event_type"] for item in history["events"]]
    if (max(index for index, kind in enumerate(kinds) if kind == "TimerScheduled") >= kinds.index("TimerCancelled")
            or max(index for index, kind in enumerate(kinds) if kind == "TimerCancelled") >= kinds.index("WorkflowCancelled")):
        raise RuntimeError("Concurrent cancellation history is out of order.")


def check_completed_parallel(history: dict, timers: list[dict], scenario: str) -> None:
    fired = events(history, "TimerFired")
    if len(events(history, "WorkflowCompleted")) != 1:
        raise RuntimeError(f"Expected one concurrent workflow completion: {history!r}")
    check_resolved_members(timers, fired, "fired")
    kinds = [item["event_type"] for item in history["events"]]
    if max(index for index, kind in enumerate(kinds) if kind == "TimerScheduled") >= kinds.index("TimerFired"):
        raise RuntimeError("All timers must be scheduled before the first fire.")
    if kinds.index("WorkflowCompleted") <= max(index for index, kind in enumerate(kinds) if kind == "TimerFired"):
        raise RuntimeError("Workflow completed before every timer fired.")
    members = {timer["timer_id"]: timer for timer in timers}
    for item in fired:
        check_interruption(scenario, members[item["payload"]["timer_id"]], item["payload"])


async def verify_concurrent(client: Client, runtime: str, scenario: str) -> None:
    if CONCURRENT_SCENARIOS[scenario] == "cancellation":
        _, execution, history = await cancelled_parallel_history(client, runtime, scenario)
        result = None
    else:
        handle, _, _ = await observe(client, runtime, scenario)
        result = await handle.result(timeout=120, poll_interval=0.25)
        _, execution, history = await observe(client, runtime, scenario)
        expected = {"workflow_runtime": runtime, "request": execution.workflow_id, "timer_seconds": CONCURRENT_SECONDS}
        if execution.status != "completed" or result != expected:
            raise RuntimeError(f"Unexpected concurrent timer outcome: {execution!r}, {result!r}")
    timers = original_parallel_timers(runtime, scenario, execution, history)
    if CONCURRENT_SCENARIOS[scenario] == "cancellation":
        check_cancelled_parallel(history, timers)
    else:
        check_completed_parallel(history, timers, scenario)
    emit(phase="verified", runtime=runtime, scenario=scenario, workflow_id=execution.workflow_id,
         run_id=execution.run_id, status=execution.status, result=result,
         interruption=interruption_record(scenario),
         history_events=[item["event_type"] for item in history["events"]],
         timer_events=[item for item in history["events"] if item["event_type"].startswith("Timer")])


async def verify(client: Client, runtime: str, scenario: str) -> None:
    if scenario in CONCURRENT_SCENARIOS:
        await verify_concurrent(client, runtime, scenario)
        return
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
        check_interruption(scenario, scheduled["payload"], fired_event["payload"])
    emit(phase="verified", runtime=runtime, scenario=scenario, workflow_id=execution.workflow_id,
         run_id=execution.run_id, status=execution.status, result=result,
         interruption=interruption_record(scenario),
         history_events=[event["event_type"] for event in history["events"]],
         timer_events=[event for event in history["events"] if event["event_type"].startswith("Timer")])


async def main(phase: str, scenario: str) -> None:
    async with Client(
        required("DURABLE_WORKFLOW_SERVER_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_AUTH_TOKEN"),
    ) as client:
        if phase == "diagnose":
            workers = []
            for suffix in ("", "?status=stale"):
                request = Request(required("DURABLE_WORKFLOW_SERVER_URL") + "/api/workers" + suffix,
                    headers={"Authorization": "Bearer " + required("DURABLE_WORKFLOW_AUTH_TOKEN"),
                             "Accept": "application/json", "X-Namespace": required("DURABLE_WORKFLOW_NAMESPACE"),
                             "X-Durable-Workflow-Control-Plane-Version": "2"})
                with urlopen(request, timeout=30) as response:
                    rows = json.loads(response.read())["workers"]
                fields = ("worker_id", "task_queue", "runtime", "sdk_version", "status",
                          "last_heartbeat_at", "heartbeat_interval_seconds")
                workers.extend({key: row.get(key) for key in fields} for row in rows)
            emit(phase="worker_diagnostics", scenario=scenario, workers=workers)
            for runtime in RUNTIMES:
                _, execution, history = await observe(client, runtime, scenario)
                emit(phase="run_diagnostics", scenario=scenario, runtime=runtime,
                     workflow_id=execution.workflow_id, run_id=execution.run_id, status=execution.status,
                     history_events=[event["event_type"] for event in history["events"]])
            return
        action = {"start": pending, "fired": fired, "verify": verify}[phase]
        await asyncio.gather(*(action(client, runtime, scenario) for runtime in RUNTIMES))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("start", "fired", "verify", "diagnose"))
    parser.add_argument("scenario", choices=("completion", "worker-restart", "server-restart", "cancellation", *CONCURRENT_SCENARIOS))
    args = parser.parse_args()
    asyncio.run(main(args.phase, args.scenario))
