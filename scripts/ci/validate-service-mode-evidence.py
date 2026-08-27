#!/usr/bin/env python3
"""Validate one or more service-mode onboarding timing records."""

from __future__ import annotations

import json
import os
import re
import sys
from pathlib import Path
from typing import Any


VERSION = re.compile(r"^2\.0\.0-(?:beta|rc)\.\d+$")
REVISION = re.compile(r"^[0-9a-f]{40}$")
PUBLIC_COMPLETION_GATE = "https://github.com/durable-workflow/waterline/issues/79"
REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
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
RUN_DETAIL_VIEWPORTS = {
    "desktop": (1440, 900),
    "intermediate": (768, 1024),
    "mobile": (390, 844),
    "short-height": (1280, 360),
}
RUN_DETAIL_NAVIGATION_STATES = {"initial", "deep-section"}
RUN_DETAIL_PRESENTATIONS = {"embedded", "service"}
RUN_DETAIL_RESULTS = {"populated", "supported-empty", "unavailable", "degraded"}
RUN_DETAIL_STATES = {
    (f"{presentation}-{result}-expanded", presentation, result, True)
    for presentation in RUN_DETAIL_PRESENTATIONS
    for result in RUN_DETAIL_RESULTS
} | {
    (f"{presentation}-populated-collapsed", presentation, "populated", False)
    for presentation in RUN_DETAIL_PRESENTATIONS
}
EXPECTED_RUN_DETAIL_CASES = {
    (state, presentation, result, navigation, viewport, width, height)
    for state, presentation, result, _expanded in RUN_DETAIL_STATES
    for navigation in RUN_DETAIL_NAVIGATION_STATES
    for viewport, (width, height) in RUN_DETAIL_VIEWPORTS.items()
}


def fail(message: str) -> None:
    raise SystemExit(f"service-mode evidence: {message}")


def require_mapping(value: Any, label: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        fail(f"{label} must be an object")
    return value


def require_list(value: Any, label: str) -> list[Any]:
    if not isinstance(value, list):
        fail(f"{label} must be an array")
    return value


def require_empty_list(value: Any, label: str) -> None:
    if require_list(value, label) != []:
        fail(f"{label} must be empty")


def require_number(value: Any, label: str) -> int | float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        fail(f"{label} must be a number")
    return value


def summary_path(evidence_path: Path, value: Any, label: str) -> Path:
    if not isinstance(value, str) or Path(value).name != "summary.json":
        fail(f"{evidence_path} does not identify responsive {label} evidence")
    path = evidence_path.parent / value
    if path.resolve().parent.parent != evidence_path.parent.resolve():
        fail(f"{evidence_path} responsive {label} evidence must be a sibling directory")
    return path


def load_json_mapping(path: Path) -> dict[str, Any]:
    with path.open(encoding="utf-8") as source:
        return require_mapping(json.load(source), str(path))


def locked_waterline_identity() -> tuple[str, str]:
    lock = load_json_mapping(REPOSITORY_ROOT / "composer.lock")
    packages = require_list(lock.get("packages"), "composer.lock packages")

    for package in packages:
        package = require_mapping(package, "composer.lock package")
        if package.get("name") != "durable-workflow/waterline":
            continue

        source = require_mapping(package.get("source"), "locked Waterline source")
        version = package.get("version")
        reference = source.get("reference")
        if (
            not isinstance(version, str)
            or VERSION.fullmatch(version) is None
            or not isinstance(reference, str)
            or REVISION.fullmatch(reference) is None
        ):
            fail("composer.lock has an invalid Waterline identity")

        return version, reference

    fail("composer.lock does not contain durable-workflow/waterline")


def load(path: Path) -> dict[str, Any]:
    with path.open(encoding="utf-8") as source:
        payload = require_mapping(json.load(source), str(path))

    if payload.get("schema") != "durable-workflow.sample-app.service-mode-evidence.v2":
        fail(f"{path} has an unsupported schema")
    for field in (
        "startup_ms",
        "journey_ms",
        "browser_ms",
        "dialog_ms",
        "run_detail_ms",
    ):
        if not isinstance(payload.get(field), int) or payload[field] <= 0:
            fail(f"{path} {field} must be a positive integer")

    consumer = require_mapping(payload.get("consumer"), f"{path} consumer")
    if consumer.get("repository") != "durable-workflow/sample-app":
        fail(f"{path} has an invalid consumer repository")
    revision = consumer.get("revision")
    if not isinstance(revision, str) or REVISION.fullmatch(revision) is None:
        fail(f"{path} does not identify an exact Sample App revision")
    expected_revision = os.environ.get("GITHUB_SHA")
    if expected_revision and revision != expected_revision:
        fail(f"{path} revision does not match the protected workflow revision")
    if payload.get("public_completion_gate") != PUBLIC_COMPLETION_GATE:
        fail(f"{path} does not link the public completion gate")
    ci = require_mapping(payload.get("ci"), f"{path} ci")
    if os.environ.get("GITHUB_ACTIONS") == "true":
        for field, environment_name in (
            ("event_name", "GITHUB_EVENT_NAME"),
            ("ref", "GITHUB_REF"),
            ("run_id", "GITHUB_RUN_ID"),
            ("run_attempt", "GITHUB_RUN_ATTEMPT"),
        ):
            if ci.get(field) != os.environ.get(environment_name):
                fail(f"{path} {field} does not match the GitHub Actions run")

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

    dialog_summary_path = summary_path(path, payload.get("dialog_evidence"), "dialog")
    dialog_summary = load_json_mapping(dialog_summary_path)
    if (
        dialog_summary.get("schema")
        != "durable-workflow.waterline.dialog-visual-summary.v1"
    ):
        fail(f"{dialog_summary_path} has an unsupported schema")
    if (
        dialog_summary.get("expectedCases") != 8
        or dialog_summary.get("observedCases") != 8
        or dialog_summary.get("passedCases") != 8
        or dialog_summary.get("failedCases") != 0
    ):
        fail(f"{dialog_summary_path} did not pass all responsive dialog cases")

    observed_dialog_cases: set[tuple[str, str, int, int]] = set()
    for case in require_list(
        dialog_summary.get("cases"), f"{dialog_summary_path} cases"
    ):
        case = require_mapping(case, f"{dialog_summary_path} case")
        viewport = require_mapping(
            case.get("viewport"), f"{dialog_summary_path} viewport"
        )
        key = (
            case.get("dialog"),
            viewport.get("name"),
            viewport.get("width"),
            viewport.get("height"),
        )
        observed_dialog_cases.add(key)
        if case.get("status") != "passed" or case.get("failure") is not None:
            fail(f"{dialog_summary_path} contains a failed dialog case")
        screenshot_name = case.get("screenshot")
        report_name = f"{case.get('dialog')}-{viewport.get('name')}.json"
        for filename in (screenshot_name, report_name):
            if not isinstance(filename, str) or Path(filename).name != filename:
                fail(f"{dialog_summary_path} has an invalid case artifact name")
            if not dialog_summary_path.with_name(filename).is_file():
                fail(f"{dialog_summary_path} case artifact {filename} is missing")

        report_path = dialog_summary_path.with_name(report_name)
        report = load_json_mapping(report_path)
        if (
            report.get("schema") != "durable-workflow.waterline.dialog-visual.v1"
            or report.get("status") != "passed"
            or report.get("failure") is not None
            or report.get("dialog") != case.get("dialog")
            or report.get("viewport") != viewport
            or report.get("screenshot") != screenshot_name
            or report.get("openedDialog") is not True
        ):
            fail(f"{report_path} does not retain a passing opened-dialog result")
        for field in ("consoleErrors", "requestFailures", "errorResponses"):
            require_empty_list(report.get(field), f"{report_path} {field}")
        if not require_list(report.get("contrast"), f"{report_path} contrast"):
            fail(f"{report_path} does not retain readable dialog content checks")
        focus = require_list(report.get("focus"), f"{report_path} focus")
        if len(focus) != 24:
            fail(f"{report_path} does not retain the complete focus-trap audit")
        geometry = require_mapping(report.get("geometry"), f"{report_path} geometry")
        require_empty_list(geometry.get("failures"), f"{report_path} geometry.failures")
        if (
            geometry.get("appRootInert") is not True
            or geometry.get("backdropSemantics") != "intentional"
            or geometry.get("dialogSemantics") != "modal"
            or geometry.get("role") != "dialog"
            or geometry.get("ariaModal") != "true"
            or geometry.get("activeElementInside") is not True
        ):
            fail(
                f"{report_path} does not retain dialog focus and inert-background semantics"
            )
        controls = require_list(report.get("controls"), f"{report_path} controls")
        if not controls:
            fail(f"{report_path} does not retain visible form controls")
        for control in controls:
            control = require_mapping(control, f"{report_path} control")
            if (
                control.get("clipped") is not False
                or control.get("inViewport") is not True
                or control.get("reachable") is not True
            ):
                fail(f"{report_path} contains a clipped or unreachable dialog control")
        control_classes = " ".join(
            str(control.get("className", "")) for control in controls
        )
        if (
            "swal2-confirm" not in control_classes
            or "swal2-cancel" not in control_classes
        ):
            fail(f"{report_path} does not retain reachable primary and cancel actions")
        if case.get("dialog") == "view-options":
            checkboxes = require_list(
                report.get("checkboxes"), f"{report_path} checkboxes"
            )
            if not any(
                checkbox.get("checked") is True for checkbox in checkboxes
            ) or not any(checkbox.get("checked") is False for checkbox in checkboxes):
                fail(f"{report_path} does not retain checked and unchecked controls")
    if observed_dialog_cases != EXPECTED_DIALOG_CASES:
        fail(
            f"{dialog_summary_path} does not cover the required dialog viewport matrix"
        )

    run_detail_summary_path = summary_path(
        path, payload.get("run_detail_evidence"), "run-detail"
    )
    run_detail_summary = load_json_mapping(run_detail_summary_path)
    if (
        run_detail_summary.get("schema")
        != "durable-workflow.waterline.run-detail-visual-summary.v1"
    ):
        fail(f"{run_detail_summary_path} has an unsupported schema")
    expected_run_detail_count = len(EXPECTED_RUN_DETAIL_CASES)
    if (
        run_detail_summary.get("expectedCases") != expected_run_detail_count
        or run_detail_summary.get("observedCases") != expected_run_detail_count
        or run_detail_summary.get("passedCases") != expected_run_detail_count
        or run_detail_summary.get("failedCases") != 0
    ):
        fail(f"{run_detail_summary_path} did not pass all responsive run-detail cases")

    base_urls = require_mapping(
        run_detail_summary.get("baseUrls"), f"{run_detail_summary_path} baseUrls"
    )
    for presentation in RUN_DETAIL_PRESENTATIONS:
        base_url = base_urls.get(presentation)
        if not isinstance(base_url, str) or not base_url.startswith("http://"):
            fail(
                f"{run_detail_summary_path} does not identify the {presentation} presentation"
            )
    if (
        base_urls["embedded"] == base_urls["service"]
        or run_detail_summary.get("baseUrl") != base_urls["embedded"]
    ):
        fail(f"{run_detail_summary_path} does not retain distinct presentation URLs")

    observed_run_detail_cases: set[tuple[str, str, str, str, str, int, int]] = set()
    for case in require_list(
        run_detail_summary.get("cases"), f"{run_detail_summary_path} cases"
    ):
        case = require_mapping(case, f"{run_detail_summary_path} case")
        viewport = require_mapping(
            case.get("viewport"), f"{run_detail_summary_path} viewport"
        )
        key = (
            case.get("state"),
            case.get("presentation"),
            case.get("result"),
            case.get("navigation"),
            viewport.get("name"),
            viewport.get("width"),
            viewport.get("height"),
        )
        observed_run_detail_cases.add(key)
        if key not in EXPECTED_RUN_DETAIL_CASES:
            fail(f"{run_detail_summary_path} contains an unexpected run-detail case")
        if case.get("status") != "passed" or case.get("failure") is not None:
            fail(f"{run_detail_summary_path} contains a failed run-detail case")
        expected_stream_state = f"{case.get('presentation')}-{case.get('result')}"
        if case.get("streamState") != expected_stream_state:
            fail(f"{run_detail_summary_path} contains an invalid stream fixture")
        screenshot_name = case.get("screenshot")
        report_name = (
            f"{case.get('state')}-{case.get('navigation')}-"
            f"{viewport.get('name')}.json"
        )
        for filename in (screenshot_name, report_name):
            if not isinstance(filename, str) or Path(filename).name != filename:
                fail(f"{run_detail_summary_path} has an invalid case artifact name")
            if not run_detail_summary_path.with_name(filename).is_file():
                fail(f"{run_detail_summary_path} case artifact {filename} is missing")

        report_path = run_detail_summary_path.with_name(report_name)
        report = load_json_mapping(report_path)
        if (
            report.get("schema") != "durable-workflow.waterline.run-detail-visual.v1"
            or report.get("surface") != "run-detail"
            or report.get("status") != "passed"
            or report.get("failure") is not None
            or report.get("state") != case.get("state")
            or report.get("streamState") != case.get("streamState")
            or report.get("presentation") != case.get("presentation")
            or report.get("result") != case.get("result")
            or report.get("navigation") != case.get("navigation")
            or report.get("viewport") != viewport
            or report.get("screenshot") != screenshot_name
        ):
            fail(f"{report_path} does not retain a passing run-detail result")
        bootstrap = require_mapping(report.get("bootstrap"), f"{report_path} bootstrap")
        expected_bootstrap = {
            "embedded": {
                "mode": "embedded",
                "label": "Embedded Laravel",
                "transport": "workflow-package",
            },
            "service": {
                "mode": "service",
                "label": "Standalone service",
                "transport": "durable-workflow/sdk",
            },
        }[case["presentation"]]
        if any(
            bootstrap.get(field) != value
            for field, value in expected_bootstrap.items()
        ):
            fail(f"{report_path} does not retain the expected presentation identity")
        for field in ("browserErrors", "requestFailures", "errorResponses"):
            require_empty_list(report.get(field), f"{report_path} {field}")
        if not require_list(report.get("contrast"), f"{report_path} contrast"):
            fail(f"{report_path} does not retain readable run-detail content checks")
        disclosure = require_mapping(
            report.get("disclosure"), f"{report_path} disclosure"
        )
        expanded = case.get("state", "").endswith("-expanded")
        expected_disclosure = {
            "text": "Collapse Workflow Streams"
            if expanded
            else "Expand Workflow Streams",
            "ariaExpanded": "true" if expanded else "false",
            "regionVisible": expanded,
        }
        if disclosure != expected_disclosure:
            fail(f"{report_path} has an invalid Workflow Streams disclosure state")
        geometry = require_mapping(report.get("geometry"), f"{report_path} geometry")
        for field in (
            "failures",
            "unreachable_controls",
            "clipped_controls",
            "overlapping_floating_elements",
        ):
            require_empty_list(geometry.get(field), f"{report_path} geometry.{field}")
        document = require_mapping(
            geometry.get("document"), f"{report_path} geometry.document"
        )
        document_scroll_width = require_number(
            document.get("scrollWidth"), f"{report_path} document scroll width"
        )
        if document_scroll_width > viewport["width"] + 1:
            fail(f"{report_path} retains horizontal document overflow")

        simultaneous_navigation = require_mapping(
            geometry.get("simultaneous_navigation"),
            f"{report_path} geometry.simultaneous_navigation",
        )
        sidebar = require_mapping(
            simultaneous_navigation.get("sidebar"),
            f"{report_path} simultaneous navigation sidebar",
        )
        sidebar_scroll_left = require_number(
            sidebar.get("scrollLeft"), f"{report_path} navigation scroll position"
        )
        if sidebar_scroll_left > 1:
            fail(f"{report_path} retains a horizontally scrolled initial navigation")
        navigation_links = [
            require_mapping(link, f"{report_path} simultaneous navigation link")
            for link in require_list(
                simultaneous_navigation.get("links"),
                f"{report_path} simultaneous navigation links",
            )
        ]
        for label in ("Dashboard", "Workers"):
            matches = [link for link in navigation_links if link.get("name") == label]
            if len(matches) != 1 or matches[0].get("inViewport") is not True:
                fail(f"{report_path} does not retain a visible {label} navigation link")

        simultaneous_topbar = require_mapping(
            geometry.get("simultaneous_topbar"),
            f"{report_path} geometry.simultaneous_topbar",
        )
        topbar_actions = require_mapping(
            simultaneous_topbar.get("actions"),
            f"{report_path} simultaneous top-bar actions",
        )
        actions_scroll_left = require_number(
            topbar_actions.get("scrollLeft"), f"{report_path} action scroll position"
        )
        actions_scroll_width = require_number(
            topbar_actions.get("scrollWidth"), f"{report_path} action scroll width"
        )
        actions_client_width = require_number(
            topbar_actions.get("clientWidth"), f"{report_path} action client width"
        )
        if actions_scroll_left > 1 or actions_scroll_width > actions_client_width + 1:
            fail(f"{report_path} retains overflowing persistent actions")
        topbar_items = [
            require_mapping(item, f"{report_path} simultaneous top-bar item")
            for item in require_list(
                simultaneous_topbar.get("items"),
                f"{report_path} simultaneous top-bar items",
            )
        ]
        for label in ("Scope", "Backend", "Auto refresh", "Theme"):
            matches = [item for item in topbar_items if item.get("name") == label]
            if (
                len(matches) != 1
                or matches[0].get("inViewport") is not True
                or matches[0].get("inTopbar") is not True
                or matches[0].get("clipped") is not False
            ):
                fail(f"{report_path} does not retain a visible {label} top-bar item")
        controls = require_list(report.get("controls"), f"{report_path} controls")
        if not controls:
            fail(f"{report_path} does not retain run-detail control reachability")
        for control in controls:
            control = require_mapping(control, f"{report_path} control")
            if (
                control.get("clipped") is not False
                or control.get("coveredByChrome") is not False
                or control.get("inViewport") is not True
                or control.get("reachable") is not True
            ):
                fail(
                    f"{report_path} contains a clipped or unreachable run-detail control"
                )
    if observed_run_detail_cases != EXPECTED_RUN_DETAIL_CASES:
        fail(
            f"{run_detail_summary_path} does not cover the required run-detail viewport matrix"
        )

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

    installed = require_mapping(payload.get("installed"), f"{path} installed")
    installed_waterline = require_mapping(
        installed.get("waterline"), f"{path} installed.waterline"
    )
    locked_version, locked_reference = locked_waterline_identity()
    if (
        installed_waterline.get("package") != "durable-workflow/waterline"
        or artifacts.get("waterline") != locked_version
        or installed_waterline.get("version") != locked_version
        or installed_waterline.get("reference") != locked_reference
    ):
        fail(f"{path} does not bind the exact installed Waterline package")

    return payload


def main() -> None:
    if len(sys.argv) < 2:
        fail("pass at least one evidence JSON path")
    records = [load(Path(value)) for value in sys.argv[1:]]
    workflow_ids = [record["workflow"]["workflow_id"] for record in records]
    if len(workflow_ids) != len(set(workflow_ids)):
        fail("repeated runs reused a workflow ID")
    revisions = {record["consumer"]["revision"] for record in records}
    installations = {
        (
            record["installed"]["waterline"]["version"],
            record["installed"]["waterline"]["reference"],
        )
        for record in records
    }
    if len(revisions) != 1 or len(installations) != 1:
        fail("repeated runs did not retain one Sample App and Waterline identity")
    print(f"Validated {len(records)} service-mode onboarding run(s).")


if __name__ == "__main__":
    main()
