from __future__ import annotations

import asyncio
import json
import os
import uuid

from durable_workflow import Client


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before starting the client.")
    return value


async def main() -> None:
    queue = required("DURABLE_WORKFLOW_TASK_QUEUE")
    marker = f"saga-{uuid.uuid4().hex[:12]}"
    directions = (
        ("rust", "rust"),
        ("rust", "php"),
        ("rust", "python"),
        ("php", "rust"),
        ("python", "rust"),
    )
    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_CLIENT_TOKEN"),
    ) as client:
        for workflow_runtime, compensation_runtime in directions:
            workflow_id = f"{marker}-{workflow_runtime}-{compensation_runtime}"
            handle = await client.start_workflow(
                workflow_type=f"sample-app.saga.{workflow_runtime}.compensate-{compensation_runtime}",
                workflow_id=workflow_id,
                task_queue=queue,
                input=[marker],
            )
            result = await handle.result(timeout=90.0, poll_interval=0.5)
            if (
                result.get("status") != "compensated"
                or result.get("workflow_runtime") != workflow_runtime
                or result.get("compensation_runtime") != compensation_runtime
                or result.get("marker") != marker
                or not result.get("initiating_failure")
            ):
                raise RuntimeError(f"{workflow_runtime}/{compensation_runtime}: unexpected result {result!r}")

            history = await handle.get_history()
            events = history["events"]
            scheduled = [
                event["payload"]["activity_type"]
                for event in events
                if event["event_type"] == "ActivityScheduled"
            ]
            expected = [
                "sample-app.saga.reserve-first",
                "sample-app.saga.reserve-second",
                "sample-app.saga.decline",
                f"sample-app.saga.{compensation_runtime}.undo-second",
                f"sample-app.saga.{compensation_runtime}.undo-first",
            ]
            if scheduled != expected:
                raise RuntimeError(f"{workflow_runtime}/{compensation_runtime}: unexpected activity order {scheduled!r}")
            completed = [
                event["payload"]["activity_type"]
                for event in events
                if event["event_type"] == "ActivityCompleted"
            ]
            expected_completed = [expected[0], expected[1], expected[3], expected[4]]
            if completed != expected_completed:
                raise RuntimeError(f"{workflow_runtime}/{compensation_runtime}: unexpected completed activities {completed!r}")
            failed = [
                event["payload"]["activity_type"]
                for event in events
                if event["event_type"] == "ActivityFailed"
            ]
            if failed != ["sample-app.saga.decline"]:
                raise RuntimeError(f"{workflow_runtime}/{compensation_runtime}: unexpected failed activities {failed!r}")
            if events[-1]["event_type"] != "WorkflowCompleted":
                raise RuntimeError(f"{workflow_runtime}/{compensation_runtime}: workflow did not complete after compensation")

            print(json.dumps({
                "workflow_id": workflow_id,
                "run_id": handle.run_id,
                "workflow_runtime": workflow_runtime,
                "compensation_runtime": compensation_runtime,
                "scheduled_activities": scheduled,
                "completed_activities": completed,
                "failed_activities": failed,
                "result": result,
            }, sort_keys=True), flush=True)

    print("5/5 Rust-involving saga compensation directions completed", flush=True)


if __name__ == "__main__":
    asyncio.run(main())
