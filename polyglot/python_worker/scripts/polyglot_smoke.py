"""Run all sample-app polyglot scenarios and emit conformance metadata."""
from __future__ import annotations

import asyncio
import importlib.metadata
import json
import os
import sys
from datetime import datetime, timezone
from typing import Any, Awaitable, Callable

from php_to_python_smoke import run_scenario as run_php_to_python
from python_to_php_smoke import run_scenario as run_python_to_php
from python_workflow_smoke import run_scenario as run_python_same_language


ScenarioRunner = Callable[[], Awaitable[dict[str, Any]]]


SCENARIOS: list[tuple[str, str, ScenarioRunner]] = [
    (
        "python_same_language",
        "python-authored workflow on a python worker",
        run_python_same_language,
    ),
    (
        "php_to_python",
        "php-authored workflow scheduling python activities",
        run_php_to_python,
    ),
    (
        "python_to_php",
        "python-authored workflow scheduling php activities",
        run_python_to_php,
    ),
]


def artifact_metadata() -> dict[str, Any]:
    return {
        "server": {
            "artifact": "durableworkflow/server",
            "pin": os.environ.get("DURABLE_SERVER_IMAGE", "durableworkflow/server:0.2.111"),
            "exercised": True,
        },
        "sdk_python": {
            "artifact": "durable-workflow",
            "pin": f"durable-workflow=={importlib.metadata.version('durable-workflow')}",
            "exercised": True,
        },
        "sdk_php_workflow": {
            "artifact": "durable-workflow/workflow",
            "pin": os.environ.get("DURABLE_WORKFLOW_PHP_SDK_PIN", "durable-workflow/workflow:unknown"),
            "exercised": True,
        },
        "dw_cli": {
            "artifact": "dw",
            "pin": os.environ.get("DURABLE_WORKFLOW_CLI_PIN", "not_declared"),
            "exercised": False,
            "reason": "The sample polyglot compose stack drives workflow start/result through sdk-python clients; it does not install or invoke the dw CLI.",
        },
        "waterline": {
            "artifact": "durable-workflow/waterline",
            "pin": os.environ.get("DURABLE_WORKFLOW_WATERLINE_PIN", "durable-workflow/waterline:unknown"),
            "exercised": False,
            "reason": "The sample polyglot compose stack runs the standalone server and workers only; no Waterline observer is configured in this stack.",
        },
    }


def coverage_matrix() -> list[dict[str, Any]]:
    return [
        {
            "scenario": "python_same_language",
            "workflow_language": "python",
            "activity_language": "python",
            "workflow_type": "polyglot.python.greeter",
            "activity_types": ["polyglot.python.greet", "polyglot.python.summarise"],
            "task_queues": [os.environ.get("POLYGLOT_PY_TASK_QUEUE", "polyglot-python")],
            "workflow_start_result_driver": "sdk-python Client",
            "observer_check": "not_exercised",
        },
        {
            "scenario": "php_to_python",
            "workflow_language": "php",
            "activity_language": "python",
            "workflow_type": "polyglot.php-to-python.PhpToPythonWorkflow",
            "activity_types": ["polyglot.php-to-python.reverse", "polyglot.php-to-python.tally"],
            "task_queues": [os.environ.get("POLYGLOT_PHP2PY_TASK_QUEUE", "polyglot-php-to-python")],
            "workflow_start_result_driver": "sdk-python Client",
            "observer_check": "not_exercised",
        },
        {
            "scenario": "python_to_php",
            "workflow_language": "python",
            "activity_language": "php",
            "workflow_type": "polyglot.python-to-php.greeter",
            "activity_types": ["polyglot.python-to-php.marker", "polyglot.python-to-php.describe"],
            "task_queues": [
                os.environ.get("POLYGLOT_PY_TASK_QUEUE", "polyglot-python"),
                os.environ.get("POLYGLOT_PY2PHP_TASK_QUEUE", "polyglot-python-to-php"),
            ],
            "workflow_start_result_driver": "sdk-python Client",
            "observer_check": "not_exercised",
        },
    ]


async def run_all() -> int:
    scenario_results: list[dict[str, Any]] = []

    for _name, label, runner in SCENARIOS:
        print(f"\n==> polyglot smoke: {label}", flush=True)
        scenario = await runner()
        scenario_results.append(scenario)
        print(json.dumps(scenario, indent=2, sort_keys=True), flush=True)

    metadata = {
        "schema": "durable-workflow.sample-app.polyglot-smoke.run",
        "version": 1,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "artifacts": artifact_metadata(),
        "coverage_matrix": coverage_matrix(),
        "scenarios": [
            {
                "scenario": scenario["scenario"],
                "workflow_id": scenario["workflow_id"],
                "workflow_type": scenario["workflow_type"],
                "status": scenario["status"],
            }
            for scenario in scenario_results
        ],
    }

    print("\n==> polyglot smoke: run metadata", flush=True)
    print(json.dumps(metadata, indent=2, sort_keys=True), flush=True)
    print("\npolyglot smoke: all scenarios passed", flush=True)

    return 0


if __name__ == "__main__":
    try:
        sys.exit(asyncio.run(run_all()))
    except Exception as exc:  # noqa: BLE001
        print(str(exc), file=sys.stderr)
        sys.exit(1)
