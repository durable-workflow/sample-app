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
    contract = api("/cluster/info")["principal_attribution_contract"]
    guards = contract["spoofing_guards"]
    if (guards["request_body_field_values"] != BODY_FIELDS
            or guards["request_header_values"] != HEADERS
            or set(guards["request_body_fields"]) != set(BODY_FIELDS)
            or set(guards["request_headers"]) != set(HEADERS)):
        raise RuntimeError("Gateway injections differ from the published Server principal contract.")
    retain("principal-contract", contract)
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


def verify_rotation(before, after, repeated):
    for key in ("id", "subject", "roles", "tenant", "claims", "created_at", "expires_at"):
        if before[key] != after[key] or after[key] != repeated[key]:
            raise RuntimeError("Rotation changed the authenticated identity, authority or namespace.")
    if (not after.get("rotated_at") or after.get("revoked_at") is not None or repeated.get("revoked_at") is not None
            or after["rotated_at"] != repeated.get("rotated_at")
            or before.get("rotated_at") is not None):
        raise RuntimeError("Rotation or its duplicate lost the original stable rotation boundary.")
    return {key: after[key] for key in ("id", "subject", "roles", "tenant", "claims", "created_at", "expires_at", "rotated_at")}


def rotate():
    results = []
    for namespace in NAMESPACES:
        for role in ("operator", "worker"):
            credential = f"fixture-{namespace}-{role}"
            path = f"/runtime-credentials/{credential}"
            before = api(path)
            token = f"dwr_fixture_{role}_{namespace.replace('-', '_')}_0123456789_rotated"
            after = api(path + "/rotate", method="POST", body={"token": token})
            repeated = api(path + "/rotate", method="POST", body={"token": token})
            results.append(verify_rotation(before, after, repeated))
    retain("credential-rotation", {"credentials": results, "duplicate_rotation_preserved": True})
    emit(scenario="rust-namespace-credentials-rotated", credentials=results,
         duplicate_rotation_preserved=True)


def expected_principal(namespace, role, *, anonymous=False):
    return ({"type": "server", "id": "anonymous", "label": "Admin"} if anonymous else
            {"type": "auth:runtime-token", "id": f"fixture-{namespace}-{role}", "label": role.title()})


def verify_principals(history, namespace, *, completed=False, anonymous=False):
    roles = {"WorkflowStarted": "operator"}
    if any(row["event_type"] == "SignalReceived" for row in history["events"]):
        roles["SignalReceived"] = "operator"
    if completed:
        roles["WorkflowCompleted"] = "worker"
    observed = {}
    for kind, role in roles.items():
        expected = expected_principal(namespace, role, anonymous=anonymous)
        actual = one(history, kind).get("principal")
        if actual != expected:
            raise RuntimeError(f"{kind} principal expected {expected!r}, got {actual!r}.")
        observed[kind] = actual
    # Every supplied principal, including optional events, must belong to this namespace.
    for row in history["events"]:
        if row.get("principal") is not None and row["principal"] not in (
            expected_principal(namespace, role, anonymous=anonymous)
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


def verify_operation_history(history, namespace, started, terminal, waiting=None, *, anonymous=False):
    if one(history, "WorkflowStarted")["payload"]["workflow_run_id"] != started["run_id"]:
        raise RuntimeError("Principal operation changed its original run.")
    terminals = [row for row in history["events"] if row["event_type"] in TERMINAL]
    if len(terminals) != 1 or terminals[0]["event_type"] != terminal:
        raise RuntimeError("Principal operation has a missing, duplicate or wrong terminal event.")
    for kind, role in (("WorkflowStarted", "operator"), (terminal, "worker" if terminal == "WorkflowFailed" else "operator")):
        expected = expected_principal(namespace, role, anonymous=anonymous)
        if one(history, kind).get("principal") != expected:
            raise RuntimeError("Principal operation recorded a missing, forged or wrong-role actor.")
    if terminal == "WorkflowFailed":
        if "principal-fixture-failure" not in json.dumps(terminals[0]["payload"]):
            raise RuntimeError("Workflow failure did not come from the actual Rust fixture handler.")
    if waiting is not None:
        before = waiting["history"]["events"]
        if before != history["events"][:len(before)]:
            raise RuntimeError("Terminal cancellation rewrote the original wait history.")
        one(history, "SignalWaitOpened")
        if any(row["event_type"] in {"SignalReceived", "SignalApplied", "WorkflowCompleted", "WorkflowFailed"}
               for row in history["events"]):
            raise RuntimeError("Terminal cancellation resumed the fixture workflow.")
    if anonymous:
        verify_principals(history, namespace, anonymous=True)
    return terminals[0]


def verify_query_receipt(receipt, record, history_before, history_after, *, anonymous=False):
    expected = expected_principal(record["namespace"], "operator", anonymous=anonymous)
    if (receipt.get("response_principal") != expected or receipt.get("response_run_id") != record["run_id"]
            or receipt.get("status") != 200 or receipt.get("headers") != HEADERS or receipt.get("body_fields") != BODY_FIELDS):
        raise RuntimeError("Query lost its server-controlled audit actor, selected run or injected execution.")
    if anonymous and receipt.get("authorization_present") is not False:
        raise RuntimeError("Anonymous query supplied a credential or lacks its transport observation.")
    if not isinstance(receipt.get("result_envelope"), dict):
        raise RuntimeError("Query response has no published Avro result envelope.")
    result = serializer.decode_envelope(receipt["result_envelope"], codec="avro")
    if (result != record["result"] or result.get("principal") != {"type": "attacker", "id": "mallory"}
            or result["workflow_id"] != record["workflow_id"] or result["run_id"] != record["run_id"]):
        raise RuntimeError("Query result does not match the actual Rust-selected run or forged application field.")
    if history_before != history_after:
        raise RuntimeError("Read-only query mutated the completed workflow's committed history.")
    return {"audit_principal": receipt["response_principal"], "application_principal": result["principal"],
            "original_run_id": record["run_id"], "history_unchanged": True}


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


def verify_anonymous_transport(receipts):
    successful = [row for row in receipts if row["namespace"] == "default" and 200 <= row["status"] < 300]
    if not successful or any(row.get("authorization_present") is not False or row["headers"] != HEADERS
                             or row["body_fields"] != BODY_FIELDS for row in successful):
        raise RuntimeError("Anonymous execution supplied credentials or bypassed the forged metadata matrix.")
    for kind, count in (("start", 3), ("signal", 1), ("activity-complete", 1), ("query", 1), ("cancel", 1)):
        if sum(row["kind"] == kind for row in successful) != count:
            raise RuntimeError("An actual anonymous principal operation is missing or duplicated.")
    commands = [command for row in successful for command in row["commands"]]
    if commands.count("complete_workflow") != 1 or commands.count("fail_workflow") != 1:
        raise RuntimeError("Anonymous workflow completion or authored failure did not execute once.")
    return successful


async def inspect_anonymous(phase):
    workflow_id = "namespace-run-default"
    client = Client(os.environ["DURABLE_WORKFLOW_SERVER_URL"], namespace="default")
    try:
        if phase == "anonymous-park":
            deadline = asyncio.get_running_loop().time() + 90
            while asyncio.get_running_loop().time() < deadline:
                description, history = await observe(client, workflow_id)
                if any(row["event_type"] in TERMINAL for row in history["events"]):
                    raise RuntimeError("Anonymous workflow closed before its declared wait.")
                if description.status == "waiting" and any(row["event_type"] == "SignalWaitOpened" for row in history["events"]):
                    identity = parked_identity(history)
                    if identity["run_id"] != description.run_id:
                        raise RuntimeError("Anonymous description and history selected different runs.")
                    principals = verify_principals(history, "default", anonymous=True)
                    retain(f"park-{workflow_id}", {"namespace": "default", "workflow_id": workflow_id,
                           "history": history, "principals": principals, **identity})
                    break
                await asyncio.sleep(.25)
            else:
                raise RuntimeError("Anonymous workflow did not reach its declared wait.")
        else:
            before = json.loads(Path(os.environ["NAMESPACE_PROOF_DIR"], f"park-{workflow_id}.json").read_text())
            description, history = await observe(client, workflow_id)
            if (description.status != "completed" or description.run_id != before["run_id"]
                    or before["history"]["events"] != history["events"][:len(before["history"]["events"])]):
                raise RuntimeError("Anonymous completion changed its original run or committed wait history.")
            result = verify_history(history, before)
            principals = verify_principals(history, "default", completed=True, anonymous=True)
            retain(f"verify-{workflow_id}", {**before, "history": history, "result": result, "principals": principals})
            emit(scenario="rust-anonymous-completed", workflow_id=workflow_id, run_id=description.run_id, principals=principals)
    finally:
        await client.aclose()


async def inspect_operations(phase, *, anonymous=False):
    proof = Path(os.environ["NAMESPACE_PROOF_DIR"])
    receipts = [json.loads(line) for line in (proof / "gateway.jsonl").read_text().splitlines()]
    for namespace in (("default",) if anonymous else NAMESPACES):
        client = Client(os.environ["DURABLE_WORKFLOW_SERVER_URL"], token=None if anonymous else "test-token", namespace=namespace)
        try:
            cancelled_id = f"principal-cancel-{namespace}"
            if phase == "operations-park":
                deadline = asyncio.get_running_loop().time() + 90
                while asyncio.get_running_loop().time() < deadline:
                    description, history = await observe(client, cancelled_id)
                    if description.status == "waiting" and any(row["event_type"] == "SignalWaitOpened" for row in history["events"]):
                        one(history, "SignalWaitOpened")
                        retain(f"principal-park-{cancelled_id}", {"run_id": description.run_id, "history": history})
                        break
                    if description.status in {"failed", "completed", "cancelled", "terminated"}:
                        raise RuntimeError("Cancellation probe closed before its declared wait.")
                    await asyncio.sleep(.25)
                else:
                    raise RuntimeError("Cancellation probe never reached its declared wait.")
                continue
            query = json.loads((proof / f"{namespace}-query.json").read_text())
            before = json.loads((proof / f"verify-{query['workflow_id']}.json").read_text())["history"]
            _, after = await observe(client, query["workflow_id"])
            path = f"/api/workflows/{query['workflow_id']}/runs/{query['run_id']}/query/namespace-principal"
            matches = [row for row in receipts if row["kind"] == "query" and row["path"] == path and row["namespace"] == namespace]
            if len(matches) != 1:
                raise RuntimeError("Actual selected-run Rust query gateway receipt is missing or duplicated.")
            query_review = verify_query_receipt(matches[0], query, before, after, anonymous=anonymous)
            outcomes = []
            for workflow_id, terminal in ((f"principal-failure-{namespace}", "WorkflowFailed"), (cancelled_id, "WorkflowCancelled")):
                started = json.loads((proof / f"start-{workflow_id}.json").read_text())
                description, history = await observe(client, workflow_id)
                waiting = json.loads((proof / f"principal-park-{workflow_id}.json").read_text()) if terminal == "WorkflowCancelled" else None
                end = verify_operation_history(history, namespace, started, terminal, waiting, anonymous=anonymous)
                kind = "cancel" if terminal == "WorkflowCancelled" else "workflow-task-complete"
                mutations = [row for row in receipts if row["kind"] == kind and row["namespace"] == namespace
                             and 200 <= row["status"] < 300 and
                             (row["path"] == f"/api/workflows/{workflow_id}/cancel" if kind == "cancel" else "fail_workflow" in row["commands"])]
                starts = [row for row in receipts if row["kind"] == "start" and row["workflow_id"] == workflow_id
                          and row["namespace"] == namespace and row["status"] == 201]
                if len(mutations) != 1 or len(starts) != 1:
                    raise RuntimeError("Actual Rust principal operation start/terminal mutation did not reach Server once.")
                for mutation in (*mutations, *starts):
                    if mutation["headers"] != HEADERS or mutation["body_fields"] != BODY_FIELDS:
                        raise RuntimeError("A principal operation bypassed the forged identity matrix.")
                outcome = {"workflow_id": workflow_id, "run_id": description.run_id, "terminal": terminal, "principal": end["principal"]}
                retain(f"principal-verify-{workflow_id}", {"history": history, **outcome})
                outcomes.append(outcome)
            retain(f"principal-operations-{namespace}", {"query": query_review, "terminals": outcomes})
            if anonymous:
                successful = verify_anonymous_transport(receipts)
                retain("anonymous-transport", {"auth_driver": "none", "authorization_absent": True,
                       "successful_requests": len(successful)})
            emit(scenario="rust-principal-operations-pass", namespace=namespace, anonymous=anonymous, query=query_review, terminals=outcomes)
        finally:
            await client.aclose()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("setup", "rotate", "park", "released", "verify", "operations-park", "operations-verify",
                        "anonymous-park", "anonymous-verify", "anonymous-operations-park", "anonymous-operations-verify"))
    phase = parser.parse_args().phase
    if phase == "setup":
        setup()
    elif phase == "rotate":
        rotate()
    elif phase.startswith("anonymous-operations-"):
        asyncio.run(inspect_operations(phase.removeprefix("anonymous-"), anonymous=True))
    elif phase.startswith("anonymous-"):
        asyncio.run(inspect_anonymous(phase))
    elif phase.startswith("operations-"):
        asyncio.run(inspect_operations(phase))
    else:
        asyncio.run(inspect(phase))
