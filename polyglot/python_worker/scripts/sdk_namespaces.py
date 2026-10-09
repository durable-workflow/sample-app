"""Provision disposable namespace credentials and verify original run histories."""

from __future__ import annotations

import argparse
import asyncio
import json
import os
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

from durable_workflow import Client, serializer
from principal_gateway import BODY_FIELDS, HEADERS

NAMESPACES = ("rust-namespace-a", "rust-namespace-b")
IDENTITIES = tuple((namespace, workflow_id)
                   for namespace in NAMESPACES
                   for workflow_id in (f"namespace-run-{namespace}", f"only-{namespace}"))
TERMINAL = {"WorkflowCompleted", "WorkflowFailed", "WorkflowCancelled", "WorkflowTerminated"}


def emit(**record):
    print(json.dumps(record, sort_keys=True), flush=True)


def retain(name, record):
    Path(os.environ["NAMESPACE_PROOF_DIR"], name + ".json").write_text(json.dumps(record, sort_keys=True, indent=2) + "\n")


def api(path, *, method="GET", body=None, namespace="default", status=200):
    headers = {"Authorization": "Bearer test-token", "Accept": "application/json",
               "Content-Type": "application/json", "X-Namespace": namespace,
               "X-Durable-Workflow-Control-Plane-Version": "2"}
    request = Request(os.environ["DURABLE_WORKFLOW_SERVER_URL"] + "/api" + path,
                      data=None if body is None else json.dumps(body).encode(),
                      method=method, headers=headers)
    try:
        response = urlopen(request, timeout=30)
    except HTTPError as error:
        response = error
    with response:
        actual = response.code
        result = json.loads(response.read())
    if actual != status:
        raise RuntimeError(f"{method} {path} returned {actual}: {result!r}")
    return result


def setup():
    for namespace in NAMESPACES:
        result = api("/namespaces", method="POST", status=201,
                     body={"name": namespace, "description": "Disposable published Rust namespace fixture",
                           "retention_days": 30})
        if result["name"] != namespace:
            raise RuntimeError("Created namespace identity differs.")
        for role in ("operator", "worker"):
            credential = f"fixture-{namespace}-{role}"
            token = f"dwr_fixture_{role}_{namespace.replace('-', '_')}_0123456789"
            result = api(f"/runtime-credentials/{credential}", method="PUT", status=201,
                         body={"token": token, "subject": credential, "roles": [role], "tenant": namespace})
            if result["roles"] != [role] or result["tenant"] != namespace:
                raise RuntimeError("Published credential did not retain its role and namespace binding.")
        emit(scenario="rust-namespace-provisioned", namespace=namespace, roles=["operator", "worker"])


def one(history, kind):
    found = [event for event in history["events"] if event["event_type"] == kind]
    if len(found) != 1:
        raise RuntimeError(f"Expected one {kind}, got {len(found)}.")
    return found[0]


def verify_principals(history, namespace, *, completed=False):
    roles = {"WorkflowStarted": "operator"}
    if any(row["event_type"] == "SignalReceived" for row in history["events"]):
        roles["SignalReceived"] = "operator"
    if completed:
        roles["WorkflowCompleted"] = "worker"
    observed = {}
    for kind, role in roles.items():
        expected = {"type": "auth:runtime-token", "id": f"fixture-{namespace}-{role}", "label": role.title()}
        actual = one(history, kind).get("principal")
        if actual != expected:
            raise RuntimeError(f"{kind} principal expected {expected!r}, got {actual!r}.")
        observed[kind] = actual
    # Every supplied principal, including optional events, must belong to this namespace.
    for row in history["events"]:
        if row.get("principal") is not None and row["principal"] not in (
            {"type": "auth:runtime-token", "id": f"fixture-{namespace}-{role}", "label": role.title()}
            for role in ("operator", "worker")
        ):
            raise RuntimeError("History includes a forged or foreign principal.")
    return observed


def verify_gateway(receipts):
    successful = [row for row in receipts if 200 <= row["status"] < 300]
    for row in successful:
        if row["headers"] != HEADERS or row["body_fields"] != BODY_FIELDS:
            raise RuntimeError("A successful gateway mutation omitted the forged identity matrix.")
    for namespace, workflow_id in IDENTITIES:
        starts = [row for row in successful if row["kind"] == "start" and row["namespace"] == namespace
                  and row["workflow_id"] == workflow_id]
        signals = [row for row in successful if row["kind"] == "signal" and row["namespace"] == namespace
                   and row["path"] == f"/api/workflows/{workflow_id}/signal/namespace-finish"]
        if len(starts) != 1 or len(signals) != 1:
            raise RuntimeError("Original Rust start or signal did not execute through the spoofing gateway once.")
    for namespace in NAMESPACES:
        completions = [row for row in successful if row["kind"] == "workflow-task-complete"
                       and row["namespace"] == namespace and "complete_workflow" in row["commands"]]
        activities = [row for row in successful if row["kind"] == "activity-complete"
                      and row["namespace"] == namespace]
        if len(completions) != 2 or len(activities) != 2 or len({row["activity_attempt_id"] for row in activities}) != 2:
            raise RuntimeError("Both Rust worker completions and original activity attempts must reach the gateway.")
    return successful


def parked_identity(history):
    started = one(history, "WorkflowStarted")["payload"]
    scheduled = one(history, "ActivityScheduled")["payload"]
    activity_started = one(history, "ActivityStarted")["payload"]
    completed = one(history, "ActivityCompleted")["payload"]
    opened = one(history, "SignalWaitOpened")["payload"]
    if (not started.get("workflow_run_id") or not scheduled.get("activity_execution_id")
            or scheduled["activity_execution_id"] != completed.get("activity_execution_id")
            or scheduled["activity_execution_id"] != activity_started.get("activity_execution_id")
            or not activity_started.get("activity_attempt_id")
            or activity_started["activity_attempt_id"] != completed.get("activity_attempt_id")
            or not opened.get("signal_wait_id") or opened.get("signal_name") != "namespace-finish"):
        raise RuntimeError("Run, activity, or signal wait lost its durable identity.")
    return {"run_id": started["workflow_run_id"], "activity_execution_id": scheduled["activity_execution_id"],
            "activity_attempt_id": activity_started["activity_attempt_id"],
            "wait_id": opened["signal_wait_id"], "wait_sequence": opened["sequence"]}


def verify_history(history, record):
    if parked_identity(history) != {key: record[key] for key in ("run_id", "activity_execution_id", "activity_attempt_id", "wait_id", "wait_sequence")}:
        raise RuntimeError("Worker replacement changed the original run, activity, or signal wait.")
    received = one(history, "SignalReceived")["payload"]
    applied = one(history, "SignalApplied")["payload"]
    if (not received.get("signal_id") or received["signal_id"] != applied.get("signal_id")
            or applied.get("signal_wait_id") != record["wait_id"]
            or applied.get("sequence") != record["wait_sequence"]
            or received.get("signal_name") != "namespace-finish"):
        raise RuntimeError("The acknowledged signal did not resolve the original wait once.")
    terminals = [event for event in history["events"] if event["event_type"] in TERMINAL]
    if len(terminals) != 1 or terminals[0]["event_type"] != "WorkflowCompleted":
        raise RuntimeError("Original namespace run did not complete exactly once.")
    kinds = [event["event_type"] for event in history["events"]]
    if not kinds.index("SignalReceived") < kinds.index("SignalApplied") < kinds.index("WorkflowCompleted"):
        raise RuntimeError("Completion did not follow the acknowledged signal.")
    namespace, workflow_id = record["namespace"], record["workflow_id"]
    request = {"namespace": namespace, "workflow_id": workflow_id}
    worker_id = f"rust-namespace-{namespace}"
    expected = {"namespace": namespace, "worker_id": worker_id, "request": request,
                "activity": {"namespace": namespace, "worker_id": worker_id, "request": request},
                "signal": request}
    result = serializer.decode_envelope(terminals[0]["payload"]["output"], codec="avro")
    activity = serializer.decode_envelope(one(history, "ActivityCompleted")["payload"]["result"], codec="avro")
    if result != expected or activity != expected["activity"]:
        raise RuntimeError("Durable namespace result or activity was produced by another namespace's worker.")
    return result


def records():
    result = [json.loads(line) for line in os.environ["DURABLE_WORKFLOW_NAMESPACE_RUNS"].splitlines()]
    if (len(result) != len(IDENTITIES)
            or {(row["namespace"], row["workflow_id"]) for row in result} != set(IDENTITIES)
            or len({row["run_id"] for row in result}) != len(IDENTITIES)):
        raise RuntimeError("Namespace records are missing, duplicated, or share a run identity.")
    return result


async def observe(client, workflow_id):
    description = await client.describe_workflow(workflow_id)
    events, token, seen = [], None, set()
    while True:
        page = await client.get_history(workflow_id, run_id=description.run_id, next_page_token=token)
        events.extend(page["events"])
        token = page.get("next_page_token")
        if not token:
            return description, {"events": events}
        if token in seen:
            raise RuntimeError("History pagination repeated a token.")
        seen.add(token)


async def inspect(phase):
    pending = records() if phase != "park" else None
    clients = {namespace: Client(os.environ["DURABLE_WORKFLOW_SERVER_URL"], token="test-token", namespace=namespace)
               for namespace in NAMESPACES}
    try:
        for namespace, workflow_id in IDENTITIES:
            client = clients[namespace]
            if phase == "park":
                deadline = asyncio.get_running_loop().time() + 90
                while asyncio.get_running_loop().time() < deadline:
                    description, history = await observe(client, workflow_id)
                    kinds = {event["event_type"] for event in history["events"]}
                    if kinds & TERMINAL:
                        raise RuntimeError("Namespace workflow closed before its declared wait.")
                    if description.status == "waiting" and "SignalWaitOpened" in kinds:
                        if "SignalReceived" in kinds:
                            raise RuntimeError("Denied cross-namespace signaling mutated a workflow.")
                        identity = parked_identity(history)
                        principals = verify_principals(history, namespace)
                        if identity["run_id"] != description.run_id:
                            raise RuntimeError("Description and history selected different runs.")
                        emit(scenario="rust-namespace-parked", namespace=namespace, workflow_id=workflow_id, **identity)
                        retain(f"park-{workflow_id}", {"namespace": namespace, "workflow_id": workflow_id,
                                                      "history": history, "principals": principals, **identity})
                        break
                    await asyncio.sleep(.25)
                else:
                    raise RuntimeError("Namespace workflow did not reach its declared wait.")
            else:
                record = next(row for row in pending if (row["namespace"], row["workflow_id"]) == (namespace, workflow_id))
                description, history = await observe(client, workflow_id)
                if description.run_id != record["run_id"]:
                    raise RuntimeError("A replacement run hid failed recovery.")
                if phase == "released":
                    one(history, "SignalReceived")
                    verify_principals(history, namespace)
                    if any(event["event_type"] in TERMINAL | {"SignalApplied"} for event in history["events"]):
                        raise RuntimeError("A killed worker applied the signal or completed the workflow.")
                    if parked_identity(history)["wait_id"] != record["wait_id"]:
                        raise RuntimeError("Acknowledgment replaced the original wait.")
                    retain(f"released-{workflow_id}", {"history": history, **record})
                else:
                    if description.status != "completed":
                        raise RuntimeError("Public namespace workflow status is not completed.")
                    result = verify_history(history, record)
                    principals = verify_principals(history, namespace, completed=True)
                    emit(scenario="rust-namespace-recovered", **{key: value for key, value in record.items() if key != "scenario"},
                         result=result, principals=principals, events=[event["event_type"] for event in history["events"]])
                    retain(f"verify-{workflow_id}", {"history": history, "result": result, "principals": principals, **record})
        if phase == "verify":
            receipts = [json.loads(line) for line in Path(os.environ["NAMESPACE_PROOF_DIR"], "gateway.jsonl").read_text().splitlines()]
            successful = verify_gateway(receipts)
            emit(scenario="rust-principal-spoofing-refused", workflows=len(IDENTITIES),
                 successful_mutations=len(successful), body_fields=list(BODY_FIELDS), headers=list(HEADERS))
            for namespace in (*NAMESPACES, "default"):
                rejected = api("/workflows/denied-cross-namespace-start", namespace=namespace, status=404)
                if rejected.get("reason") != "instance_not_found":
                    raise RuntimeError("Rejected start was persisted in a namespace.")
    finally:
        for client in clients.values():
            await client.aclose()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("setup", "park", "released", "verify"))
    phase = parser.parse_args().phase
    if phase == "setup":
        setup()
    else:
        asyncio.run(inspect(phase))
