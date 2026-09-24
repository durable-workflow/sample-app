from __future__ import annotations

import argparse
import asyncio
import json
import os
import time
import uuid

from durable_workflow import Client


SIGNAL = "sample-app.saga.restart-continue"


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before starting the client.")
    return value


async def wait_for_restart_boundary(handle) -> None:
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        events = (await handle.get_history())["events"]
        first_reserved = any(
            event["event_type"] == "ActivityCompleted"
            and event["payload"].get("activity_type") == "sample-app.saga.reserve-first"
            for event in events
        )
        wait_open = any(event["event_type"] == "SignalWaitOpened" for event in events)
        if first_reserved and wait_open:
            return
        await asyncio.sleep(0.5)
    raise TimeoutError("The workflow did not reach its persisted restart boundary.")


async def main() -> None:
    parser = argparse.ArgumentParser(description="Verify Rust saga compensation after a worker restart.")
    parser.add_argument("phase", choices=("start", "signal", "verify"))
    parser.add_argument("workflow_id", nargs="?")
    parser.add_argument("--compensation-runtime", choices=("rust", "php", "python"), default="rust")
    args = parser.parse_args()
    if args.phase != "start" and not args.workflow_id:
        parser.error("signal and verify require the workflow ID printed by start")
    queue = required("DURABLE_WORKFLOW_TASK_QUEUE")
    workflow_type = f"sample-app.saga.rust.compensate-{args.compensation_runtime}"

    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_CLIENT_TOKEN"),
    ) as client:
        if args.phase == "start":
            workflow_id = f"saga-rust-{args.compensation_runtime}-restart-{uuid.uuid4().hex[:12]}"
            handle = await client.start_workflow(
                workflow_type=workflow_type,
                workflow_id=workflow_id,
                task_queue=queue,
                input=[workflow_id, "restart-check"],
            )
            await wait_for_restart_boundary(handle)
            print(json.dumps({"phase": "restart_boundary", "workflow_id": workflow_id,
                              "run_id": handle.run_id, "compensation_runtime": args.compensation_runtime}))
            return

        workflow_id = args.workflow_id
        if args.phase == "signal":
            await client.signal_workflow(workflow_id, SIGNAL)
            print(json.dumps({"phase": "signal_recorded", "workflow_id": workflow_id}))
            return

        execution = await client.describe_workflow(workflow_id)
        handle = client.get_workflow_handle(
            workflow_id, run_id=execution.run_id, workflow_type=workflow_type
        )
        result = await handle.result(timeout=90.0, poll_interval=0.5)
        expected_result = {
            "status": "compensated",
            "workflow_runtime": "rust",
            "compensation_runtime": args.compensation_runtime,
            "marker": workflow_id,
        }
        if not isinstance(result, dict) or any(result.get(key) != value for key, value in expected_result.items()):
            raise RuntimeError(f"Unexpected restart result: {result!r}")
        if not result.get("initiating_failure"):
            raise RuntimeError("The planned decline did not fail.")

        events = (await handle.get_history())["events"]
        scheduled = [
            event["payload"].get("activity_type")
            for event in events if event["event_type"] == "ActivityScheduled"
        ]
        expected = [
            "sample-app.saga.reserve-first",
            "sample-app.saga.reserve-second",
            "sample-app.saga.decline",
            f"sample-app.saga.{args.compensation_runtime}.undo-second",
            f"sample-app.saga.{args.compensation_runtime}.undo-first",
        ]
        if scheduled != expected:
            raise RuntimeError(f"Unexpected persisted activity order: {scheduled!r}")
        completed = [
            event["payload"].get("activity_type")
            for event in events if event["event_type"] == "ActivityCompleted"
        ]
        if completed != [expected[0], expected[1], expected[3], expected[4]]:
            raise RuntimeError(f"Unexpected completed activities: {completed!r}")
        failed = [
            event["payload"].get("activity_type")
            for event in events if event["event_type"] == "ActivityFailed"
        ]
        if failed != [expected[2]]:
            raise RuntimeError(f"Unexpected failed activities: {failed!r}")
        if [event["event_type"] for event in events].count("SignalWaitOpened") != 1:
            raise RuntimeError("The durable restart wait was not recorded exactly once.")
        if [event["event_type"] for event in events].count("SignalReceived") != 1:
            raise RuntimeError("The offline signal was not recorded exactly once.")
        if events[-1]["event_type"] != "WorkflowCompleted":
            raise RuntimeError("The workflow did not complete after compensation.")
        print(json.dumps({"phase": "verified", "workflow_id": workflow_id, "run_id": handle.run_id,
                          "compensation_runtime": args.compensation_runtime,
                          "scheduled_activities": scheduled, "result": result}, sort_keys=True))


if __name__ == "__main__":
    asyncio.run(main())
