"""Verify native calendar transitions against the installed SDK and Server."""

from __future__ import annotations

import asyncio
import json
from dataclasses import dataclass
from datetime import UTC, date, datetime, timedelta
from importlib.metadata import version

from durable_workflow import Client, ScheduleAction, ScheduleSpec

from python_created_rust_schedule import required
from verify_schedule_backfill import verify_run


@dataclass(frozen=True)
class CalendarCase:
    name: str
    day: date
    hour_field: str
    start: int
    end: int
    utc_hours: tuple[int, ...]

    def at(self, hour: int, minute: int = 0) -> datetime:
        return datetime(self.day.year, self.day.month, self.day.day, hour, minute, tzinfo=UTC)

    @property
    def cron(self) -> str:
        return f"30 {self.hour_field} {self.day.day} {self.day.month} *"


def historical_transition(primary: date, alternate: date) -> date:
    # Cron matches month and day each year. Avoid dates near the current
    # calendar day so the live evaluator cannot fire during this short check.
    today = datetime.now(UTC).date()
    nearby = {(day.month, day.day) for day in (today - timedelta(days=1), today, today + timedelta(days=1))}
    return alternate if (primary.month, primary.day) in nearby else primary


def calendar_cases() -> tuple[CalendarCase, ...]:
    spring = historical_transition(date(2026, 3, 8), date(2024, 3, 10))
    fall = historical_transition(date(2026, 11, 1), date(2024, 11, 3))
    return (
        CalendarCase("spring-gap", spring, "2", 5, 9, (7,)),
        CalendarCase("daily-fold", fall, "1", 5, 7, (5,)),
        CalendarCase("daily-fold-second-pass", fall, "1", 6, 7, ()),
        CalendarCase("hourly-fold", fall, "0-23", 5, 7, (5, 6)),
    )


async def verify_case(client: Client, case: CalendarCase) -> dict:
    schedule_id = f"calendar-{case.name}"
    schedule = await client.create_schedule(
        schedule_id=schedule_id,
        spec=ScheduleSpec(cron_expressions=[case.cron], timezone="America/New_York"),
        action=ScheduleAction(
            workflow_type="polyglot.rust.greeter",
            task_queue=required("POLYGLOT_RUST_TASK_QUEUE"),
            input=[schedule_id],
        ),
        overlap_policy="allow_all",
    )
    try:
        expected = [case.at(hour, 30) for hour in case.utc_hours]
        window = {"start_time": case.at(case.start).isoformat(), "end_time": case.at(case.end).isoformat()}
        submitted = await schedule.backfill(**window)
        results = submitted.results or []
        if (
            submitted.outcome != "backfill_started"
            or submitted.fires_attempted != len(expected)
            or [datetime.fromisoformat(row["fire_time"]) for row in results] != expected
            or [row["outcome"] for row in results] != ["started"] * len(expected)
        ):
            raise RuntimeError(f"{case.name} returned unexpected calendar occurrences: {results!r}")

        page = await schedule.history()
        triggers = [event for event in page.events if event.event_type == "ScheduleTriggered"]
        if (
            page.has_more
            or [datetime.fromisoformat(event.payload["occurrence_time"]) for event in triggers] != expected
            or [event.workflow_instance_id for event in triggers] != [row["workflow_id"] for row in results]
            or len({event.workflow_run_id for event in triggers}) != len(expected)
            or any(event.payload["outcome"] != "backfilled" for event in triggers)
        ):
            raise RuntimeError(f"{case.name} has unexpected schedule history.")
        runs = [
            await verify_run(client, {"schedule_id": schedule_id, "workflow_runtime": "rust", "phase": "calendar"}, event)
            for event in triggers
        ]

        repeated = await schedule.backfill(**window)
        if (
            repeated.fires_attempted != len(expected)
            or [row["outcome"] for row in repeated.results or []] != ["skipped"] * len(expected)
            or (await schedule.describe()).fires_count != len(expected)
        ):
            raise RuntimeError(f"{case.name} changed the fire count when the window was repeated.")
        return {"case": case.name, "date": case.day.isoformat(), "cron": case.cron, "runs": runs}
    finally:
        await schedule.delete()


async def main() -> None:
    if version("durable-workflow") != required("DURABLE_WORKFLOW_PYTHON_SDK_VERSION"):
        raise RuntimeError("The calendar client does not match the published artifact tuple.")
    async with Client(
        required("DURABLE_WORKFLOW_SERVER_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_AUTH_TOKEN"),
    ) as client, asyncio.timeout(90):
        for case in calendar_cases():
            print(json.dumps(await verify_case(client, case), sort_keys=True), flush=True)


if __name__ == "__main__":
    asyncio.run(main())
