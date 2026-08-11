#!/usr/bin/env python3
"""Validate one or more service-mode onboarding timing records."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from typing import Any


VERSION = re.compile(r"^2\.0\.0-(?:beta|rc)\.\d+$")
EXPECTED_DIALOG_CASES = {
    ("filters", "desktop", 1440, 900),
    ("filters", "intermediate", 900, 768),
    ("filters", "mobile", 390, 844),
    ("filters", "short-height", 1280, 480),
    ("view-options", "desktop", 1440, 900),
    ("view-options", "intermediate", 900, 768),
    ("view-options", "mobile", 390, 844),
    ("view-options", "short-height", 1280, 480),
}


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
    for field in ("startup_ms", "journey_ms", "browser_ms", "dialog_ms"):
        if not isinstance(payload.get(field), int) or payload[field] <= 0:
            fail(f"{path} {field} must be a positive integer")
    screenshot = payload.get("browser_screenshot")
    if not isinstance(screenshot, str) or not screenshot.endswith("-waterline.png"):
        fail(f"{path} has an invalid browser screenshot name")
    if not path.with_name(screenshot).is_file():
        fail(f"{path} browser screenshot is missing")

    mount_evidence = payload.get("mount_evidence")
    if not isinstance(mount_evidence, str) or not mount_evidence.endswith(
        "-waterline-mount.json"
    ):
        fail(f"{path} has an invalid Waterline mount evidence name")
    mount_evidence_path = path.with_name(mount_evidence)
    with mount_evidence_path.open(encoding="utf-8") as source:
        mount_summary = require_mapping(json.load(source), str(mount_evidence_path))
    if mount_summary.get("schema") != "durable-workflow.sample-app.waterline-mount.v1":
        fail(f"{mount_evidence_path} has an unsupported schema")
    if mount_summary.get("status") != "passed":
        fail(f"{mount_evidence_path} did not observe a mounted Waterline page")
    page = require_mapping(mount_summary.get("page"), f"{mount_evidence_path} page")
    if page.get("mounted") is not True or page.get("body_text_length", 0) < 100:
        fail(f"{mount_evidence_path} did not retain a nonblank mounted page")
    list_request = require_mapping(
        mount_summary.get("workflow_list_request"),
        f"{mount_evidence_path} workflow_list_request",
    )
    if list_request.get("status") != 200:
        fail(f"{mount_evidence_path} did not complete the workflow-list request")
    empty_state_requests = require_mapping(
        mount_summary.get("empty_state_requests"),
        f"{mount_evidence_path} empty_state_requests",
    )
    saved_views = require_mapping(
        empty_state_requests.get("saved_views"),
        f"{mount_evidence_path} saved_views",
    )
    if saved_views.get("status") != 200 or saved_views.get("custom_view_count") != 0:
        fail(f"{mount_evidence_path} did not observe empty saved-view state")
    preferences = require_mapping(
        empty_state_requests.get("workflow_list_preferences"),
        f"{mount_evidence_path} workflow_list_preferences",
    )
    if preferences.get("status") != 200 or any(
        preferences.get(field) != 0
        for field in (
            "stored_preference_count",
            "effective_preference_count",
            "override_count",
        )
    ):
        fail(f"{mount_evidence_path} did not observe empty workflow-list preferences")
    for field in ("page_errors", "console_errors", "request_failures", "api_failures"):
        if mount_summary.get(field) != []:
            fail(f"{mount_evidence_path} recorded {field}")

    dialog_evidence = payload.get("dialog_evidence")
    if not isinstance(dialog_evidence, str) or Path(dialog_evidence).name != "summary.json":
        fail(f"{path} does not identify responsive dialog evidence")
    dialog_summary_path = path.parent / dialog_evidence
    if dialog_summary_path.resolve().parent.parent != path.parent.resolve():
        fail(f"{path} responsive dialog evidence path must be a sibling directory")
    with dialog_summary_path.open(encoding="utf-8") as source:
        dialog_summary = require_mapping(json.load(source), str(dialog_summary_path))
    if dialog_summary.get("schema") != "durable-workflow.waterline.dialog-visual-summary.v1":
        fail(f"{dialog_summary_path} has an unsupported schema")
    if dialog_summary.get("expectedCases") != 8 or dialog_summary.get("passedCases") != 8:
        fail(f"{dialog_summary_path} did not pass all responsive dialog cases")

    observed_dialog_cases: set[tuple[str, str, int, int]] = set()
    for case in dialog_summary.get("cases", []):
        case = require_mapping(case, f"{dialog_summary_path} case")
        viewport = require_mapping(case.get("viewport"), f"{dialog_summary_path} viewport")
        key = (
            case.get("dialog"),
            viewport.get("name"),
            viewport.get("width"),
            viewport.get("height"),
        )
        observed_dialog_cases.add(key)
        screenshot_name = case.get("screenshot")
        report_name = f"{case.get('dialog')}-{viewport.get('name')}.json"
        for filename in (screenshot_name, report_name):
            if not isinstance(filename, str) or Path(filename).name != filename:
                fail(f"{dialog_summary_path} has an invalid case artifact name")
            if not dialog_summary_path.with_name(filename).is_file():
                fail(f"{dialog_summary_path} case artifact {filename} is missing")
    if observed_dialog_cases != EXPECTED_DIALOG_CASES:
        fail(f"{dialog_summary_path} does not cover the required dialog viewport matrix")

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
