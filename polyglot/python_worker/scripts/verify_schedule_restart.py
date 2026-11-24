"""Verify six ongoing schedules after a restart of the Server process."""

from __future__ import annotations

import asyncio
import json
import sys
from datetime import datetime, timedelta

from durable_workflow import Client

from python_created_rust_schedule import REQUIRED_WORKFLOW_EVENTS, required


INTERVAL = timedelta(seconds=10)
DIRECTIONS = {(creator, runtime) for creator in ("php", "python") for runtime in ("php", "python", "rust")}


def original_receipts(lines: list[str]) -> list[dict]:
    originals = [json.loads(line) for line in lines]
    directions = {(item["schedule_creator"], item["workflow_runtime"]) for item in originals}
    if len(originals) != len(DIRECTIONS) or directions != DIRECTIONS:
        raise ValueError("Expected exactly one original for each of the six SDK directions.")
    for identity in ("schedule_id", "workflow_id", "run_id"):
        if len({item[identity] for item in originals if item[identity]}) != len(DIRECTIONS):
            raise ValueError(f"The originals must have distinct, nonempty {identity} values.")
    return originals


async def verify_schedule(client: Client, original: dict, stopped: datetime, restarted: datetime) -> dict:
    schedule_id = original["schedule_id"]
    schedule = client.get_schedule_handle(schedule_id)
    original_ids = (original["workflow_id"], original["run_id"])

    deadline = asyncio.get_running_loop().time() + 45
    while True:
        page = await schedule.history()
        if page.has_more:
            raise RuntimeError(f"{schedule_id} exceeded the expected bounded audit history.")
        triggers = [event for event in page.events if event.event_type == "ScheduleTriggered"]
        if not triggers:
            raise RuntimeError(f"{schedule_id} lost its existing trigger history.")
        ids = [(event.workflow_instance_id, event.workflow_run_id) for event in triggers]
        times = [datetime.fromisoformat(event.payload["occurrence_time"]) for event in triggers]
        numbers = [event.payload["trigger_number"] for event in triggers]
        if (
            len(set(times)) != len(times)
            or len({workflow for workflow, _ in ids if workflow}) != len(ids)
            or len({run for _, run in ids if run}) != len(ids)
            or numbers != list(range(1, len(triggers) + 1))
            or any(following <= time for time, following in zip(times, times[1:]))
            or any((time - times[0]) % INTERVAL for time in times)
        ):
            raise RuntimeError(f"{schedule_id} repeated an occurrence or broke the authored cadence.")
        if original_ids not in ids or times[ids.index(original_ids)] >= stopped:
            raise RuntimeError(f"{schedule_id} lost its original pre-restart trigger.")
        fresh = [(event, time) for event, time in zip(triggers, times) if time >= restarted]
        if len(fresh) >= 2:
            pair = fresh[:2]
            if pair[1][1] - pair[0][1] != INTERVAL:
                raise RuntimeError(f"{schedule_id} skipped a cadence interval after restart.")
            break
        if asyncio.get_running_loop().time() >= deadline:
            description = await schedule.describe()
            raise TimeoutError(
                f"{schedule_id} has {len(fresh)} post-restart triggers, {description.fires_count} total fires, "
                f"{description.failures_count} failures, status {description.status}, "
                f"and next fire {description.next_fire_at}."
            )
        await asyncio.sleep(0.5)

    original_history = await client.get_history(*original_ids)
    if [event["event_type"] for event in original_history["events"]] != original["workflow_events"]:
        raise RuntimeError(f"{schedule_id} changed the original completed workflow history.")

    after = []
    workflow_type = f"polyglot.{original['workflow_runtime']}.greeter"
    for event, occurrence in pair:
        workflow_id, run_id = event.workflow_instance_id, event.workflow_run_id
        handle = client.get_workflow_handle(workflow_id, workflow_type=workflow_type, run_id=run_id)
        if await handle.result(timeout=60, poll_interval=0.5) != original["result"]:
            raise RuntimeError(f"{schedule_id} returned a different authored result after restart.")
        history = await client.get_history(workflow_id, run_id)
        events = history["events"]
        kinds = [event["event_type"] for event in events]
        markers = [event["payload"] for event in events if event["event_type"] == "ScheduleTriggered"]
        if (
            any(kinds.count(kind) != 1 for kind in REQUIRED_WORKFLOW_EVENTS if not kind.startswith("Activity"))
            or not all(kind in kinds for kind in REQUIRED_WORKFLOW_EVENTS)
            or kinds.index("ActivityScheduled") >= kinds.index("ActivityCompleted")
            or len(markers) != 1
            or markers[0].get("schedule_id") != schedule_id
            or markers[0].get("trigger_number") != event.payload["trigger_number"]
            or datetime.fromisoformat(markers[0]["occurrence_time"]) != occurrence
        ):
            raise RuntimeError(f"{schedule_id} has an invalid post-restart run history.")
        after.append({"workflow_id": workflow_id, "run_id": run_id, "occurrence_time": occurrence.isoformat()})

    return {
        "schedule_creator": original["schedule_creator"],
        "workflow_runtime": original["workflow_runtime"],
        "schedule_id": schedule_id,
        "original_workflow_id": original_ids[0],
        "original_run_id": original_ids[1],
        "after_restart": after,
    }


async def main(stopped: datetime, restarted: datetime, originals: list[dict]) -> None:
    if restarted - stopped <= INTERVAL:
        raise ValueError("Keep Server stopped for more than one full schedule interval.")
    async with Client(
        required("DURABLE_WORKFLOW_SERVER_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_AUTH_TOKEN"),
    ) as client, asyncio.timeout(100):
        results = await asyncio.gather(*(verify_schedule(client, item, stopped, restarted) for item in originals))
    for result in results:
        print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    stopped, restarted = map(datetime.fromisoformat, sys.argv[1:])
    asyncio.run(main(stopped, restarted, original_receipts(sys.stdin.read().splitlines())))
