"""Observe published SDK child results, typed failures and cold recovery."""

from __future__ import annotations

import argparse
import asyncio
import json
import os

from durable_workflow import Client, serializer

RUNTIMES = ("php", "python", "rust")
DIRECTIONS = tuple((parent, child) for parent in RUNTIMES for child in RUNTIMES)
RUST_DIRECTIONS = tuple(direction for direction in DIRECTIONS if "rust" in direction)
PARENT_EVENTS = {"WorkflowStarted", "ChildWorkflowScheduled", "ChildRunStarted",
                 "ChildRunCompleted", "ChildRunFailed", "ChildRunCancelled", "ChildRunTerminated",
                 "WorkflowCompleted", "WorkflowFailed", "WorkflowCancelled", "WorkflowTerminated"}
TERMINAL_EVENTS = {"WorkflowCompleted", "WorkflowFailed", "WorkflowCancelled", "WorkflowTerminated"}


def required(name):
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before running SDK children.")
    return value


def emit(**record):
    print(json.dumps(record, sort_keys=True), flush=True)


def records():
    pending = [json.loads(line) for line in required("DURABLE_WORKFLOW_CHILD_RUNS").splitlines()]
    if (len(pending) != len(RUST_DIRECTIONS)
            or {(record["parent"], record["child"]) for record in pending} != set(RUST_DIRECTIONS)):
        raise RuntimeError("Missing or repeated parked child recovery directions.")
    for record in pending:
        record.pop("scenario", None)
    return pending


def same_result(actual, expected):
    return json.dumps(actual, sort_keys=True) == json.dumps(expected, sort_keys=True)


def one(history, kind):
    events = [event for event in history["events"] if event["event_type"] == kind]
    if len(events) != 1:
        raise RuntimeError(f"Expected one {kind}, got {len(events)}.")
    return events[0]


def child_identity(history, child_type):
    scheduled = one(history, "ChildWorkflowScheduled")["payload"]
    started = one(history, "ChildRunStarted")["payload"]
    keys = ("child_workflow_instance_id", "child_workflow_run_id", "workflow_link_id", "child_call_id")
    if any(not scheduled.get(key) or scheduled[key] != started.get(key) for key in keys):
        raise RuntimeError("Child schedule and start do not retain one relationship identity.")
    if scheduled.get("child_workflow_type") != child_type or started.get("child_workflow_type") != child_type:
        raise RuntimeError("The original parent started the wrong child workflow type.")
    return {key: started[key] for key in keys}


def wait_identity(history, runtime):
    signal_wait = runtime == "rust"
    opened = one(history, "SignalWaitOpened" if signal_wait else "ConditionWaitOpened")["payload"]
    wait_id = opened.get("signal_wait_id" if signal_wait else "condition_wait_id")
    if (not wait_id or opened.get("signal_name" if signal_wait else "condition_key") != "child-finish"
            or not isinstance(opened.get("sequence"), int)):
        raise RuntimeError("Child did not retain its declared durable completion wait.")
    return {"wait_id": wait_id, "wait_sequence": opened["sequence"]}


def verify_resume(history, record):
    if wait_identity(history, record["child"]) != {key: record[key] for key in ("wait_id", "wait_sequence")}:
        raise RuntimeError("Recovery replaced the original child wait.")
    signal_wait = record["child"] == "rust"
    received = one(history, "SignalReceived")["payload"]
    resolved_kind = "SignalApplied" if signal_wait else "ConditionWaitSatisfied"
    resolved = one(history, resolved_kind)["payload"]
    if (received.get("signal_name") != "child-finish" or not received.get("signal_id")
            or received["signal_id"] != resolved.get("signal_id" if signal_wait else "workflow_signal_id")
            or resolved.get("signal_name") != "child-finish"
            or resolved.get("signal_wait_id" if signal_wait else "condition_wait_id") != record["wait_id"]
            or resolved.get("sequence") != record["wait_sequence"]):
        raise RuntimeError("Recovery did not resolve the original wait with its acknowledged signal once.")
    kinds = [event["event_type"] for event in history["events"]]
    if not kinds.index("SignalReceived") < kinds.index(resolved_kind) < kinds.index("WorkflowCompleted"):
        raise RuntimeError("Child completion did not follow its acknowledged signal and durable wait resolution.")


def expected_result(record, failed=False):
    if failed:
        prefix = "codec error: " if record["child"] == "rust" else ""
        return {"parent_runtime": record["parent"], "child_failure": {
            "type": "ChildWorkflowFailed", "message": f"{prefix}child-probe-failure: {record['value']}",
            "child_type": record["child_type"],
        }}
    return {"parent_runtime": record["parent"], "child_result": {
        "runtime": record["child"], "value": record["value"],
    }}


def verify_pair(parent_history, child_history, record, result, failed=False):
    linked = child_identity(parent_history, record["child_type"])
    if any(linked[key] != record[key] for key in linked):
        raise RuntimeError("Recovery replaced the original child or relationship identity.")
    outcome = "ChildRunFailed" if failed else "ChildRunCompleted"
    kinds = [event["event_type"] for event in parent_history["events"] if event["event_type"] in PARENT_EVENTS]
    if kinds != ["WorkflowStarted", "ChildWorkflowScheduled", "ChildRunStarted", outcome, "WorkflowCompleted"]:
        raise RuntimeError(f"Original parent lifecycle is incomplete or out of order: {kinds!r}")
    settled = one(parent_history, outcome)["payload"]
    if any(settled.get(key) != record[key] for key in linked):
        raise RuntimeError("Parent settled a different child or relationship identity.")
    child_start = one(child_history, "WorkflowStarted")["payload"]
    if (child_start.get("workflow_run_id") != record["child_workflow_run_id"]
            or child_start.get("parent_workflow_run_id") != record["parent_run_id"]):
        raise RuntimeError("Child start does not belong to the original parent and child runs.")
    child_terminal = [event for event in child_history["events"] if event["event_type"] in TERMINAL_EVENTS]
    expected_terminal = "WorkflowFailed" if failed else "WorkflowCompleted"
    if len(child_terminal) != 1 or child_terminal[0]["event_type"] != expected_terminal:
        raise RuntimeError("Original child did not reach its expected terminal outcome once.")
    expected = expected_result(record, failed)
    parent_output = one(parent_history, "WorkflowCompleted")["payload"]["output"]
    if not same_result(result, expected) or not same_result(serializer.decode_envelope(parent_output, codec="avro"), expected):
        raise RuntimeError(f"SDK and persisted parent result differ: expected={expected!r}, actual={result!r}")
    if failed:
        failure = child_terminal[0]["payload"]
        if (not settled.get("failure_id") or settled["failure_id"] != failure.get("failure_id")
                or settled.get("message") != expected["child_failure"]["message"]
                or failure.get("message") != expected["child_failure"]["message"]):
            raise RuntimeError("Typed child failure does not match one durable original failure.")
    else:
        child_output = serializer.decode_envelope(child_terminal[0]["payload"]["output"], codec="avro")
        if not same_result(child_output, expected["child_result"]):
            raise RuntimeError("Persisted child result lost its original input or runtime.")


async def observe(client, workflow_id, run_id):
    execution = await client.describe_workflow(workflow_id)
    if execution.run_id != run_id:
        raise RuntimeError("The original workflow run was replaced.")
    events, token, seen = [], None, set()
    while True:
        page = await client.get_history(workflow_id, run_id=run_id, next_page_token=token)
        events.extend(page["events"])
        token = page.get("next_page_token")
        if not token:
            return execution, {"events": events}
        if token in seen:
            raise RuntimeError("History pagination repeated a page token.")
        seen.add(token)


async def start(client, parent, child, behavior):
    value = f"{required('DURABLE_WORKFLOW_CHILD_ID')}-{behavior}-{parent}-{child}"
    handle = await client.start_workflow(
        workflow_type=f"sample-app.child-matrix.{parent}.parent-{child}",
        workflow_id=value, task_queue=f"polyglot-{parent}", input=[value, behavior],
    )
    if not handle.run_id:
        raise RuntimeError("Parent start returned no original run identity.")
    return handle, {"parent": parent, "child": child, "value": value,
                    "parent_workflow_id": value, "parent_run_id": handle.run_id,
                    "child_type": f"sample-app.child-matrix.{child}.child"}


async def verify(client, handle, record, failed=False, recovered=False):
    result = await handle.result(timeout=90, poll_interval=.25)
    parent_execution, parent_history = await observe(client, record["parent_workflow_id"], record["parent_run_id"])
    if "child_workflow_run_id" not in record:
        record.update(child_identity(parent_history, record["child_type"]))
    child_execution, child_history = await observe(client, record["child_workflow_instance_id"], record["child_workflow_run_id"])
    if parent_execution.status != "completed" or child_execution.status != ("failed" if failed else "completed"):
        raise RuntimeError("Public status differs from the expected parent/child outcome.")
    verify_pair(parent_history, child_history, record, result, failed)
    if recovered:
        verify_resume(child_history, record)
    emit(scenario="typed-child-failure" if failed else ("child-worker-recovery" if recovered else "child-completion"),
         **record, result=result,
         parent_events=[event for event in parent_history["events"] if event["event_type"] in PARENT_EVENTS],
         child_events=child_history["events"])


async def matrix(client):
    for parent, child in DIRECTIONS:
        handle, record = await start(client, parent, child, "complete")
        await verify(client, handle, record)


async def failure(client):
    for parent, child in RUST_DIRECTIONS:
        handle, record = await start(client, parent, child, "fail")
        await verify(client, handle, record, failed=True)


async def park(client):
    for parent, child in RUST_DIRECTIONS:
        _, record = await start(client, parent, child, "wait")
        deadline = asyncio.get_running_loop().time() + 90
        while asyncio.get_running_loop().time() < deadline:
            parent_execution, history = await observe(client, record["parent_workflow_id"], record["parent_run_id"])
            if any(event["event_type"] == "ChildRunStarted" for event in history["events"]):
                record.update(child_identity(history, record["child_type"]))
                child_execution, child_history = await observe(client, record["child_workflow_instance_id"], record["child_workflow_run_id"])
                if any(event["event_type"] in TERMINAL_EVENTS for event in child_history["events"]):
                    terminal = [event for event in child_history["events"] if event["event_type"] in TERMINAL_EVENTS]
                    emit(scenario="child-closed-before-wait", **record, parent_status=parent_execution.status,
                         child_status=child_execution.status, terminal_events=terminal)
                    raise RuntimeError("Recovery child closed before reaching its signal wait.")
                if parent_execution.status == "waiting" and child_execution.status == "waiting":
                    record.update(wait_identity(child_history, child))
                    emit(scenario="child-parked", **record)
                    break
            await asyncio.sleep(.25)
        else:
            raise RuntimeError(f"Child did not reach its signal wait: {record!r}")


async def release(client):
    for record in records():
        handle = client.get_workflow_handle(record["child_workflow_instance_id"], run_id=record["child_workflow_run_id"])
        await handle.signal("child-finish")
        _, parent_history = await observe(client, record["parent_workflow_id"], record["parent_run_id"])
        _, child_history = await observe(client, record["child_workflow_instance_id"], record["child_workflow_run_id"])
        if (any(event["event_type"] in TERMINAL_EVENTS | {"SignalApplied", "ConditionWaitSatisfied", "ConditionWaitTimedOut"}
                for event in child_history["events"])
                or any(event["event_type"] in TERMINAL_EVENTS | {"ChildRunCompleted"} for event in parent_history["events"])):
            raise RuntimeError("An absent worker unexpectedly applied or completed child work.")
        received = one(child_history, "SignalReceived")["payload"]
        if received.get("signal_name") != "child-finish" or not received.get("signal_id"):
            raise RuntimeError("The original child did not record its acknowledged completion signal.")
        emit(scenario="child-signal-without-workers", **record, signal_id=received["signal_id"])


async def recovery(client):
    for record in records():
        handle = client.get_workflow_handle(record["parent_workflow_id"], run_id=record["parent_run_id"])
        await verify(client, handle, record, recovered=True)


async def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("phase", choices=["matrix", "failure", "park", "release", "recovery"])
    args = parser.parse_args()
    async with Client(required("DURABLE_WORKFLOW_SERVER_URL"), token=required("DURABLE_WORKFLOW_AUTH_TOKEN"),
                      namespace=required("DURABLE_WORKFLOW_NAMESPACE"), timeout=60) as client:
        await globals()[args.phase](client)


if __name__ == "__main__":
    asyncio.run(main())
