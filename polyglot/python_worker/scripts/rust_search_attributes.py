"""Observe published Rust typed updates and original-history cold recovery."""

from __future__ import annotations

import argparse
import asyncio
import json
import os
from datetime import datetime
from pathlib import Path

from durable_workflow import Client

WORKFLOW = "sample-app.rust.search-attributes"
QUEUE = "rust-search-attributes"
SIGNAL = "search-release"
DEFINITIONS = {
    "SearchText": "string", "SearchKeyword": "keyword", "SearchTags": "keyword_list",
    "SearchCount": "int", "SearchRatio": "float", "SearchFlag": "bool",
    "SearchTime": "datetime", "SearchRemoved": "keyword",
}
REGISTERED_TYPES = {key: "double" if kind == "float" else kind for key, kind in DEFINITIONS.items()}
INITIAL = {
    "SearchText": "界" * 682 + "ab", "SearchKeyword": "界" * 85,
    "SearchTags": ["urgent", "界" * 85, "urgent"],
    "SearchCount": 9_007_199_254_740_993, "SearchRatio": 1.25, "SearchFlag": True,
    "SearchTime": "2026-10-08T12:34:56.123456Z", "SearchRemoved": "erase",
}
MUTATION = {
    "SearchText": "finished café 界", "SearchKeyword": "finished",
    "SearchTags": ["done", "café"], "SearchFlag": False, "SearchRemoved": None,
}
FINAL = {**INITIAL, **MUTATION}
del FINAL["SearchRemoved"]
PROOF = Path(os.environ.get("SEARCH_ATTRIBUTES_PROOF", "/proof"))
WORKFLOW_ID = "rust-search-attributes-recovery"


def record(phase: str, value: dict) -> None:
    PROOF.mkdir(parents=True, exist_ok=True)
    (PROOF / f"{phase}.json").write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps({"phase": phase, **value}, ensure_ascii=False), flush=True)


def events(history: dict, kind: str) -> list[dict]:
    return [event for event in history["events"] if event["event_type"] == kind]


def one(history: dict, kind: str) -> dict:
    found = events(history, kind)
    if len(found) != 1:
        raise RuntimeError(f"Expected one {kind}, got {len(found)}")
    return found[0]


def assert_values(actual: dict | None, expected: dict) -> None:
    if not isinstance(actual, dict) or set(actual) != set(expected):
        raise RuntimeError("Search attribute keys differ")
    for key, value in expected.items():
        candidate = actual[key]
        if key == "SearchTime":
            if datetime.fromisoformat(candidate) != datetime.fromisoformat(value):
                raise RuntimeError("Datetime instant or microsecond precision changed")
        elif type(candidate) is not type(value) or candidate != value:
            raise RuntimeError(f"Search attribute {key} lost its value or type")


def assert_upsert(event: dict, attributes: dict) -> None:
    payload = event["payload"]
    if payload.get("attributes") != attributes:
        raise RuntimeError("Authored update values differ from durable history")
    expected_types = {key: DEFINITIONS[key] for key, value in attributes.items() if value is not None}
    if payload.get("attribute_types") != expected_types:
        raise RuntimeError("Typed update history lost canonical type identities")


def assert_recovery(history: dict, parked: dict) -> None:
    updates = events(history, "SearchAttributesUpserted")
    if len(updates) != 2 or updates[0] != parked["upsert"]:
        raise RuntimeError("Cold replay changed or duplicated the original upsert")
    assert_upsert(updates[0], INITIAL)
    assert_upsert(updates[1], MUTATION)
    opened = one(history, "SignalWaitOpened")
    if opened != parked["wait"]:
        raise RuntimeError("Cold replay changed the original signal boundary")
    received = one(history, "SignalReceived")["payload"]
    applied = one(history, "SignalApplied")["payload"]
    if (not received.get("signal_id") or received["signal_id"] != applied.get("signal_id")
            or received.get("signal_name") != SIGNAL
            or applied.get("signal_wait_id") != opened["payload"].get("signal_wait_id")
            or applied.get("sequence") != opened["payload"].get("sequence")):
        raise RuntimeError("Acknowledged signal did not resolve its original wait once")
    terminal = [event for event in history["events"] if event["event_type"] in
                {"WorkflowCompleted", "WorkflowFailed", "WorkflowCancelled", "WorkflowTerminated", "WorkflowTimedOut"}]
    if len(terminal) != 1 or terminal[0]["event_type"] != "WorkflowCompleted":
        raise RuntimeError("Original workflow did not complete exactly once")
    if not updates[0]["sequence"] < opened["sequence"] < updates[1]["sequence"] < terminal[0]["sequence"]:
        raise RuntimeError("Durable updates, wait and completion are out of order")


async def visibility(client: Client, expected: dict, final: bool) -> dict:
    queries = [
        f'SearchKeyword = {json.dumps(expected["SearchKeyword"], ensure_ascii=False)}',
        'SearchCount > 9007199254740992 AND SearchCount <= 9007199254740993',
        'SearchRatio > 1.0 AND SearchRatio < 2.0',
        f'SearchFlag = {str(expected["SearchFlag"]).lower()}',
        f'SearchTags = {json.dumps(expected["SearchTags"][0], ensure_ascii=False)}',
        'SearchTime >= "2026-10-08T12:34:56Z" AND SearchTime < "2026-10-08T12:34:57Z"',
    ]
    observed = {}
    for query in queries:
        page = await client.list_workflows(workflow_type=WORKFLOW, query=query)
        if [execution.workflow_id for execution in page.executions] != [WORKFLOW_ID]:
            raise RuntimeError(f"Typed visibility query lost the actual Rust workflow: {query}")
        assert_values(page.executions[0].search_attributes, expected)
        observed[query] = [execution.workflow_id for execution in page.executions]
    absent = ['SearchKeyword = "missing"']
    if final:
        absent += ['SearchRemoved = "erase"', 'SearchTags = "urgent"', 'SearchFlag = true']
    for query in absent:
        page = await client.list_workflows(workflow_type=WORKFLOW, query=query)
        if page.executions:
            raise RuntimeError(f"Stale or nonmatching attribute remained visible: {query}")
        observed[query] = []
    return observed


async def run(phase: str) -> None:
    client = Client(os.environ["DURABLE_WORKFLOW_SERVER_URL"],
                    token=os.environ["DURABLE_WORKFLOW_AUTH_TOKEN"],
                    namespace=os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default"))
    async with client:
        if phase == "setup":
            for key, kind in REGISTERED_TYPES.items():
                await client.create_search_attribute(key, kind)
            schema = await client.list_search_attributes()
            if schema.custom_attributes != REGISTERED_TYPES:
                raise RuntimeError("Published schema differs from its registered types")
            record(phase, {"definitions": schema.custom_attributes})
        elif phase == "park":
            handle = await client.start_workflow(workflow_type=WORKFLOW, task_queue=QUEUE,
                                                 workflow_id=WORKFLOW_ID, input=[WORKFLOW_ID])
            deadline = asyncio.get_running_loop().time() + 60
            while True:
                description = await handle.describe()
                history = await client.get_history(WORKFLOW_ID, description.run_id)
                if description.status == "waiting" and events(history, "SignalWaitOpened"):
                    break
                if description.status in {"failed", "cancelled", "terminated", "timed_out"}:
                    raise RuntimeError(f"Rust workflow ended before its wait: {history}")
                if asyncio.get_running_loop().time() >= deadline:
                    raise TimeoutError("Rust worker did not persist its typed updates and wait")
                await asyncio.sleep(.25)
            upsert = one(history, "SearchAttributesUpserted")
            assert_upsert(upsert, INITIAL)
            assert_values(description.search_attributes, INITIAL)
            wait = one(history, "SignalWaitOpened")
            if not wait["payload"].get("signal_wait_id"):
                raise RuntimeError("Parked wait has no durable identity")
            record(phase, {"workflow_id": WORKFLOW_ID, "run_id": description.run_id,
                           "upsert": upsert, "wait": wait, "history": history,
                           "attributes": description.search_attributes,
                           "queries": await visibility(client, INITIAL, False)})
        elif phase == "release":
            parked = json.loads((PROOF / "park.json").read_text())
            acknowledgment = await client.signal_workflow(WORKFLOW_ID, SIGNAL, ["released"])
            history = await client.get_history(WORKFLOW_ID, parked["run_id"])
            one(history, "SignalReceived")
            if events(history, "SignalApplied") or len(events(history, "SearchAttributesUpserted")) != 1:
                raise RuntimeError("Work progressed during the required worker-absence window")
            record(phase, {"acknowledgment": acknowledgment, "history": history})
        elif phase == "verify":
            parked = json.loads((PROOF / "park.json").read_text())
            handle = client.get_workflow_handle(WORKFLOW_ID, workflow_type=WORKFLOW)
            result = await handle.result(timeout=90, poll_interval=.25)
            if result != {"workflow_runtime": "rust", "request": WORKFLOW_ID, "signal": ["released"]}:
                raise RuntimeError(f"Cold workflow returned an unexpected result: {result}")
            description = await handle.describe()
            if description.run_id != parked["run_id"] or description.status != "completed":
                raise RuntimeError("Worker recovery changed the original run or terminal state")
            history = await client.get_history(WORKFLOW_ID, description.run_id)
            assert_recovery(history, parked)
            assert_values(description.search_attributes, FINAL)
            record(phase, {"workflow_id": WORKFLOW_ID, "run_id": description.run_id,
                           "history": history, "result": result,
                           "attributes": description.search_attributes,
                           "queries": await visibility(client, FINAL, True)})


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=["setup", "park", "release", "verify"])
    asyncio.run(run(parser.parse_args().phase))
