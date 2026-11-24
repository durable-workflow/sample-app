from __future__ import annotations

import argparse
import asyncio
import json
import uuid
from urllib.request import urlopen

from durable_workflow import Client

from client import RUNTIMES, required, verify_compensation_outputs


def workflow_type(record: dict) -> str:
    return f"sample-app.saga.{record['workflow_runtime']}.compensate-{record['compensation_runtime']}"


def compensation_type(record: dict, step: str) -> str:
    return f"sample-app.saga.{record['compensation_runtime']}.undo-{step}"


def activity_events(history: list[dict], kind: str, activity_type: str) -> list[dict]:
    return [event["payload"] for event in history
            if event["event_type"] == kind and event["payload"].get("activity_type") == activity_type]


def compensation_attempts(history: list[dict], target: str) -> list[dict]:
    scheduled = activity_events(history, "ActivityScheduled", target)
    if len(scheduled) != 1:
        raise RuntimeError("Expected one logical scheduling of the recovered compensation.")
    execution = scheduled[0]["activity_execution_id"]
    return [event["payload"] for event in history
            if event["event_type"] == "ActivityStarted" and event["payload"]["activity_execution_id"] == execution]


async def effect_snapshot(marker: str) -> dict:
    def fetch():
        with urlopen(required("ACTIVITY_EFFECTS_URL") + "/effects/" + marker + ":undo-second", timeout=5) as response:
            return json.load(response)

    return await asyncio.to_thread(fetch)


async def start(client: Client, workflow_runtime: str, compensation_runtime: str) -> dict:
    marker = f"saga-effect-{workflow_runtime}-{compensation_runtime}-{uuid.uuid4().hex[:12]}"
    record = {"workflow_id": marker, "workflow_runtime": workflow_runtime, "compensation_runtime": compensation_runtime}
    handle = await client.start_workflow(
        workflow_type=workflow_type(record), workflow_id=marker,
        task_queue=required("DURABLE_WORKFLOW_TASK_QUEUE"), input=[marker, "recover-effect"],
    )
    if not handle.run_id:
        raise RuntimeError("The start response did not identify the original saga run.")
    target = compensation_type(record, "second")
    async with asyncio.timeout(45):
        while True:
            downstream = await effect_snapshot(marker)
            if not downstream["effects"]:
                await asyncio.sleep(0.25)
                continue
            history = (await handle.get_history())["events"]
            starts = compensation_attempts(history, target)
            completed = activity_events(history, "ActivityCompleted", target)
            if len(starts) != 1 or starts[0]["attempt_number"] != 1 or completed:
                raise RuntimeError("The first compensation was no longer in flight at the crash boundary.")
            attempt = starts[0]
            primary = {"attempt_id": attempt["activity_attempt_id"], "outcome": "committed"}
            if len(downstream["effects"]) != 1 or downstream["deliveries"] != [primary]:
                raise RuntimeError("The downstream commit did not belong to the first compensation attempt.")
            effect = downstream["effects"][0]
            if (
                effect["operation_key"] != marker + ":undo-second"
                or effect["input"] != {"operation": "undo-second", "marker": marker}
            ):
                raise RuntimeError("Unexpected input reached the downstream ledger.")
            return {
                **record, "run_id": handle.run_id, "effect": effect,
                "activity_execution_id": attempt["activity_execution_id"],
                "original_attempt_id": attempt["activity_attempt_id"],
            }


async def verify(client: Client, record: dict) -> dict:
    marker = record["workflow_id"]
    runtime = record["compensation_runtime"]
    handle = client.get_workflow_handle(marker, run_id=record["run_id"], workflow_type=workflow_type(record))
    result = await handle.result(timeout=65, poll_interval=0.5)
    expected = {
        "status": "compensated", "marker": marker,
        "workflow_runtime": record["workflow_runtime"], "compensation_runtime": runtime,
    }
    if not isinstance(result, dict) or any(result.get(key) != value for key, value in expected.items()):
        raise RuntimeError(f"Unexpected recovered saga result: {result!r}")
    if not result.get("initiating_failure"):
        raise RuntimeError("The planned initiating failure was lost.")

    history = (await handle.get_history())["events"]
    if len(activity_events(history, "ActivityFailed", "sample-app.saga.decline")) != 1:
        raise RuntimeError("Expected one recorded initiating failure.")
    target = compensation_type(record, "second")
    starts = compensation_attempts(history, target)
    if [attempt["attempt_number"] for attempt in starts] != [1, 2]:
        raise RuntimeError("The original compensation did not receive exactly two attempts.")
    original, replacement = [attempt["activity_attempt_id"] for attempt in starts]
    execution = record["activity_execution_id"]
    if original != record["original_attempt_id"] or replacement == original:
        raise RuntimeError("The compensation retry did not have a distinct attempt identity.")
    if any(attempt["activity_execution_id"] != execution for attempt in starts):
        raise RuntimeError("The compensation retry replaced its logical activity execution.")
    retries = [event["payload"] for event in history if event["event_type"] == "ActivityRetryScheduled"]
    if len(retries) != 1 or any(retries[0].get(key) != value for key, value in {
        "activity_execution_id": execution, "retry_after_attempt_id": original, "timeout_kind": "start_to_close",
    }.items()):
        raise RuntimeError("The retry did not follow the first compensation's persisted timeout.")
    verify_compensation_outputs(history, runtime, marker)
    if history[-1]["event_type"] != "WorkflowCompleted":
        raise RuntimeError("The original saga did not reach durable completion.")

    downstream = await effect_snapshot(marker)
    if downstream["effects"] != [record["effect"]] or downstream["deliveries"] != [
        {"attempt_id": original, "outcome": "committed"},
        {"attempt_id": replacement, "outcome": "reused"},
    ]:
        raise RuntimeError("The retry did not reuse the original downstream effect.")
    return {**record, "replacement_attempt_id": replacement, "result": result}


async def main() -> None:
    parser = argparse.ArgumentParser(description="Verify committed saga compensation across worker loss.")
    commands = parser.add_subparsers(dest="phase", required=True)
    initial = commands.add_parser("start")
    initial.add_argument("workflow_runtime", choices=RUNTIMES)
    initial.add_argument("compensation_runtime", choices=RUNTIMES)
    recovery = commands.add_parser("verify")
    recovery.add_argument("boundary", type=json.loads)
    recovery.add_argument("original_container")
    recovery.add_argument("replacement_container")
    args = parser.parse_args()

    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"), namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_CLIENT_TOKEN"),
    ) as client:
        if args.phase == "start":
            record = await start(client, args.workflow_runtime, args.compensation_runtime)
        else:
            record = await verify(client, args.boundary)
            record.update(original_container=args.original_container, replacement_container=args.replacement_container)
        print(json.dumps(record, sort_keys=True), flush=True)


if __name__ == "__main__":
    asyncio.run(main())
