from __future__ import annotations

import importlib.util
import os
import sys
import types
import unittest
from pathlib import Path


REQUIRED_ENV = {
    "DURABLE_WORKFLOW_SERVER_URL": "http://server:8080",
    "DURABLE_SERVER_IMAGE": "durableworkflow/server:0.2.0",
    "DURABLE_WORKFLOW_CLI_VERSION": "0.2.0",
    "DURABLE_WORKFLOW_PHP_SDK_VERSION": "0.2.0",
    "DURABLE_WORKFLOW_PYTHON_SDK_VERSION": "0.2.0",
    "DURABLE_WORKFLOW_RUST_SDK_VERSION": "0.2.0",
    "DURABLE_WORKFLOW_WORKFLOW_VERSION": "2.0.0-alpha.1",
    "DURABLE_WORKFLOW_WATERLINE_VERSION": "2.0.0-alpha.1",
    "DURABLE_WORKFLOW_RUST_AVRO_VERSION": "0.21.0",
    "DURABLE_WORKFLOW_PYTHON_AVRO_VERSION": "1.12.1",
}
os.environ.update(REQUIRED_ENV)

durable_workflow = types.ModuleType("durable_workflow")
durable_workflow.Client = object
sys.modules["durable_workflow"] = durable_workflow

module_path = Path(__file__).parents[1] / "scripts" / "polyglot_smoke.py"
spec = importlib.util.spec_from_file_location("polyglot_smoke", module_path)
assert spec is not None and spec.loader is not None
polyglot_smoke = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = polyglot_smoke
spec.loader.exec_module(polyglot_smoke)


def cell(scenario: str, workflow_runtime: str, activity_runtime: str) -> dict[str, str]:
    return {
        "scenario": scenario,
        "workflow_runtime": workflow_runtime,
        "activity_runtime": activity_runtime,
        "workflow_id": f"workflow-{scenario}",
        "run_id": f"run-{scenario}",
        "status": "passed",
    }


PHP_RUNTIME_CELLS = [
    cell("php_same_language", "php", "php"),
    cell("php_to_python", "php", "python"),
    cell("php_to_rust", "php", "rust"),
    cell("python_to_php", "python", "php"),
    cell("rust_to_php", "rust", "php"),
]


class PhpSdkMetadataTest(unittest.TestCase):
    def artifact(self, runtime_matrix: dict[str, object] | None) -> dict[str, object]:
        return polyglot_smoke.artifact_metadata(
            {"sdk-php": "0.2.0"},
            php_worker_error=None,
            runtime_matrix=runtime_matrix,
        )["sdk_php"]

    def test_marks_sdk_exercised_after_every_required_php_cell_passes(self) -> None:
        artifact = self.artifact({"status": "passed", "cells": PHP_RUNTIME_CELLS})
        execution = artifact["execution_evidence"]

        self.assertTrue(artifact["registration_evidence"]["observed"])
        self.assertTrue(artifact["exercised"])
        self.assertEqual("completed", execution["status"])
        self.assertEqual(6, execution["required_cell_count"])
        self.assertEqual(6, execution["completed_cell_count"])
        self.assertEqual([], execution["missing_cells"])

    def test_artifact_preflight_block_reports_registration_without_execution(self) -> None:
        artifact = self.artifact(None)
        execution = artifact["execution_evidence"]

        self.assertTrue(artifact["registration_evidence"]["observed"])
        self.assertFalse(artifact["exercised"])
        self.assertEqual("not_run", execution["status"])
        self.assertEqual([], execution["completed_cells"])
        self.assertEqual(6, len(execution["missing_cells"]))

    def test_failed_runtime_matrix_reports_partial_cells_without_exercising_sdk(self) -> None:
        failed_cell = {
            "scenario": "php_to_rust",
            "workflow_runtime": "php",
            "activity_runtime": "rust",
            "status": "failed",
        }
        artifact = self.artifact({
            "status": "failed",
            "cells": PHP_RUNTIME_CELLS[:2],
            "failed_cell": failed_cell,
        })
        execution = artifact["execution_evidence"]

        self.assertFalse(artifact["exercised"])
        self.assertEqual("runtime_matrix_failed", execution["reason"])
        self.assertEqual(failed_cell, execution["failed_cell"])
        self.assertEqual(3, execution["completed_cell_count"])
        self.assertEqual(3, len(execution["missing_cells"]))
        self.assertEqual(
            {"workflow", "activity"},
            {item["php_role"] for item in execution["completed_cells"]},
        )


if __name__ == "__main__":
    unittest.main()
