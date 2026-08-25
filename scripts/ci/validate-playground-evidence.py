#!/usr/bin/env python3
"""Validate the authored-source PHP, Python, and Rust playground evidence set."""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any


LANGUAGES = {"php", "python", "rust"}
REPO_ROOT = Path(__file__).resolve().parents[2]
CONTRACT_PATH = REPO_ROOT / "playground" / "contract.json"
REQUIRED_EVENTS = {
    "WorkflowStarted",
    "ActivityScheduled",
    "ActivityCompleted",
    "WorkflowCompleted",
}


def fail(message: str) -> None:
    raise SystemExit(message)


def require_dict(value: Any, label: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        fail(f"{label} must be an object")
    return value


def validate(path: Path, contract: dict[str, Any]) -> str:
    with path.open(encoding="utf-8") as source:
        record = require_dict(json.load(source), str(path))

    if record.get("schema") != "durable-workflow.sample-app.playground-evidence":
        fail(f"{path} has an unsupported evidence schema")
    if record.get("schema_version") != 2:
        fail(f"{path} has an unsupported evidence schema version")

    language = record.get("language")
    if language not in LANGUAGES:
        fail(f"{path} has an unsupported language: {language}")

    runtime = require_dict(record.get("runtime"), f"{path}: runtime")
    runtime_target = runtime.get("target")
    if runtime_target not in {"local", "managed"}:
        fail(f"{path} has an unsupported runtime target: {runtime_target}")
    runtime_contract = require_dict(
        require_dict(
            require_dict(contract.get("proof"), "contract.proof").get("runtime"),
            "contract.proof.runtime",
        ).get(runtime_target),
        f"contract.proof.runtime.{runtime_target}",
    )

    normal_user = require_dict(record.get("normal_user"), f"{path}: normal_user")
    if not isinstance(normal_user.get("uid"), int) or normal_user["uid"] <= 0:
        fail(f"{path} did not run as a non-root user")

    source_record = require_dict(record.get("source"), f"{path}: source")
    if (
        not isinstance(source_record.get("created"), list)
        or not source_record["created"]
    ):
        fail(f"{path} did not create fresh caller-owned source")
    files = require_dict(source_record.get("files"), f"{path}: source.files")
    if not files or any(
        not isinstance(digest, str) or len(digest) != 64 for digest in files.values()
    ):
        fail(f"{path} did not record source file digests")
    source_directory = source_record.get("directory")
    if not isinstance(source_directory, str) or not source_directory:
        fail(f"{path} did not retain its caller-owned source directory")
    try:
        Path(source_directory).resolve().relative_to(REPO_ROOT / "polyglot")
    except ValueError:
        pass
    else:
        fail(f"{path} reused a canonical polyglot matrix worker")

    artifacts = require_dict(record.get("artifacts"), f"{path}: artifacts")
    for name in ("server", "sdk_php", "sdk_python", "sdk_rust", "waterline", "cli"):
        value = artifacts.get(name)
        if not isinstance(value, str) or not value or value.startswith(("/", "file:")):
            fail(f"{path} artifact {name} is not a published identity")

    workflow = require_dict(record.get("workflow"), f"{path}: workflow")
    effective = require_dict(
        record.get("effective_contract"), f"{path}: effective_contract"
    )
    declared = require_dict(
        require_dict(contract.get("scenarios"), "contract.scenarios").get(language),
        f"contract.scenarios.{language}",
    )
    for name in ("workflow_type", "activity_type", "workflow_id_prefix"):
        if effective.get(name) != declared.get(name):
            fail(f"{path} effective {name} drifted from the playground contract")
    for name in ("worker_command", "start_command"):
        command = effective.get(name)
        if not isinstance(command, list) or not all(
            isinstance(argument, str) and argument for argument in command
        ):
            fail(f"{path} effective {name} is invalid")
    if effective.get("expected_result") != declared.get("expected_result"):
        fail(f"{path} effective expected result drifted from the contract")
    if workflow.get("status") != "completed":
        fail(f"{path} workflow did not complete")
    if not isinstance(workflow.get("workflow_id"), str) or not workflow["workflow_id"]:
        fail(f"{path} workflow identity is missing")
    if not isinstance(workflow.get("run_id"), str) or not workflow["run_id"]:
        fail(f"{path} run identity is missing")
    if not isinstance(workflow.get("worker_id"), str) or not workflow["worker_id"]:
        fail(f"{path} worker identity is missing")
    if workflow.get("task_queue") != effective.get("task_queue"):
        fail(f"{path} worker and start task queue drifted from the contract")
    if not workflow["workflow_id"].startswith(str(effective["workflow_id_prefix"])):
        fail(f"{path} workflow identity is not Sample App-owned")

    roster = require_dict(
        record.get("worker_registration"), f"{path}: worker_registration"
    )
    workers = roster.get("workers")
    if not isinstance(workers, list) or not any(
        isinstance(worker, dict)
        and worker.get("worker_id") == workflow["worker_id"]
        and effective["workflow_type"] in (worker.get("supported_workflow_types") or [])
        and effective["activity_type"] in (worker.get("supported_activity_types") or [])
        for worker in workers
    ):
        fail(f"{path} did not retain its exact worker registration")
    event_types = workflow.get("history_event_types")
    if not isinstance(event_types, list) or not REQUIRED_EVENTS.issubset(event_types):
        fail(f"{path} workflow history is incomplete: {event_types}")

    result = workflow.get("result")
    if result != effective.get("expected_result"):
        fail(f"{path} result does not match the {language} activity: {result}")

    waterline_requirement = require_dict(
        runtime_contract.get("selected_waterline_run"),
        f"contract.proof.runtime.{runtime_target}.selected_waterline_run",
    )
    waterline = require_dict(record.get("waterline"), f"{path}: waterline")
    if waterline_requirement.get("requirement") == "required":
        if waterline.get("status") != "validated":
            fail(f"{path} did not validate its required Waterline proof")
        selection = require_dict(
            waterline.get("selection"), f"{path}: waterline.selection"
        )
        if selection.get("instance_id") != workflow["workflow_id"]:
            fail(f"{path} Waterline selected a different workflow")
        if selection.get("selected_run_id") != workflow["run_id"]:
            fail(f"{path} Waterline selected a different run")
        url = waterline.get("url")
        if (
            not isinstance(url, str)
            or workflow["workflow_id"] not in url
            or workflow["run_id"] not in url
        ):
            fail(f"{path} does not contain an exact Waterline run URL")
    elif waterline_requirement.get("requirement") == "omitted":
        if waterline != {
            "status": "omitted",
            "reason": waterline_requirement.get("reason"),
        }:
            fail(f"{path} did not authoritatively explain omitted Waterline proof")
    else:
        fail(f"{path} has an unsupported Waterline proof requirement")

    if not isinstance(record.get("elapsed_ms"), int) or record["elapsed_ms"] <= 0:
        fail(f"{path} journey timing is missing")
    return str(language)


def main() -> None:
    if len(sys.argv) < 2:
        fail("Usage: validate-playground-evidence.py EVIDENCE.json [...]")

    with CONTRACT_PATH.open(encoding="utf-8") as source:
        contract = require_dict(json.load(source), str(CONTRACT_PATH))
    paths = [Path(argument) for argument in sys.argv[1:]]
    observed = [validate(path, contract) for path in paths]
    if set(observed) != LANGUAGES or len(observed) != len(LANGUAGES):
        fail(f"expected one evidence record per SDK; observed={observed}")

    print(f"Validated authored playground journeys: {', '.join(sorted(observed))}")


if __name__ == "__main__":
    main()
