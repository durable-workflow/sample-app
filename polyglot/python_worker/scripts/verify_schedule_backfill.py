"""Check backfill, overlap, and timed-out action recovery against durable history."""

from __future__ import annotations

import asyncio
import json
import sys
from datetime import UTC, datetime, timedelta

from durable_workflow import Client

from python_created_rust_schedule import REQUIRED_WORKFLOW_EVENTS, activity_matches, required
from schedule_backfill import RANGES
from verify_schedule_restart import DIRECTIONS


EXPECTED_OUTCOMES = {
    "initial": ["started", "skipped"],
    "repeat": ["skipped", "started"],
    "timeout": ["started"],
    "recover": ["skipped", "started"],
}
EXPECTED_SKIPS = {
    "initial": ["overlap_policy_skip"],
    "repeat": ["overlap_policy_skip", "already_fired_occurrence"],
    "timeout": ["overlap_policy_skip", "already_fired_occurrence"],
    "recover": ["overlap_policy_skip", "already_fired_occurrence", "already_fired_occurrence"],
}


def phase_receipts(receipts: list[dict], phase: str) -> dict[tuple[str, str], dict]:
    items = [row for row in receipts if row["phase"] == phase]
    directions = {(row["schedule_creator"], row["workflow_runtime"]): row for row in items}
    if len(items) != len(DIRECTIONS) or directions.keys() != DIRECTIONS:
        raise ValueError(f"Expected one {phase} receipt per SDK direction.")
    for (creator, runtime), row in directions.items():
        if row["schedule_id"] != f"{creator}-backfill-{runtime}":
            raise ValueError(f"Unexpected schedule identity in the {phase} receipts.")
    return directions


async def verify_timeout(handle, client: Client, workflow_id: str, run_id: str) -> dict:
    async with asyncio.timeout(35):
        description = await handle.describe()
        while description.status in {"pending", "running", "waiting"}:
            await asyncio.sleep(0.5)
            description = await handle.describe()
    if description.status != "failed":
        raise RuntimeError(f"{workflow_id} closed with unexpected status {description.status!r}.")
    history = (await client.get_history(workflow_id, run_id))["events"]
    kinds = [event["event_type"] for event in history]
    if (
        kinds.count("WorkflowTimedOut") != 1
        or not {"SignalWaitOpened", "ConditionWaitOpened"}.intersection(kinds)
        or {"WorkflowCompleted", "ActivityScheduled"}.intersection(kinds)
    ):
        raise RuntimeError(f"{workflow_id} has the wrong timed-out run history.")
    terminal = history[kinds.index("WorkflowTimedOut")]
    started = next(event for event in history if event["event_type"] == "WorkflowStarted")
    start_time = datetime.fromisoformat(started["timestamp"])
    deadline = datetime.fromisoformat(started["payload"]["execution_deadline_at"])
    if (
        terminal["payload"]["timeout_kind"] != "execution_timeout"
        or abs(deadline - start_time - timedelta(seconds=10)) > timedelta(seconds=1)
        or datetime.fromisoformat(terminal["timestamp"]) < deadline
    ):
        raise RuntimeError(f"{workflow_id} did not respect the scheduled execution deadline.")
    return {"deadline": deadline.isoformat(), "terminal_at": terminal["timestamp"]}


async def verify_run(client: Client, receipt: dict, trigger) -> dict:
    runtime = receipt["workflow_runtime"]
    schedule_id = receipt["schedule_id"]
    workflow_id, run_id = trigger.workflow_instance_id, trigger.workflow_run_id
    if not workflow_id or not run_id:
        raise RuntimeError(f"{schedule_id} has an unlinked backfill.")
    workflow = "signal-query" if receipt["phase"] == "timeout" else "greeter"
    handle = client.get_workflow_handle(
        workflow_id, run_id=run_id, workflow_type=f"polyglot.{runtime}.{workflow}",
    )
    if receipt["phase"] == "timeout":
        result = await verify_timeout(handle, client, workflow_id, run_id)
        status = "timed_out"
    else:
        result = await handle.result(timeout=60, poll_interval=0.5)
        request = schedule_id if runtime == "rust" else {"name": schedule_id, "locale": "en"}
        if (
            not isinstance(result, dict)
            or result.get("workflow_runtime") != runtime
            or result.get("request") != request
            or not activity_matches(runtime, result, schedule_id)
        ):
            raise RuntimeError(f"{schedule_id} returned the wrong scheduled result.")
        status = "completed"
    history = (await client.get_history(workflow_id, run_id))["events"]
    kinds = [event["event_type"] for event in history]
    markers = [event["payload"] for event in history if event["event_type"] == "ScheduleTriggered"]
    if (
        len(markers) != 1
        or markers[0]["schedule_id"] != schedule_id
        or markers[0]["occurrence_time"] != trigger.payload["occurrence_time"]
        or markers[0]["trigger_number"] != trigger.payload["trigger_number"]
    ):
        raise RuntimeError(f"{schedule_id} lost the link between its audit and workflow history.")
    if status == "completed" and (
        not all(kind in kinds for kind in REQUIRED_WORKFLOW_EVENTS)
        or kinds.count("WorkflowCompleted") != 1
        or kinds.index("ActivityScheduled") >= kinds.index("ActivityCompleted")
    ):
        raise RuntimeError(f"{schedule_id} has the wrong completed run history.")
    return {
        "workflow_id": workflow_id, "run_id": run_id, "status": status,
        "occurrence_time": trigger.payload["occurrence_time"], "result": result,
    }


async def verify_schedule(client: Client, receipt: dict, previous: list[dict], hour: int) -> dict:
    schedule_id = receipt["schedule_id"]
    phase = receipt["phase"]
    first_day, end_day = RANGES[phase]
    days = range(first_day, end_day)
    results = receipt["results"]
    if (
        receipt["outcome"] != "backfill_started"
        or receipt["fires_attempted"] != len(days)
        or [row["outcome"] for row in results] != EXPECTED_OUTCOMES[phase]
        or [datetime.fromisoformat(row["fire_time"]) for row in results]
        != [datetime(2020, 1, day, hour, tzinfo=UTC) for day in days]
    ):
        raise RuntimeError(f"{schedule_id} returned unexpected backfill results: {receipt!r}")

    page = await client.get_schedule_handle(schedule_id).history()
    triggers = [event for event in page.events if event.event_type == "ScheduleTriggered"]
    reasons = [event.payload["reason"] for event in page.events if event.event_type == "ScheduleTriggerSkipped"]
    count = list(RANGES).index(phase) + 1
    started = next(row for row in results if row["outcome"] == "started")
    if (
        page.has_more
        or len(triggers) != count
        or reasons != EXPECTED_SKIPS[phase]
        or [event.payload["outcome"] for event in triggers] != ["backfilled"] * count
        or [event.payload["trigger_number"] for event in triggers] != list(range(1, count + 1))
        or [datetime.fromisoformat(event.payload["occurrence_time"]) for event in triggers]
        != [datetime(2020, 1, day, hour, tzinfo=UTC) for day in range(1, count + 1)]
        or triggers[-1].workflow_instance_id != started["workflow_id"]
    ):
        raise RuntimeError(f"{schedule_id} has an unexpected schedule audit after {phase} backfill.")

    ids = [(event.workflow_instance_id, event.workflow_run_id) for event in triggers]
    if (
        ids[:-1] != [(run["workflow_id"], run["run_id"]) for run in previous]
        or len({workflow_id for workflow_id, _ in ids}) != count
        or len({run_id for _, run_id in ids}) != count
    ):
        raise RuntimeError(f"{schedule_id} changed an original run or repeated a run identity.")
    run = await verify_run(client, receipt, triggers[-1])
    if phase == "recover":
        timed_out = previous[-1]
        old_handle = client.get_workflow_handle(timed_out["workflow_id"], run_id=timed_out["run_id"])
        if await verify_timeout(old_handle, client, timed_out["workflow_id"], timed_out["run_id"]) != timed_out["result"]:
            raise RuntimeError(f"{schedule_id} changed the original timeout while recovering.")
    return {
        "phase": "verified",
        "schedule_creator": receipt["schedule_creator"], "workflow_runtime": receipt["workflow_runtime"],
        "schedule_id": schedule_id, "skip_reasons": reasons, "runs": [*previous, run],
    }


async def main(phase: str, hour: int, receipts: list[dict]) -> None:
    selected = phase_receipts(receipts, phase)
    previous = phase_receipts(receipts, "verified") if phase != "initial" else {}
    async with Client(
        required("DURABLE_WORKFLOW_SERVER_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_AUTH_TOKEN"),
    ) as client, asyncio.timeout(90):
        results = await asyncio.gather(*(
            verify_schedule(client, receipt, previous.get(direction, {}).get("runs", []), hour)
            for direction, receipt in sorted(selected.items())
        ))
    for result in results:
        print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    phase, hour = sys.argv[1:]
    asyncio.run(main(phase, int(hour), [json.loads(line) for line in sys.stdin if line.strip()]))
