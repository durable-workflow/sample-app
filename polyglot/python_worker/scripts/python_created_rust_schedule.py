"""Prove a Python-created schedule fires into a published SDK worker."""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import uuid

from durable_workflow import Client, ScheduleAction, ScheduleSpec


QUEUE_VARIABLES = {
    "php": "POLYGLOT_PHP_TASK_QUEUE",
    "python": "POLYGLOT_PY_TASK_QUEUE",
    "rust": "DURABLE_WORKFLOW_TASK_QUEUE",
}
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


def activity_matches(runtime: str, result: dict, marker: str) -> bool:
    match runtime, result:
        case "php", {
            "activity_runtime": "php",
            "php_marker": {"name": name},
            "php_description": {"runtime": "php"},
        }:
            return name == marker
        case "python", {
            "greeting": {"language": "python", "greeting": greeting},
            "summary": {"language": "python"},
        }:
            return greeting == f"hello, {marker}"
        case "rust", {
            "activity_runtime": "rust",
            "echo": {"runtime": "rust", "value": value},
        }:
            return value == marker
    return False


async def main(runtime: str, keep_schedule: bool) -> None:
    workflow_type = f"polyglot.{runtime}.greeter"
    queue = required(QUEUE_VARIABLES[runtime])
    marker = f"python-schedule-{runtime}-{uuid.uuid4().hex[:12]}"
    request = marker if runtime == "rust" else {"name": marker, "locale": "en"}
    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_CLIENT_TOKEN"),
    ) as client:
        schedule = await client.create_schedule(
            schedule_id=marker,
            spec=ScheduleSpec(intervals=[{"every": "PT10S"}], timezone="UTC"),
            action=ScheduleAction(
                workflow_type=workflow_type,
                task_queue=queue,
                input=[request],
            ),
            max_runs=12 if keep_schedule else None,
        )
        verified = False
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
            handle = client.get_workflow_handle(workflow_id, workflow_type=workflow_type)
            result = await handle.result(timeout=90, poll_interval=0.5)
            if (
                not isinstance(result, dict)
                or result.get("workflow_runtime") != runtime
                or result.get("request") != request
                or not activity_matches(runtime, result, marker)
            ):
                raise RuntimeError(f"Unexpected {runtime} workflow result: {result!r}")

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
                    "Schedule history has no trigger linked to the completed run."
                )

            print(
                json.dumps(
                    {
                        "schedule_creator": "python",
                        "workflow_runtime": runtime,
                        "schedule_id": marker,
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
            print(f"Python-created schedule -> {runtime.capitalize()} worker completed", flush=True)
            verified = True
        finally:
            if not keep_schedule or not verified:
                await schedule.delete()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("runtime", nargs="?", default="rust", choices=QUEUE_VARIABLES)
    parser.add_argument("--keep-schedule", action="store_true", help="Retain a successful schedule for the isolated restart fixture.")
    args = parser.parse_args()
    asyncio.run(main(args.runtime, args.keep_schedule))
