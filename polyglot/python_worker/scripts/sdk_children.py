"""Observe published SDK child results, typed failures and cold recovery."""

from __future__ import annotations

import argparse
import asyncio
import json
import os
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote

from durable_workflow import Client, serializer
from principal_gateway import BODY_FIELDS, HEADERS

RUNTIMES = ("php", "python", "rust")
DIRECTIONS = tuple((parent, child) for parent in RUNTIMES for child in RUNTIMES)
RUST_DIRECTIONS = tuple(direction for direction in DIRECTIONS if "rust" in direction)
PARENT_EVENTS = {"WorkflowStarted", "ChildWorkflowScheduled", "ChildRunStarted",
                 "ChildRunCompleted", "ChildRunFailed", "ChildRunCancelled", "ChildRunTerminated",
                 "WorkflowCompleted", "WorkflowFailed", "WorkflowCancelled", "WorkflowTerminated"}
TERMINAL_EVENTS = {"WorkflowCompleted", "WorkflowFailed", "WorkflowCancelled", "WorkflowTerminated"}
CANCELLATION_ACTOR = {"id": "legacy-token", "label": "Admin", "type": "auth:token"}


def required(name):
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before running SDK children.")
    return value


def emit(**record):
    print(json.dumps(record, sort_keys=True), flush=True)
    if os.environ.get("CHILD_PROOF_DIR") and all(record.get(key) for key in ("scenario", "parent", "child")):
        path = Path(os.environ["CHILD_PROOF_DIR"]) / f"{record['scenario']}-{record['parent']}-{record['child']}.json"
        path.write_text(json.dumps(record, sort_keys=True, indent=2) + "\n")


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


async def park(client, behavior="wait"):
    for parent, child in RUST_DIRECTIONS:
        _, record = await start(client, parent, child, behavior)
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


def instant(value):
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def markers(history, role, runtime):
    values = [serializer.decode_envelope(event["payload"]["result"], codec="avro")
              for event in history["events"] if event["event_type"] == "SideEffectRecorded"]
    if (not values or len(values) > 2
            or [value.get("stage") for value in values] != ["entry", "finished"][:len(values)]
            or any(value.get("role") != role or value.get("runtime") != runtime for value in values)):
        raise RuntimeError("Cleanup did not retain its ordered, original side-effect markers.")
    return values


def cancellation_actor(history, role):
    event = one(history, "CooperativeCancellationRequested")
    context = event["payload"]["cancellation"]
    if ("principal" not in event or event["principal"] != (CANCELLATION_ACTOR if role == "parent" else None)
            or context.get("requester") != CANCELLATION_ACTOR or context.get("source") != "control_plane"):
        raise RuntimeError("Cooperative cancellation lost its authenticated requester or internal propagation classification.")
    return context


def cancellation_context(history, record, role):
    context = cancellation_actor(history, role)
    root = record["root_request"]
    expected_run = record["parent_run_id"] if role == "parent" else record["child_workflow_run_id"]
    expected_workflow = record["parent_workflow_id"] if role == "parent" else record["child_workflow_instance_id"]
    if (context.get("schema") != "durable-workflow.cancellation-context/v1"
            or context.get("root_request_id") != root["request_id"]
            or context.get("root_workflow_run_id") != record["parent_run_id"]
            or context.get("root_workflow_instance_id") != record["parent_workflow_id"]
            or context.get("requested_at") != root["requested_at"]
            or context.get("cleanup_deadline_at") != root["cleanup_deadline_at"]
            or context.get("reason") != "SDK child cancellation conformance"):
        raise RuntimeError("Cancellation lost its root identity, reason or original deadline.")
    lineage = context.get("lineage", [])
    if (len(lineage) != (1 if role == "parent" else 2)
            or lineage[0] != {"request_id": root["request_id"],
                "workflow_instance_id": record["parent_workflow_id"], "workflow_run_id": record["parent_run_id"]}
            or lineage[-1] != {"request_id": context["request_id"],
                "workflow_instance_id": expected_workflow, "workflow_run_id": expected_run}
            or context.get("parent_request_id") != (None if role == "parent" else root["request_id"])):
        raise RuntimeError("Cancellation did not retain one parent-child lineage.")
    if role == "child":
        parent = record["root_context"]
        if context["request_id"] == root["request_id"] or any(context[key] != parent[key] for key in ("requester", "source")):
            raise RuntimeError("Child cancellation lost its propagated requester or source.")
    return context


async def cancellation_start(client):
    await park(client, "cancel")


async def cancellation_request(client):
    for record in records():
        handle = client.get_workflow_handle(record["parent_workflow_id"], run_id=record["parent_run_id"])
        response = await handle.request_cancellation(reason="SDK child cancellation conformance", cleanup_timeout_seconds=30)
        root = response["cancellation_request"]
        if response.get("duplicate") or not root.get("request_id") or (instant(root["cleanup_deadline_at"]) - instant(root["requested_at"])).total_seconds() != 30:
            raise RuntimeError("Cancellation did not accept one original 30-second cleanup budget.")
        record["root_request"] = root
        emit(scenario="child-cancellation-requested", **record)


async def cancellation_park(client):
    for record in records():
        while datetime.now(timezone.utc) < instant(record["root_request"]["cleanup_deadline_at"]):
            _, parent = await observe(client, record["parent_workflow_id"], record["parent_run_id"])
            _, child = await observe(client, record["child_workflow_instance_id"], record["child_workflow_run_id"])
            if any(event["event_type"] in TERMINAL_EVENTS for event in child["events"]):
                emit(scenario="child-closed-before-cleanup-restart", **record, child_events=child["events"])
                raise RuntimeError("Child closed before worker loss during cleanup.")
            if (any(event["event_type"] == "SideEffectRecorded" for event in child["events"])
                    and any(event["event_type"] == "TimerScheduled" for event in child["events"])):
                if any(event["event_type"] == "CooperativeCancellationDelivered" for event in parent["events"]):
                    raise RuntimeError("WAIT_CANCELLATION_COMPLETED delivered before the child finished cleanup.")
                record["root_context"] = cancellation_context(parent, record, "parent")
                context = cancellation_context(child, record, "child")
                entry = markers(child, "child", record["child"])
                delivery = one(child, "CooperativeCancellationDelivered")
                if len(entry) != 1 or entry[0]["context"] != context or delivery["payload"]["cancellation"] != context:
                    raise RuntimeError("Child cleanup did not use its committed cancellation delivery.")
                record.update(child_delivery=delivery, child_cleanup_entry=entry[0], child_cleanup_timer=one(child, "TimerScheduled"))
                emit(scenario="child-cleanup-before-worker-loss", **record)
                break
            await asyncio.sleep(.1)
        else:
            raise RuntimeError("Child cleanup did not start within its original cancellation deadline.")


async def cancellation_duplicate(client):
    for record in records():
        handle = client.get_workflow_handle(record["parent_workflow_id"], run_id=record["parent_run_id"])
        response = await handle.request_cancellation(reason="duplicate must not replace original", cleanup_timeout_seconds=60)
        root = response["cancellation_request"]
        if not response.get("duplicate") or any(root.get(key) != record["root_request"][key] for key in ("request_id", "requested_at", "cleanup_deadline_at")):
            raise RuntimeError("Duplicate cancellation replaced the original identity or deadline.")
        _, parent = await observe(client, record["parent_workflow_id"], record["parent_run_id"])
        if cancellation_context(parent, record, "parent") != record["root_context"]:
            raise RuntimeError("Duplicate cancellation replaced its original authenticated requester or context.")
        _, child = await observe(client, record["child_workflow_instance_id"], record["child_workflow_run_id"])
        if (one(child, "CooperativeCancellationDelivered") != record["child_delivery"]
                or markers(child, "child", record["child"]) != [record["child_cleanup_entry"]]
                or any(event["event_type"] in TERMINAL_EVENTS for event in child["events"])):
            raise RuntimeError("An absent worker unexpectedly changed or finished child cleanup.")
        emit(scenario="child-cancellation-duplicate-without-workers", **record)


def verify_cancelled(parent, child, record):
    if child_identity(parent, record["child_type"]) != {key: record[key] for key in child_identity(parent, record["child_type"])}:
        raise RuntimeError("Cancellation replaced the original child relationship.")
    if wait_identity(child, record["child"]) != {key: record[key] for key in ("wait_id", "wait_sequence")}:
        raise RuntimeError("Cancellation replaced the original child wait.")
    if (one(child, "CooperativeCancellationDelivered") != record["child_delivery"]
            or one(child, "TimerScheduled") != record["child_cleanup_timer"]):
        raise RuntimeError("Replacement changed the committed cancellation boundary or cleanup timer.")
    for role, history, runtime in (("parent", parent, record["parent"]), ("child", child, record["child"])):
        terminal = [event for event in history["events"] if event["event_type"] in TERMINAL_EVENTS]
        if len(terminal) != 1 or terminal[0]["event_type"] != "WorkflowCancelled":
            raise RuntimeError("Original run did not terminate as Cancelled exactly once.")
        context = cancellation_context(history, record, role)
        delivery = one(history, "CooperativeCancellationDelivered")
        if ("principal" not in delivery or delivery["principal"] is not None
                or "principal" not in terminal[0] or terminal[0]["principal"] is not None
                or delivery["payload"]["cancellation"] != context):
            raise RuntimeError("Cleanup changed its committed requester or internal event classification.")
        values = markers(history, role, runtime)
        if (len(values) != 2 or any(value["context"] != context for value in values)
                or not 0 < values[1]["remaining"] < values[0]["remaining"] <= 30
                or (role == "child" and values[0] != record["child_cleanup_entry"])):
            raise RuntimeError("Cleanup did not replay its context and decreasing original budget.")
        scheduled, fired = one(history, "TimerScheduled"), one(history, "TimerFired")
        if scheduled["payload"]["timer_id"] != fired["payload"]["timer_id"]:
            raise RuntimeError("Cleanup fired a replacement timer.")
        cleanup = terminal[0]["payload"].get("cancellation_cleanup", {})
        if (cleanup.get("outcome") != "completed" or cleanup.get("request_id") != context["request_id"]
                or cleanup.get("cleanup_deadline_at") != context["cleanup_deadline_at"]
                or cleanup.get("delivery_sequence") != delivery["payload"]["sequence"]
                or not cleanup.get("delivery_history_event_id")
                or instant(cleanup["finished_at"]) >= instant(context["cleanup_deadline_at"])
                or instant(terminal[0]["timestamp"]) >= instant(context["cleanup_deadline_at"])):
            raise RuntimeError("Cleanup did not complete under its original delivery and deadline.")
    if instant(one(parent, "CooperativeCancellationDelivered")["timestamp"]) < instant(one(child, "WorkflowCancelled")["timestamp"]):
        raise RuntimeError("Parent resumed before its WAIT_CANCELLATION_COMPLETED child settled.")
    settled = one(parent, "ChildRunCancelled")["payload"]
    if any(settled.get(key) != record[key] for key in ("child_workflow_instance_id", "child_workflow_run_id", "workflow_link_id", "child_call_id")):
        raise RuntimeError("Cancellation settled a different child relationship.")


def verify_cascade_request(actual, context):
    # Published cascade metadata uses graph edges for lineage.
    expected = {key: value for key, value in context.items() if key != "lineage"}
    if actual != expected:
        raise RuntimeError("Cascade request metadata changed its original requester, identity or budget.")


async def inspect_cascade(record, parent, child):
    import httpx
    base = required("DURABLE_WORKFLOW_SERVER_URL").rstrip("/")
    api = base if base.endswith("/api") else base + "/api"
    path = f"/workflows/{quote(record['parent_workflow_id'], safe='')}/runs/{quote(record['parent_run_id'], safe='')}/debug"
    async with httpx.AsyncClient(timeout=15, headers={"Authorization": "Bearer " + required("DURABLE_WORKFLOW_AUTH_TOKEN"),
            "X-Namespace": required("DURABLE_WORKFLOW_NAMESPACE"), "Accept": "application/json",
            "X-Durable-Workflow-Control-Plane-Version": "2"}) as client:
        response = await client.get(api + path)
        response.raise_for_status()
        view = response.json()["cancellation_cascade"]
    process = await asyncio.create_subprocess_exec("dw", "debug", "workflow", record["parent_workflow_id"],
        "--run-id=" + record["parent_run_id"], "--server=" + base.removesuffix("/api"),
        "--namespace=" + required("DURABLE_WORKFLOW_NAMESPACE"), "--output=json", "--no-ansi",
        stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE)
    try:
        output, errors = await asyncio.wait_for(process.communicate(), 15)
    except BaseException:
        process.kill()
        await process.wait()
        raise
    if process.returncode or json.loads(output)["cancellation_cascade"] != view:
        raise RuntimeError(f"Published CLI differs from the Server cancellation cascade: {errors.decode()}")
    emit(scenario="child-cancellation-cascade-observed", parent=record["parent"], child=record["child"],
         cancellation_cascade=view)
    verify_cascade_request(view["root"], record["root_context"])
    if (view.get("schema") != "durable-workflow.cancellation-cascade/v1"
            or view.get("selected_run_id") != record["parent_run_id"]
            or view.get("inspection_complete") is not True or view.get("truncated") is not False
            or view.get("findings") != [] or len(view.get("runs", [])) != 2 or len(view.get("edges", [])) != 1
            or view["root"]["root_request_id"] != record["root_request"]["request_id"]
            or view["root"]["cleanup_deadline_at"] != record["root_request"]["cleanup_deadline_at"]):
        raise RuntimeError("Operator view does not explain the complete original cancellation cascade.")
    nodes = {node["run_id"]: node for node in view["runs"]}
    for role, run_id, history in (("parent", record["parent_run_id"], parent), ("child", record["child_workflow_run_id"], child)):
        node = nodes[run_id]
        delivery = one(history, "CooperativeCancellationDelivered")["payload"]
        cleanup = one(history, "WorkflowCancelled")["payload"]["cancellation_cleanup"]
        verify_cascade_request(node["request"], cancellation_context(history, record, role))
        if (node["lifecycle"] != "cancelled" or node["same_root_budget"] is not True
                or node["cleanup"] != cleanup or node["delivery"]["sequence"] != delivery["sequence"]
                or node["delivery"]["history_event_id"] != cleanup["delivery_history_event_id"]):
            raise RuntimeError("Operator view contradicts a durable cancellation outcome or delivery.")
    edge = view["edges"][0]
    if edge["parent_run_id"] != record["parent_run_id"] or edge["child_run_id"] != record["child_workflow_run_id"]:
        raise RuntimeError("Operator view links different original runs.")
    return view


def verify_cancellation_transport(pending, receipts):
    selected = [row for row in receipts if row["kind"] == "cooperative-cancel"]
    expected_paths = {f"/api/workflows/{quote(row['parent_workflow_id'], safe='')}/runs/{quote(row['parent_run_id'], safe='')}/request-cancellation"
                      for row in pending}
    if (len(selected) != len(pending) * 2 or {row["path"] for row in selected} != expected_paths
            or any(sum(row["path"] == path for row in selected) != 2 for path in expected_paths)
            or any(row["status"] not in (200, 202) or row["namespace"] != "default" or row["authorization_present"] is not True
                   or row["body_fields"] != BODY_FIELDS or row["headers"] != HEADERS for row in selected)):
        raise RuntimeError("Original and duplicate cancellation lack actual successful forged-metadata request receipts.")
    for record in pending:
        path = f"/api/workflows/{quote(record['parent_workflow_id'], safe='')}/runs/{quote(record['parent_run_id'], safe='')}/request-cancellation"
        pair = [row for row in selected if row["path"] == path]
        if ([(row["status"], row.get("response_duplicate")) for row in pair] != [(202, False), (200, True)]
                or any(any(row.get("response_cancellation_request", {}).get(key) != record["root_request"][key]
                           for key in ("request_id", "requested_at", "cleanup_deadline_at")) for row in pair)):
            raise RuntimeError("Transport responses replaced the original cooperative identity or deadline.")
    return {"directions": len(pending), "original_and_duplicate_requests": len(selected),
            "body_fields": len(BODY_FIELDS), "headers": len(HEADERS), "authenticated_requester": CANCELLATION_ACTOR}


async def cancellation_verify(client):
    for record in records():
        while datetime.now(timezone.utc) < instant(record["root_request"]["cleanup_deadline_at"]):
            parent_execution, parent = await observe(client, record["parent_workflow_id"], record["parent_run_id"])
            child_execution, child = await observe(client, record["child_workflow_instance_id"], record["child_workflow_run_id"])
            if parent_execution.status == "cancelled" and child_execution.status == "cancelled":
                break
            if parent_execution.status in ("failed", "terminated", "completed") or child_execution.status in ("failed", "terminated", "completed"):
                emit(scenario="child-cancellation-unexpected-outcome", **record, parent_events=parent["events"], child_events=child["events"])
                raise RuntimeError("Cancellation reached an unexpected terminal outcome.")
            await asyncio.sleep(.1)
        else:
            raise RuntimeError("Original cancellation deadline expired before convergence.")
        verify_cancelled(parent, child, record)
        view = await inspect_cascade(record, parent, child)
        emit(scenario="child-cancellation-worker-recovery", **record, parent_events=parent["events"], child_events=child["events"],
             cancellation_cascade=view, elapsed_seconds=(instant(one(parent, "WorkflowCancelled")["timestamp"])
                 - instant(record["root_request"]["requested_at"])).total_seconds())
    proof = Path(required("CHILD_PROOF_DIR"))
    receipts = [json.loads(line) for line in (proof / "gateway.jsonl").read_text().splitlines()]
    emit(scenario="child-cooperative-principal-transport-pass", **verify_cancellation_transport(records(), receipts))


async def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("phase", choices=["matrix", "failure", "park", "release", "recovery", "cancellation_start",
        "cancellation_request", "cancellation_park", "cancellation_duplicate", "cancellation_verify"])
    args = parser.parse_args()
    async with Client(required("DURABLE_WORKFLOW_SERVER_URL"), token=required("DURABLE_WORKFLOW_AUTH_TOKEN"),
                      namespace=required("DURABLE_WORKFLOW_NAMESPACE"), timeout=60) as client:
        await globals()[args.phase](client)


if __name__ == "__main__":
    asyncio.run(main())
