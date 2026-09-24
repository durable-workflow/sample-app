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


def wait_event_type(workflow_runtime: str) -> str:
    return "SignalWaitOpened" if workflow_runtime == "rust" else "ConditionWaitOpened"


async def wait_for_restart_boundary(handle, workflow_runtime: str) -> None:
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        events = (await handle.get_history())["events"]
        first_reserved = any(
            event["event_type"] == "ActivityCompleted"
            and event["payload"].get("activity_type") == "sample-app.saga.reserve-first"
            for event in events
        )
        wait_open = any(
            event["event_type"] == wait_event_type(workflow_runtime) for event in events
        )
        if first_reserved and wait_open:
            return
        await asyncio.sleep(0.5)
    raise TimeoutError("The workflow did not reach its persisted restart boundary.")


async def main() -> None:
    parser = argparse.ArgumentParser(description="Verify saga compensation after a worker restart.")
    parser.add_argument("phase", choices=("start", "signal", "verify"))
    parser.add_argument("workflow_id", nargs="?")
    parser.add_argument("--workflow-runtime", choices=("rust", "php", "python"), default="rust")
    parser.add_argument("--compensation-runtime", choices=("rust", "php", "python"), default="rust")
    args = parser.parse_args()
    if args.phase != "start" and not args.workflow_id:
        parser.error("signal and verify require the workflow ID printed by start")
    if args.workflow_runtime != "rust" and args.compensation_runtime != "rust":
        parser.error("PHP and Python restart workflows use Rust compensation")
    queue = required("DURABLE_WORKFLOW_TASK_QUEUE")
    workflow_type = (
        f"sample-app.saga.rust.compensate-{args.compensation_runtime}"
        if args.workflow_runtime == "rust"
        else f"sample-app.saga.{args.workflow_runtime}.restart-compensate-rust"
    )

    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_CLIENT_TOKEN"),
    ) as client:
        if args.phase == "start":
            workflow_id = (
                f"saga-{args.workflow_runtime}-{args.compensation_runtime}"
                f"-restart-{uuid.uuid4().hex[:12]}"
            )
            handle = await client.start_workflow(
                workflow_type=workflow_type,
                workflow_id=workflow_id,
                task_queue=queue,
                input=[workflow_id, "restart-check"]
                if args.workflow_runtime == "rust"
                else [workflow_id],
            )
            await wait_for_restart_boundary(handle, args.workflow_runtime)
            print(json.dumps({"phase": "restart_boundary", "workflow_id": workflow_id,
                              "run_id": handle.run_id, "workflow_runtime": args.workflow_runtime,
                              "compensation_runtime": args.compensation_runtime}))
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
            "workflow_runtime": args.workflow_runtime,
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
        if [event["event_type"] for event in events].count(wait_event_type(args.workflow_runtime)) != 1:
            raise RuntimeError("The durable restart wait was not recorded exactly once.")
        if [event["event_type"] for event in events].count("SignalReceived") != 1:
            raise RuntimeError("The offline signal was not recorded exactly once.")
        if events[-1]["event_type"] != "WorkflowCompleted":
            raise RuntimeError("The workflow did not complete after compensation.")
        print(json.dumps({"phase": "verified", "workflow_id": workflow_id, "run_id": handle.run_id,
                          "workflow_runtime": args.workflow_runtime,
                          "compensation_runtime": args.compensation_runtime,
                          "scheduled_activities": scheduled, "result": result}, sort_keys=True))


if __name__ == "__main__":
    asyncio.run(main())
