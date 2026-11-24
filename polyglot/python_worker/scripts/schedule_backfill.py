"""Use the published Python SDK to backfill and update schedules."""

import argparse
import asyncio
import json
from dataclasses import asdict

from durable_workflow import Client, ScheduleAction, ScheduleSpec

from python_created_rust_schedule import QUEUE_VARIABLES, required


RANGES = {"initial": (1, 3), "repeat": (1, 3), "timeout": (3, 4), "recover": (3, 5)}


async def main(phase: str, runtime: str, hour: str) -> None:
    schedule_id = f"python-backfill-{runtime}"
    waiting = phase == "timeout"
    workflow = "signal-query" if waiting else "greeter"
    if waiting:
        request = {"workflow_runtime": runtime}
    else:
        request = schedule_id if runtime == "rust" else {"name": schedule_id, "locale": "en"}
    action = ScheduleAction(
        workflow_type=f"polyglot.{runtime}.{workflow}",
        task_queue=required(QUEUE_VARIABLES[runtime]),
        input=[request],
        execution_timeout_seconds=10 if waiting else 60,
    )
    async with Client(
        required("DURABLE_WORKFLOW_SERVER_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_AUTH_TOKEN"),
    ) as client:
        if phase == "initial":
            schedule = await client.create_schedule(
                schedule_id=schedule_id,
                spec=ScheduleSpec(cron_expressions=[f"0 {hour} * * *"], timezone="UTC"),
                action=action,
                overlap_policy="skip",
            )
        else:
            schedule = client.get_schedule_handle(schedule_id)
        if phase in {"timeout", "recover"}:
            await schedule.update(action=action)
        first_day, end_day = RANGES[phase]
        result = await schedule.backfill(
            start_time=f"2020-01-{first_day:02}T{hour}:00:00Z",
            end_time=f"2020-01-{end_day:02}T{hour}:00:00Z",
        )
    print(json.dumps({**asdict(result), "phase": phase, "schedule_creator": "python", "workflow_runtime": runtime}))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=RANGES)
    parser.add_argument("runtime", choices=QUEUE_VARIABLES)
    parser.add_argument("hour", choices=tuple(f"{hour:02}" for hour in range(24)))
    asyncio.run(main(**vars(parser.parse_args())))
