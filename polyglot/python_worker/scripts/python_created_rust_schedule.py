"""Prove a Python-created schedule fires into a published Rust SDK worker."""

from __future__ import annotations

import asyncio
import json
import os
import uuid

from durable_workflow import Client, ScheduleAction, ScheduleSpec


WORKFLOW_TYPE = "polyglot.rust.greeter"
REQUIRED_WORKFLOW_EVENTS = (
    "WorkflowStarted",
    "ScheduleTriggered",
    "ActivityScheduled",
    "ActivityCompleted",
    "WorkflowCompleted",
)


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before running the schedule experiment.")
    return value


async def main() -> None:
    queue = required("DURABLE_WORKFLOW_TASK_QUEUE")
    marker = f"python-schedule-rust-{uuid.uuid4().hex[:12]}"
    schedule_id = marker
    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_CLIENT_TOKEN"),
    ) as client:
        schedule = await client.create_schedule(
            schedule_id=schedule_id,
            spec=ScheduleSpec(intervals=[{"every": "PT10S"}], timezone="UTC"),
            action=ScheduleAction(
                workflow_type=WORKFLOW_TYPE,
                task_queue=queue,
                input=[marker],
            ),
        )
        try:
            deadline = asyncio.get_running_loop().time() + 120
            while True:
                description = await schedule.describe()
                if description.fires_count >= 1 and description.latest_workflow_instance_id:
                    break
                if asyncio.get_running_loop().time() >= deadline:
                    raise TimeoutError("The automatic schedule fire did not start a workflow.")
                await asyncio.sleep(0.5)

            workflow_id = description.latest_workflow_instance_id
            handle = client.get_workflow_handle(workflow_id, workflow_type=WORKFLOW_TYPE)
            result = await handle.result(timeout=90, poll_interval=0.5)
            if (
                not isinstance(result, dict)
                or result.get("workflow_runtime") != "rust"
                or result.get("activity_runtime") != "rust"
                or result.get("request") != marker
                or not isinstance(result.get("echo"), dict)
                or result["echo"].get("runtime") != "rust"
                or result["echo"].get("value") != marker
            ):
                raise RuntimeError(f"Unexpected Rust workflow result: {result!r}")

            execution = await handle.describe()
            if not execution.run_id or execution.status != "completed":
                raise RuntimeError(f"Scheduled workflow did not complete: {execution!r}")
            workflow_history = await client.get_history(workflow_id, execution.run_id)
            workflow_events = [
                event["event_type"] for event in workflow_history["events"]
            ]
            for event_type in REQUIRED_WORKFLOW_EVENTS:
                if event_type not in workflow_events:
                    raise RuntimeError(
                        f"Missing {event_type} in workflow history: {workflow_events!r}"
                    )
            if workflow_events.index("ActivityScheduled") >= workflow_events.index(
                "ActivityCompleted"
            ):
                raise RuntimeError(f"Activity history is out of order: {workflow_events!r}")

            schedule_history = await schedule.history()
            triggered = [
                event
                for event in schedule_history.events
                if event.event_type == "ScheduleTriggered"
                and event.workflow_instance_id == workflow_id
                and event.workflow_run_id == execution.run_id
            ]
            if not triggered:
                raise RuntimeError(
                    "Schedule history has no trigger linked to the completed Rust run."
                )

            print(
                json.dumps(
                    {
                        "schedule_creator": "python",
                        "workflow_runtime": "rust",
                        "schedule_id": schedule_id,
                        "workflow_id": workflow_id,
                        "run_id": execution.run_id,
                        "fires_count": description.fires_count,
                        "schedule_events": [
                            event.event_type for event in schedule_history.events
                        ],
                        "workflow_events": workflow_events,
                        "result": result,
                    },
                    sort_keys=True,
                ),
                flush=True,
            )
            print("Python-created schedule -> Rust worker completed", flush=True)
        finally:
            await schedule.delete()


if __name__ == "__main__":
    asyncio.run(main())
