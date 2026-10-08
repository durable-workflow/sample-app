"""Observe actual published SDK update clients and workers in the Compose stack."""

from __future__ import annotations

import argparse
import asyncio
import json
import os

from durable_workflow import Client, InvalidArgument, serializer

RUNTIMES = ("php", "python", "rust")
DIRECTIONS = tuple((caller, runtime) for caller in RUNTIMES for runtime in RUNTIMES)


def required(name):
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before running SDK updates.")
    return value


def emit(**record):
    print(json.dumps(record, sort_keys=True), flush=True)


def records(name):
    return [json.loads(line) for line in required(name).splitlines()]


def workflow_id(runtime):
    return f"{required('DURABLE_WORKFLOW_UPDATE_ID')}-{runtime}"


def request(caller, request_id):
    return {"caller": caller, "request_id": request_id, "value": "hello",
            "nested": {"enabled": True, "count": 42}}


def same_result(actual, expected):
    return json.dumps(actual, sort_keys=True) == json.dumps(expected, sort_keys=True)


def update_events(history, request_id):
    accepted = []
    for event in history["events"]:
        if event["event_type"] != "UpdateAccepted":
            continue
        args = serializer.decode_envelope(event["payload"]["arguments"], codec="avro")
        if args and isinstance(args[0], dict) and args[0].get("request_id") == request_id:
            accepted.append(event)
    if len(accepted) != 1:
        raise RuntimeError(f"Expected one accepted update for {request_id}: {accepted!r}")
    update_id = accepted[0]["payload"]["update_id"]
    related = [event for event in history["events"] if event["payload"].get("update_id") == update_id]
    return update_id, related


def verify_completed(history, request_id, runtime, caller):
    update_id, related = update_events(history, request_id)
    completed = [event for event in related if event["event_type"] == "UpdateCompleted"]
    if len(completed) != 1 or completed[0]["payload"].get("failure_id"):
        raise RuntimeError(f"Expected one completed update: {related!r}")
    types = [event["event_type"] for event in related]
    if types.index("UpdateAccepted") >= types.index("UpdateCompleted"):
        raise RuntimeError("Update completion precedes acceptance.")
    expected = {"handler_runtime": runtime, "request": request(caller, request_id)}
    result = serializer.decode_envelope(completed[0]["payload"]["result"], codec="avro")
    if not same_result(result, expected):
        raise RuntimeError(f"Persisted result changed: {result!r}")
    return update_id, related, expected


async def observe(client, runtime):
    execution = await client.describe_workflow(workflow_id(runtime))
    if not execution.run_id:
        raise RuntimeError("Workflow has no durable run identity.")
    originals = records("DURABLE_WORKFLOW_UPDATE_RUNS")
    original = next(record for record in originals if record["runtime"] == runtime)
    if execution.run_id != original["run_id"]:
        raise RuntimeError("Update experiment replaced its original run.")
    history = {"events": []}
    token = None
    seen = set()
    while True:
        page = await client.get_history(execution.workflow_id, execution.run_id,
                                        page_size=100, next_page_token=token)
        history["events"].extend(page["events"])
        token = page.get("next_page_token")
        if not token:
            return execution, history
        if token in seen:
            raise RuntimeError("History pagination repeated its token.")
        seen.add(token)


async def start(client):
    for runtime in RUNTIMES:
        handle = await client.start_workflow(workflow_type=f"polyglot.{runtime}.updates",
                                           workflow_id=workflow_id(runtime),
                                           task_queue=f"polyglot-{runtime}", input=[workflow_id(runtime)])
        deadline = asyncio.get_running_loop().time() + 30
        while True:
            execution = await handle.describe()
            if execution.status == "waiting" and execution.run_id:
                break
            if execution.status in ("failed", "completed", "terminated"):
                raise RuntimeError(f"Update workflow did not wait: {execution!r}")
            if asyncio.get_running_loop().time() >= deadline:
                raise TimeoutError(f"{runtime} did not reach its signal wait.")
            await asyncio.sleep(.25)
        emit(runtime=runtime, workflow_id=execution.workflow_id, run_id=execution.run_id)


async def call(client, target, request_id, name):
    response = await client.update_workflow(target, name, args=[request("python", request_id)],
                                           wait_for="completed", request_id=request_id)
    if response.get("update_status") != "completed":
        raise RuntimeError(f"Update did not complete: {response!r}")
    emit(caller="python", request_id=request_id,
         result=serializer.decode_envelope(response["result_envelope"], codec="avro"))


async def matrix(client):
    results = records("DURABLE_WORKFLOW_UPDATE_RESULTS")
    if len(results) != len(DIRECTIONS):
        raise RuntimeError("Not all nine client/handler directions executed.")
    for caller, runtime in DIRECTIONS:
        request_id = f"{required('DURABLE_WORKFLOW_UPDATE_ID')}-{caller}-{runtime}"
        matches = [result for result in results if result.get("request_id") == request_id]
        if len(matches) != 1 or matches[0].get("caller") != caller:
            raise RuntimeError("Missing or duplicate SDK client observation.")
        execution, history = await observe(client, runtime)
        update_id, related, expected = verify_completed(history, request_id, runtime, caller)
        if not same_result(matches[0].get("result"), expected):
            raise RuntimeError(f"SDK client result differs from persisted result: {matches[0]!r}")
        emit(scenario="client-handler", caller=caller, runtime=runtime, run_id=execution.run_id,
             update_id=update_id, result=expected, events=related)


async def queued(client):
    request_id = f"{required('DURABLE_WORKFLOW_UPDATE_ID')}-queued"
    response = await client.update_workflow(workflow_id("rust"), "echo",
                                           args=[request("python", request_id)],
                                           wait_for="accepted", request_id=request_id)
    execution, history = await observe(client, "rust")
    update_id, related = update_events(history, request_id)
    if response.get("update_status") != "accepted" or response.get("update_id") != update_id:
        raise RuntimeError(f"Missing durable acceptance while worker is absent: {response!r}")
    if any(event["event_type"] in ("UpdateCompleted", "UpdateFailed") for event in related):
        raise RuntimeError("Update settled while its worker should be absent.")
    emit(scenario="accepted-without-worker", request_id=request_id, update_id=update_id,
         run_id=execution.run_id, response=response)


async def replacement(client):
    original = json.loads(required("DURABLE_WORKFLOW_UPDATE_QUEUED"))
    request_id = original["request_id"]
    responses = []
    for _ in range(2):
        responses.append(await client.update_workflow(workflow_id("rust"), "echo",
                         args=[request("python", request_id)], wait_for="completed", request_id=request_id))
    execution, history = await observe(client, "rust")
    update_id, related, expected = verify_completed(history, request_id, "rust", "python")
    if update_id != original["update_id"] or execution.run_id != original["run_id"]:
        raise RuntimeError("Replacement changed the original accepted identity.")
    if any(response.get("update_id") != update_id or response.get("update_status") != "completed"
           or not same_result(serializer.decode_envelope(response["result_envelope"], codec="avro"), expected)
           for response in responses):
        raise RuntimeError("Duplicate request did not retain its original completion.")
    emit(scenario="replacement-and-duplicate", runtime="rust", update_id=update_id,
         run_id=execution.run_id, result=expected, events=related)
    await matrix(client)


async def failure(client):
    request_id = f"{required('DURABLE_WORKFLOW_UPDATE_ID')}-failure"
    try:
        await client.update_workflow(workflow_id("rust"), "fail",
                         args=[request("python", request_id)], wait_for="completed", request_id=request_id)
    except InvalidArgument as error:
        sdk_error = {"type": type(error).__name__, "message": str(error)}
    else:
        raise RuntimeError("Expected the published Python SDK's exception for a failed update.")
    execution, history = await observe(client, "rust")
    update_id, related = update_events(history, request_id)
    failed = [event for event in related if event["event_type"] == "UpdateCompleted"
              and event["payload"].get("failure_id")]
    if (len(failed) != 1 or "update-probe-failure" not in json.dumps(failed[0]["payload"])
            or sum(event["event_type"] == "UpdateCompleted" for event in related) != 1):
        raise RuntimeError(f"Missing durable handler failure: {sdk_error!r}, {related!r}")
    if execution.status in ("failed", "terminated", "completed"):
        raise RuntimeError("An update failure unexpectedly terminated the workflow.")
    emit(scenario="handler-failure", runtime="rust", update_id=update_id,
         sdk_error=sdk_error, events=related)


async def finish(client):
    for runtime in RUNTIMES:
        execution, _ = await observe(client, runtime)
        handle = client.get_workflow_handle(execution.workflow_id)
        await handle.signal("updates-finish")
        result = await handle.result(timeout=60, poll_interval=.25)
        execution, history = await observe(client, runtime)
        if result != {"workflow_runtime": runtime, "request": workflow_id(runtime)}:
            raise RuntimeError(f"Unexpected workflow result: {result!r}")
        if execution.status != "completed" or sum(event["event_type"] == "WorkflowCompleted"
                                                   for event in history["events"]) != 1:
            raise RuntimeError("Workflow did not complete once from its original run.")
        emit(scenario="workflow-completion", runtime=runtime, run_id=execution.run_id, result=result)


async def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("phase", choices=["start", "call", "matrix", "queued", "replacement", "failure", "finish"])
    parser.add_argument("arguments", nargs="*")
    args = parser.parse_args()
    async with Client(required("DURABLE_WORKFLOW_SERVER_URL"), token=required("DURABLE_WORKFLOW_AUTH_TOKEN"),
                      namespace=required("DURABLE_WORKFLOW_NAMESPACE"), timeout=60) as client:
        if args.phase == "call":
            if len(args.arguments) != 3:
                parser.error("call requires workflow ID, request ID and update name")
            await call(client, *args.arguments)
        else:
            await globals()[args.phase](client)


if __name__ == "__main__":
    asyncio.run(main())
