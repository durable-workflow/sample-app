#!/usr/bin/env python3
"""Validate one or more service-mode onboarding timing records."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from typing import Any


VERSION = re.compile(r"^2\.0\.0-(?:beta|rc)\.\d+$")


def fail(message: str) -> None:
    raise SystemExit(f"service-mode evidence: {message}")


def require_mapping(value: Any, label: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        fail(f"{label} must be an object")
    return value


def load(path: Path) -> dict[str, Any]:
    with path.open(encoding="utf-8") as source:
        payload = require_mapping(json.load(source), str(path))

    if payload.get("schema") != "durable-workflow.sample-app.service-mode-evidence.v1":
        fail(f"{path} has an unsupported schema")
    for field in ("startup_ms", "journey_ms", "browser_ms"):
        if not isinstance(payload.get(field), int) or payload[field] <= 0:
            fail(f"{path} {field} must be a positive integer")
    screenshot = payload.get("browser_screenshot")
    if not isinstance(screenshot, str) or not screenshot.endswith("-waterline.png"):
        fail(f"{path} has an invalid browser screenshot name")
    if not path.with_name(screenshot).is_file():
        fail(f"{path} browser screenshot is missing")

    workflow = require_mapping(payload.get("workflow"), f"{path} workflow")
    workflow_id = workflow.get("workflow_id")
    if not isinstance(workflow_id, str) or not workflow_id.startswith(
        "service-welcome-"
    ):
        fail(f"{path} has an invalid workflow ID")
    run_id = workflow.get("run_id")
    if not isinstance(run_id, str) or not run_id:
        fail(f"{path} does not identify the completed workflow run")
    result = require_mapping(workflow.get("result"), f"{path} workflow.result")
    php = require_mapping(result.get("php_activity"), f"{path} PHP activity")
    python = require_mapping(result.get("python_activity"), f"{path} Python activity")
    if php.get("runtime") != "php" or python.get("runtime") != "python":
        fail(f"{path} did not retain both runtime results")
    waterline_url = workflow.get("waterline_url")
    if (
        not isinstance(waterline_url, str)
        or workflow_id not in waterline_url
        or run_id not in waterline_url
    ):
        fail(f"{path} does not link to its workflow in Waterline")

    artifacts = require_mapping(payload.get("artifacts"), f"{path} artifacts")
    server = artifacts.get("server")
    if not isinstance(server, str) or not server.startswith("durableworkflow/server:"):
        fail(f"{path} has an invalid Server image")
    for name in ("sdk_php", "sdk_python", "workflow", "waterline"):
        version = artifacts.get(name)
        if not isinstance(version, str) or VERSION.fullmatch(version) is None:
            fail(f"{path} has an invalid {name} version")

    return payload


def main() -> None:
    if len(sys.argv) < 2:
        fail("pass at least one evidence JSON path")
    records = [load(Path(value)) for value in sys.argv[1:]]
    workflow_ids = [record["workflow"]["workflow_id"] for record in records]
    if len(workflow_ids) != len(set(workflow_ids)):
        fail("repeated runs reused a workflow ID")
    print(f"Validated {len(records)} service-mode onboarding run(s).")


if __name__ == "__main__":
    main()
