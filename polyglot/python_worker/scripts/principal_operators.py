"""Inspect actual Rust runs through the published CLI and Waterline remote API."""

import asyncio
import json
import os
import subprocess
from pathlib import Path
from urllib.request import urlopen

from durable_workflow import Client
from sdk_namespaces import emit, observe, retain

EVENT_FIELDS = ("sequence", "event_type", "timestamp", "principal", "payload")


def canonical_events(events):
    return [{key: row.get(key) for key in EVENT_FIELDS} for row in events]


def verify_views(expected, cli, table, waterline):
    namespace, workflow_id, run_id = (expected[key] for key in ("namespace", "workflow_id", "run_id"))
    history = expected["history"]["events"]
    if cli.get("namespace") != namespace or canonical_events(cli.get("events", [])) != canonical_events(history):
        raise RuntimeError("CLI history changed namespace, original events or audit actors.")
    if (waterline.get("engine_source") != "service" or waterline.get("namespace") != namespace
            or waterline.get("workflow_instance_id") != workflow_id or waterline.get("run_id") != run_id
            or waterline.get("selected_run_id") != run_id or waterline.get("status") != expected["status"]
            or waterline.get("timeline_truncated") is not False or waterline.get("history_next_page_token") is not None
            or canonical_events(waterline.get("timeline", [])) != canonical_events(history)):
        raise RuntimeError("Waterline lost its remote backend, selected run, status, history window or audit actors.")
    rows = [[cell.strip() for cell in line.split("|")] for line in table.splitlines() if line.startswith("|")]
    if not any("Principal" in row for row in rows):
        raise RuntimeError("CLI human history has no principal column.")
    for event in history:
        principal = event.get("principal")
        if principal is None:
            continue
        label = f"{principal.get('label', principal['id'])} ({principal['type']})"
        if not any(len(row) >= 6 and row[1] == str(event["sequence"])
                   and row[2] == event["event_type"] and row[4] == label for row in rows):
            raise RuntimeError("CLI human history omitted an event's actual audit actor.")
        command = event["payload"].get("command", {})
        if not command.get("id"):
            continue
        matches = [row for row in waterline.get("commands", []) if row.get("id") == command["id"]]
        if len(matches) != 1 or any(matches[0].get(key) != principal.get(field)
                                   for key, field in (("principal_type", "type"), ("principal_id", "id"), ("principal_label", "label"))):
            raise RuntimeError("Waterline command actor is missing, duplicated or differs from its actual history.")
    return {"namespace": namespace, "workflow_id": workflow_id, "original_run_id": run_id,
            "status": expected["status"], "events": len(history), "cli_json_and_table": True,
            "waterline_remote_timeline_and_commands": True}


def cli_history(namespace, workflow_id, run_id, *, human=False):
    token = "" if namespace == "default" else f"dwr_fixture_operator_{namespace.replace('-', '_')}_0123456789_rotated"
    command = ["dw", "workflow:history", workflow_id, run_id, "--server", os.environ["DURABLE_WORKFLOW_SERVER_URL"],
               "--namespace", namespace, "--no-ansi"]
    if token:
        command.extend(("--token", token))
    if not human:
        command.append("--output=json")
    environment = {**os.environ, "DURABLE_WORKFLOW_AUTH_TOKEN": "", "DURABLE_WORKFLOW_CONTROL_TOKEN": "",
                   "DURABLE_WORKFLOW_WORKER_TOKEN": "", "XDG_CONFIG_HOME": "/tmp/principal-operator-cli"}
    result = subprocess.run(command, env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=30)
    if result.returncode:
        raise RuntimeError(f"Published CLI history exited {result.returncode}.")
    return result.stdout if human else json.loads(result.stdout)


def waterline_json(path):
    with urlopen("http://waterline:8081" + path, timeout=30) as response:
        if response.status != 200:
            raise RuntimeError("Published Waterline operator request did not succeed.")
        return json.load(response)


async def inspect():
    namespace = os.environ["PRINCIPAL_OPERATOR_NAMESPACE"]
    if namespace not in {"rust-namespace-a", "rust-namespace-b", "default"}:
        raise RuntimeError("Operator observer requires an explicit fixture namespace.")
    probe = waterline_json("/polyglot/conformance/artifacts")
    for artifact, variable in (("sdk-php", "DURABLE_WORKFLOW_PHP_SDK_VERSION"),
                               ("workflow", "DURABLE_WORKFLOW_WORKFLOW_VERSION"),
                               ("waterline", "DURABLE_WORKFLOW_WATERLINE_VERSION")):
        if probe["artifacts"][artifact]["version"].removeprefix("v") != os.environ[variable]:
            raise RuntimeError("Waterline host installed an artifact outside the frozen published tuple.")
    retain(f"operator-artifacts-{namespace}", probe)
    proof = Path(os.environ["NAMESPACE_PROOF_DIR"])
    paths = [proof / f"verify-namespace-run-{namespace}.json"]
    if namespace != "default":
        paths.append(proof / f"verify-only-{namespace}.json")
    paths.extend(proof / f"principal-verify-principal-{kind}-{namespace}.json" for kind in ("failure", "cancel"))
    client = Client(os.environ["DURABLE_WORKFLOW_SERVER_URL"], token=None if namespace == "default" else "test-token", namespace=namespace)
    try:
        outcomes = []
        for path in paths:
            record = json.loads(path.read_text())
            workflow_id, run_id = record["workflow_id"], record["run_id"]
            description, history = await observe(client, workflow_id)
            if description.run_id != run_id or history != record["history"]:
                raise RuntimeError("Operator inspection started from a replacement run or changed history.")
            expected = {**record, "namespace": namespace, "status": description.status}
            cli = cli_history(namespace, workflow_id, run_id)
            table = cli_history(namespace, workflow_id, run_id, human=True)
            detail = waterline_json(f"/waterline/api/instances/{workflow_id}/runs/{run_id}")
            outcome = verify_views(expected, cli, table, detail)
            _, after = await observe(client, workflow_id)
            if after != history:
                raise RuntimeError("Read-only operator inspection mutated original history.")
            retain(f"operator-{workflow_id}", {"cli_json": cli, "waterline": detail, "review": outcome})
            (proof / f"operator-{workflow_id}.txt").write_text(table)
            outcomes.append(outcome)
        emit(scenario="rust-principal-operator-visibility-pass", namespace=namespace, runs=outcomes)
    finally:
        await client.aclose()


if __name__ == "__main__":
    asyncio.run(inspect())
