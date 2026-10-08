"""Observe actual published SDK update clients and workers in the Compose stack."""

from __future__ import annotations

import argparse
import asyncio
import json
import os
from dataclasses import asdict
from urllib.parse import quote

from durable_workflow import Client, UpdateFailed, serializer

RUNTIMES = ("php", "python", "rust")
DIRECTIONS = tuple((caller, runtime) for caller in RUNTIMES for runtime in RUNTIMES)
STATE_DELTAS = {"php": 1, "python": 2, "rust": 3}


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


def increment_request(caller, request_id, delta):
    return {**request(caller, request_id), "delta": delta}


def increment_id(runtime, caller):
    return f"{required('DURABLE_WORKFLOW_UPDATE_ID')}-increment-{caller}-{runtime}"


def counter_state(runtime, callers=RUNTIMES, replaced=False):
    mutations = [increment_id(runtime, caller) for caller in callers]
    counter = sum(STATE_DELTAS[caller] for caller in callers)
    if replaced:
        mutations.append(increment_id(runtime, "queued"))
        counter += 5
    return {"counter": counter, "mutations": mutations}


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


def verify_increment(history, request_id, runtime, caller, delta, state):
    update_id, related = update_events(history, request_id)
    types = [event["event_type"] for event in related]
    if any(types.count(kind) != 1 for kind in ("UpdateAccepted", "UpdateApplied", "UpdateCompleted")):
        raise RuntimeError("A state mutation needs one accepted, applied and completed identity.")
    if not types.index("UpdateAccepted") < types.index("UpdateApplied") < types.index("UpdateCompleted"):
        raise RuntimeError("State mutation history is out of order.")
    completed = next(event for event in related if event["event_type"] == "UpdateCompleted")
    expected = {"handler_runtime": runtime, "request": increment_request(caller, request_id, delta), "state": state}
    for event in related:
        if event["event_type"] in ("UpdateAccepted", "UpdateApplied"):
            if (event["payload"].get("update_name") != "increment"
                    or not same_result(serializer.decode_envelope(event["payload"]["arguments"], codec="avro"),
                                       [expected["request"]])):
                raise RuntimeError("Accepted and applied mutation arguments differ from the original request.")
    actual = serializer.decode_envelope(completed["payload"]["result"], codec="avro")
    if completed["payload"].get("failure_id") or not same_result(actual, expected):
        raise RuntimeError(f"Persisted mutation does not retain the expected accumulated state: "
                           f"update_id={update_id}, expected={expected!r}, actual={actual!r}")
    return update_id, related, expected


async def observe(client, runtime):
    execution = await client.describe_workflow(workflow_id(runtime))
    if not execution.run_id:
        raise RuntimeError("Workflow has no durable run identity.")
    originals = records("DURABLE_WORKFLOW_UPDATE_RUNS")
    original = next(record for record in originals if record["runtime"] == runtime)
    if execution.run_id != original["run_id"]:
        raise RuntimeError("Update experiment replaced its original run.")
    return execution, await history_for(client, execution.workflow_id, execution.run_id)


async def history_for(client, workflow, run):
    history = {"events": []}
    token = None
    seen = set()
    while True:
        page = await client.get_history(workflow, run,
                                        page_size=100, next_page_token=token)
        history["events"].extend(page["events"])
        token = page.get("next_page_token")
        if not token:
            return history
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


async def call(client, target, request_id, name, delta=None):
    arguments = request("python", request_id) if delta is None else increment_request("python", request_id, int(delta))
    response = await client.update_workflow(target, name, args=[arguments],
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


async def check_counter(client, runtime, state, stage):
    execution, before = await observe(client, runtime)
    response = await client.query_workflow(execution.workflow_id, "counter")
    _, after = await observe(client, runtime)
    if not same_result(response.get("result"), state):
        raise RuntimeError(f"Counter query lost accumulated state: expected={state!r}, actual={response!r}")
    identity = lambda history: [(event["event_type"], event.get("sequence")) for event in history["events"]]
    if identity(before) != identity(after):
        raise RuntimeError("Counter query changed durable workflow history.")
    emit(scenario="stateful-counter-query", runtime=runtime, stage=stage, run_id=execution.run_id,
         expected=state, query=response.get("result"))


async def state_matrix(client):
    results = records("DURABLE_WORKFLOW_STATE_RESULTS")
    if len(results) != len(DIRECTIONS):
        raise RuntimeError("Not all nine state mutation directions executed.")
    emit(scenario="stateful-sdk-client-observations", results=results)
    for caller, runtime in DIRECTIONS:
        request_id = increment_id(runtime, caller)
        matches = [record for record in results if record.get("request_id") == request_id and record.get("caller") == caller]
        if len(matches) != 1:
            raise RuntimeError("Missing or duplicate state mutation SDK observation.")
        execution, history = await observe(client, runtime)
        state = counter_state(runtime, RUNTIMES[:RUNTIMES.index(caller) + 1])
        update_id, related, expected = verify_increment(history, request_id, runtime, caller, STATE_DELTAS[caller], state)
        if not same_result(matches[0].get("result"), expected):
            raise RuntimeError("State mutation SDK and durable results disagree.")
        emit(scenario="stateful-client-handler", caller=caller, runtime=runtime, run_id=execution.run_id,
             update_id=update_id, result=expected, events=related)
    for runtime in RUNTIMES:
        await check_counter(client, runtime, counter_state(runtime), "initial")


async def state_queued(client):
    for runtime in RUNTIMES:
        request_id = increment_id(runtime, "queued")
        arguments = [increment_request("python", request_id, 5)]
        response = await client.update_workflow(workflow_id(runtime), "increment", args=arguments,
                                                wait_for="accepted", request_id=request_id)
        duplicate = await client.update_workflow(workflow_id(runtime), "increment", args=arguments,
                                                 wait_for="accepted", request_id=request_id)
        execution, history = await observe(client, runtime)
        update_id, related = update_events(history, request_id)
        if (response.get("update_status") != "accepted" or duplicate.get("update_status") != "accepted"
                or response.get("update_id") != update_id or duplicate.get("update_id") != update_id
                or any(event["event_type"] in ("UpdateApplied", "UpdateCompleted") for event in related)):
            raise RuntimeError("Absent workers must retain one unapplied mutation and duplicate identity.")
        emit(scenario="stateful-accepted-without-workers", runtime=runtime, request_id=request_id,
             run_id=execution.run_id, update_id=update_id)


async def state_replacement(client):
    queued = records("DURABLE_WORKFLOW_STATE_QUEUED")
    for runtime in RUNTIMES:
        request_id = increment_id(runtime, "queued")
        response = await client.update_workflow(workflow_id(runtime), "increment",
                    args=[increment_request("python", request_id, 5)], wait_for="completed", request_id=request_id)
        execution, history = await observe(client, runtime)
        update_id, related, expected = verify_increment(history, request_id, runtime, "python", 5,
                                                        counter_state(runtime, replaced=True))
        original = [record for record in queued if record.get("runtime") == runtime]
        if (len(original) != 1 or original[0].get("update_id") != update_id
                or original[0].get("run_id") != execution.run_id or response.get("update_status") != "completed"
                or response.get("update_id") != update_id
                or not same_result(serializer.decode_envelope(response["result_envelope"], codec="avro"), expected)):
            raise RuntimeError("Replacement worker lost mutation state or original accepted identity.")
        emit(scenario="stateful-replacement", runtime=runtime, run_id=execution.run_id,
             update_id=update_id, result=expected, events=related)
        await check_counter(client, runtime, counter_state(runtime, replaced=True), "replacement")


async def state_duplicates(client):
    results = records("DURABLE_WORKFLOW_STATE_DUPLICATES")
    if len(results) != len(DIRECTIONS):
        raise RuntimeError("Not all nine completed mutation duplicate directions executed.")
    for caller, runtime in DIRECTIONS:
        request_id = increment_id(runtime, caller)
        matches = [record for record in results if record.get("request_id") == request_id and record.get("caller") == caller]
        execution, history = await observe(client, runtime)
        state = counter_state(runtime, RUNTIMES[:RUNTIMES.index(caller) + 1])
        update_id, related, expected = verify_increment(history, request_id, runtime, caller, STATE_DELTAS[caller], state)
        if len(matches) != 1 or not same_result(matches[0].get("result"), expected):
            raise RuntimeError("Duplicate mutation did not return its original accumulated result.")
        emit(scenario="stateful-completed-duplicate", caller=caller, runtime=runtime,
             run_id=execution.run_id, update_id=update_id, result=expected, events=related)
    for runtime in RUNTIMES:
        await check_counter(client, runtime, counter_state(runtime, replaced=True), "duplicates-and-handler-failure")


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
    await snapshot(client, "replacement")


async def snapshot(client, stage="initial"):
    request_id = f"{required('DURABLE_WORKFLOW_UPDATE_ID')}-snapshot-{stage}"
    signal = {"request_id": f"{required('DURABLE_WORKFLOW_UPDATE_ID')}-touch", "delta": 7}
    signal_arguments = [[signal], [[1, 2]], []]
    if stage == "initial":
        for arguments in signal_arguments:
            await client.get_workflow_handle(workflow_id("rust")).signal("updates-touch", args=arguments)
    execution, history = await observe(client, "rust")
    deliveries = [event for event in history["events"] if event["event_type"] == "SignalReceived"
                  and event["payload"].get("signal_name") == "updates-touch"]
    if (len(deliveries) != len(signal_arguments) or not same_result(
            [serializer.decode_envelope(event["payload"]["arguments"], codec="avro") for event in deliveries],
            signal_arguments)):
        raise RuntimeError("The original snapshot signals were not durably recorded once each.")
    expected = {"workflow_id": execution.workflow_id, "run_id": execution.run_id,
                "workflow_input": [workflow_id("rust")], "signals": signal_arguments}
    query = await client.query_workflow(execution.workflow_id, "snapshot")
    completion_deadline = asyncio.get_running_loop().time() + 90
    response = await client.update_workflow(execution.workflow_id, "snapshot",
                    args=[request("python", request_id)], wait_for="completed", wait_timeout_seconds=45,
                    request_id=request_id)
    if response.get("update_status") == "accepted":
        while asyncio.get_running_loop().time() < completion_deadline:
            _, pending_history = await observe(client, "rust")
            _, pending_events = update_events(pending_history, request_id)
            if any(event["event_type"] == "UpdateCompleted" for event in pending_events):
                response = await client.update_workflow(execution.workflow_id, "snapshot",
                    args=[request("python", request_id)], wait_for="completed", wait_timeout_seconds=1,
                    request_id=request_id)
                break
            await asyncio.sleep(.25)
    result = serializer.decode_envelope(response["result_envelope"], codec="avro")
    execution, history = await observe(client, "rust")
    update_id, related = update_events(history, request_id)
    completed = [event for event in related if event["event_type"] == "UpdateCompleted"]
    if (response.get("update_status") != "completed" or response.get("update_id") != update_id
            or len(completed) != 1 or completed[0]["payload"].get("failure_id")
            or not same_result(serializer.decode_envelope(completed[0]["payload"]["result"], codec="avro"), result)):
        fields = ("signal_id", "signal_name", "workflow_command_id", "update_id", "sequence", "failure_id", "message")
        emit(scenario="rust-update-snapshot-incomplete", stage=stage, expected=expected,
             query=query.get("result"), update=result,
             response={key: response.get(key) for key in ("update_id", "update_status", "ordering_state",
                       "queued_behind_command_id", "queued_behind_command_type")},
             history=[{"event_type": event["event_type"], "sequence": event.get("sequence"),
                       "payload": {key: event["payload"][key] for key in fields if key in event["payload"]}}
                      for event in history["events"]])
        error = RuntimeError("Snapshot update did not retain its one original completion.")
        await failure_diagnostics(client, client.get_workflow_handle(execution.workflow_id),
                                  "rust", error, "snapshot")
        raise error
    applied = [event for event in history["events"] if event["event_type"] == "SignalApplied"
               and event["payload"].get("signal_name") == "updates-touch"]
    signal_ids = {event["payload"].get("signal_id") for event in deliveries}
    if (len(applied) != len(deliveries) or None in signal_ids or len(signal_ids) != len(deliveries)
            or {event["payload"].get("signal_id") for event in applied} != signal_ids):
        raise RuntimeError("Snapshot signals were not applied once each.")
    emit(scenario="rust-update-snapshot", stage=stage, expected=expected,
         query=query.get("result"), update=result, run_id=execution.run_id, update_id=update_id)
    if not same_result(query.get("result"), expected) or not same_result(result, expected):
        raise RuntimeError("Query and update must expose the original workflow input and committed signal snapshot.")


async def failure(client):
    request_id = f"{required('DURABLE_WORKFLOW_UPDATE_ID')}-failure"
    errors = []
    for _ in range(2):
        try:
            await client.update_workflow(workflow_id("rust"), "fail",
                             args=[request("python", request_id)], wait_for="completed", request_id=request_id)
        except UpdateFailed as error:
            errors.append(error)
        else:
            raise RuntimeError("Expected the published Python SDK's typed failed update.")
    execution, history = await observe(client, "rust")
    update_id, related = update_events(history, request_id)
    failed = [event for event in related if event["event_type"] == "UpdateCompleted"
              and event["payload"].get("failure_id")]
    if (len(failed) != 1 or "update-probe-failure" not in json.dumps(failed[0]["payload"])
            or sum(event["event_type"] == "UpdateCompleted" for event in related) != 1):
        raise RuntimeError(f"Missing durable handler failure: {related!r}")
    payload = failed[0]["payload"]
    expected = {"workflow_id": execution.workflow_id, "run_id": execution.run_id,
                "update_id": update_id, "failure_id": payload["failure_id"]}
    for error in errors:
        if (error.status != 422 or str(error) != payload.get("message")
                or "update-probe-failure" not in str(error)
                or error.body.get("update_status") != "failed"
                or error.body.get("command_status") != "accepted" or error.body.get("accepted") is False
                or any(getattr(error, name) != value or error.body.get(name) != value
                       for name, value in expected.items())):
            actual = {name: getattr(error, name) for name in expected}
            actual.update(message=str(error), status=error.status,
                          update_status=error.body.get("update_status"),
                          command_status=error.body.get("command_status"))
            raise RuntimeError(f"SDK failed-update diagnostics differ: expected={expected!r}, actual={actual!r}")
    if execution.status in ("failed", "terminated", "completed"):
        raise RuntimeError("An update failure unexpectedly terminated the workflow.")
    emit(scenario="handler-failure", runtime="rust", update_id=update_id,
         run_id=execution.run_id, sdk_error={"type": type(errors[0]).__name__, "message": str(errors[0]),
                                           "status": errors[0].status, **expected},
         duplicate_sdk_error={"type": type(errors[1]).__name__, "message": str(errors[1]),
                              "status": errors[1].status, **expected}, events=related)


async def completion_result(client, handle, runtime):
    try:
        return await handle.result(timeout=60, poll_interval=.25)
    except Exception as error:
        await failure_diagnostics(client, handle, runtime, error, "completion")
        raise


async def failure_diagnostics(client, handle, runtime, error, phase):
    # Read the failed run before the shell's exit trap removes its stack.
    # Diagnostics must preserve the original error and stay bounded.
    try:
        async with asyncio.timeout(10):
            execution = await handle.describe()
            history = await history_for(client, execution.workflow_id, execution.run_id)
            debug = await client._request("GET", f"/workflows/{quote(execution.workflow_id, safe='')}"
                                          f"/runs/{quote(execution.run_id, safe='')}/debug")
            workers = await client.list_workers(task_queue=execution.task_queue)
            emit(scenario="workflow-observation-error", runtime=runtime, phase=phase,
                 error={"type": type(error).__name__, "message": str(error)},
                 execution=asdict(execution), history=history, debug=debug,
                 workers=[worker.raw for worker in workers.workers])
    except Exception as diagnostic_error:
        emit(scenario="workflow-observation-error", runtime=runtime, phase=phase,
             error={"type": type(error).__name__, "message": str(error)},
             diagnostic_error=str(diagnostic_error))


async def finish_race(client):
    repetitions = int(os.environ.get("SDK_UPDATES_FINISH_RACE_REPETITIONS", "0"))
    if not 0 <= repetitions <= 20:
        raise ValueError("SDK_UPDATES_FINISH_RACE_REPETITIONS must be between 0 and 20.")
    for index in range(repetitions):
        identifier = f"{required('DURABLE_WORKFLOW_UPDATE_ID')}-finish-race-{index}"
        handle = await client.start_workflow(workflow_type="polyglot.rust.updates",
                                            workflow_id=identifier, task_queue="polyglot-rust",
                                            input=[identifier])
        original = await handle.describe()
        if not original.run_id:
            raise RuntimeError("Finish race has no original run identity.")
        for arguments in [[{"case_id": identifier}], [[1, 2]], []]:
            await handle.signal("updates-touch", args=arguments)
        request_id = f"{identifier}-increment"
        state = {"counter": 1, "mutations": [request_id]}
        response = await client.update_workflow(
            identifier, "increment", args=[increment_request("python", request_id, 1)],
            request_id=request_id, wait_for="completed", wait_timeout_seconds=45)
        expected_update = {"handler_runtime": "rust",
                           "request": increment_request("python", request_id, 1), "state": state}
        if response.get("update_status") != "completed":
            error = RuntimeError(f"Finish race mutation did not complete: {response!r}")
            await failure_diagnostics(client, handle, "rust", error, "finish_race_update")
            raise error
        actual_update = serializer.decode_envelope(response["result_envelope"], codec="avro")
        if not same_result(actual_update, expected_update):
            raise RuntimeError(f"Finish race mutation changed its result: {actual_update!r}")
        # Do not wait for another query or signal-wait checkpoint after the
        # update acknowledgement. Completion must survive that replay window.
        await handle.signal("updates-finish")
        result = await completion_result(client, handle, "rust")
        execution = await handle.describe()
        history = await history_for(client, identifier, original.run_id)
        verify_increment(history, request_id, "rust", "python", 1, state)
        expected = {"workflow_runtime": "rust", "request": identifier, "state": state}
        if (execution.run_id != original.run_id or execution.status != "completed"
                or not same_result(result, expected)
                or sum(event["event_type"] == "WorkflowCompleted" for event in history["events"]) != 1):
            raise RuntimeError("Finish race changed its original run, state or completion.")
        for signal_name, count in (("updates-touch", 3), ("updates-finish", 1)):
            received = [event["payload"].get("signal_id") for event in history["events"]
                        if event["event_type"] == "SignalReceived"
                        and event["payload"].get("signal_name") == signal_name]
            applied = [event["payload"].get("signal_id") for event in history["events"]
                       if event["event_type"] == "SignalApplied"
                       and event["payload"].get("signal_name") == signal_name]
            if (len(received) != count or len(applied) != count or None in received
                    or len(set(received)) != count or set(received) != set(applied)):
                raise RuntimeError("Finish race did not apply each original signal once.")
        emit(scenario="rust-update-finish-race", repetition=index, run_id=original.run_id,
             result=result, history=history)


async def finish(client):
    for runtime in RUNTIMES:
        execution, _ = await observe(client, runtime)
        handle = client.get_workflow_handle(execution.workflow_id)
        await handle.signal("updates-finish")
        result = await completion_result(client, handle, runtime)
        execution, history = await observe(client, runtime)
        if result != {"workflow_runtime": runtime, "request": workflow_id(runtime),
                      "state": counter_state(runtime, replaced=True)}:
            raise RuntimeError(f"Unexpected workflow result: {result!r}")
        if execution.status != "completed" or sum(event["event_type"] == "WorkflowCompleted"
                                                   for event in history["events"]) != 1:
            raise RuntimeError("Workflow did not complete once from its original run.")
        emit(scenario="workflow-completion", runtime=runtime, run_id=execution.run_id, result=result)


async def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("phase", choices=["start", "call", "matrix", "queued", "replacement", "snapshot", "failure", "finish", "finish_race",
                                         "state_matrix", "state_queued", "state_replacement", "state_duplicates"])
    parser.add_argument("arguments", nargs="*")
    args = parser.parse_args()
    async with Client(required("DURABLE_WORKFLOW_SERVER_URL"), token=required("DURABLE_WORKFLOW_AUTH_TOKEN"),
                      namespace=required("DURABLE_WORKFLOW_NAMESPACE"), timeout=60) as client:
        if args.phase == "call":
            if len(args.arguments) not in (3, 4):
                parser.error("call requires workflow ID, request ID, update name and optional increment delta")
            await call(client, *args.arguments)
        else:
            await globals()[args.phase](client)


if __name__ == "__main__":
    asyncio.run(main())
