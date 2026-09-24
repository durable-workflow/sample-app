from __future__ import annotations

import asyncio
import argparse
import json
import os
import uuid

from durable_workflow import Client, WorkflowFailed


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before starting the client.")
    return value


async def main() -> None:
    parser = argparse.ArgumentParser(description="Run cross-SDK saga compensation checks.")
    parser.add_argument("--compensation-failure", action="store_true")
    args = parser.parse_args()
    queue = required("DURABLE_WORKFLOW_TASK_QUEUE")
    marker = f"{'saga-fail-compensation' if args.compensation_failure else 'saga'}-{uuid.uuid4().hex[:12]}"
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
            try:
                result = await handle.result(timeout=90.0, poll_interval=0.5)
            except WorkflowFailed as failure:
                if not args.compensation_failure or "SagaCompensation" not in (failure.exception_class or ""):
                    raise
                result = {"exception_class": failure.exception_class, "message": str(failure)}
            else:
                if args.compensation_failure:
                    raise RuntimeError(f"{workflow_runtime}/{compensation_runtime}: compensation unexpectedly succeeded")
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
            ]
            if not args.compensation_failure:
                expected.append(f"sample-app.saga.{compensation_runtime}.undo-first")
            if scheduled != expected:
                raise RuntimeError(f"{workflow_runtime}/{compensation_runtime}: unexpected activity order {scheduled!r}")
            completed = [
                event["payload"]["activity_type"]
                for event in events
                if event["event_type"] == "ActivityCompleted"
            ]
            expected_completed = [expected[0], expected[1]]
            if not args.compensation_failure:
                expected_completed.extend(expected[3:])
            if completed != expected_completed:
                raise RuntimeError(f"{workflow_runtime}/{compensation_runtime}: unexpected completed activities {completed!r}")
            failed = [
                event["payload"]["activity_type"]
                for event in events
                if event["event_type"] == "ActivityFailed"
            ]
            expected_failed = ["sample-app.saga.decline"]
            if args.compensation_failure:
                expected_failed.append(expected[3])
            if failed != expected_failed:
                raise RuntimeError(f"{workflow_runtime}/{compensation_runtime}: unexpected failed activities {failed!r}")
            terminal_event = "WorkflowFailed" if args.compensation_failure else "WorkflowCompleted"
            if events[-1]["event_type"] != terminal_event:
                raise RuntimeError(f"{workflow_runtime}/{compensation_runtime}: unexpected terminal event {events[-1]!r}")

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

    outcome = "failed as expected" if args.compensation_failure else "completed"
    print(f"5/5 Rust-involving saga compensation directions {outcome}", flush=True)


if __name__ == "__main__":
    asyncio.run(main())
