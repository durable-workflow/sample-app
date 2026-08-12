from __future__ import annotations

import asyncio
import importlib.util
import json
import os
import re
import sys
import types
import unittest
from pathlib import Path


def pinned_python_sdk_version() -> str:
    tuple_path = (
        Path(__file__).parents[3]
        / "polyglot"
        / "qualified-artifact-tuple.json"
    )
    payload = json.loads(tuple_path.read_text())

    return payload["artifacts"]["sdk-python"]


PYTHON_SDK_VERSION = pinned_python_sdk_version()
PYTHON_PEP_440_VERSION = re.sub(
    r"-(alpha|beta|rc)\.",
    lambda match: {"alpha": "a", "beta": "b", "rc": "rc"}[match.group(1)],
    PYTHON_SDK_VERSION,
)

REQUIRED_ENV = {
    "DURABLE_WORKFLOW_SERVER_URL": "http://server:8080",
    "DURABLE_SERVER_IMAGE": "durableworkflow/server:0.2.0",
    "DURABLE_WORKFLOW_CLI_VERSION": "0.2.0",
    "DURABLE_WORKFLOW_PHP_SDK_VERSION": "0.2.0",
    "DURABLE_WORKFLOW_PYTHON_SDK_VERSION": PYTHON_SDK_VERSION,
    "DURABLE_WORKFLOW_RUST_SDK_VERSION": "0.2.0",
    "DURABLE_WORKFLOW_WORKFLOW_VERSION": "2.0.0-alpha.1",
    "DURABLE_WORKFLOW_WATERLINE_VERSION": "2.0.0-alpha.1",
    "DURABLE_WORKFLOW_RUST_AVRO_VERSION": "0.21.0",
    "DURABLE_WORKFLOW_PYTHON_FASTAVRO_VERSION": "1.12.2",
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


class ArtifactVersionFindingsTest(unittest.TestCase):
    def versions(self, python_version: str) -> dict[str, str]:
        versions = dict(polyglot_smoke.REQUIRED_ARTIFACT_VERSIONS)
        versions["sdk-python"] = python_version
        return versions

    def test_accepts_python_pep_440_spelling_for_the_required_release_candidate(
        self,
    ) -> None:
        stale, missing = polyglot_smoke.artifact_version_findings(
            self.versions(PYTHON_PEP_440_VERSION)
        )

        self.assertEqual({}, stale)
        self.assertEqual({}, missing)

    def test_rejects_a_different_python_release_candidate(self) -> None:
        stale, missing = polyglot_smoke.artifact_version_findings(
            self.versions("2.0.0rc4")
        )

        self.assertEqual(
            {
                "sdk-python": {
                    "expected": PYTHON_SDK_VERSION,
                    "actual": "2.0.0rc4",
                }
            },
            stale,
        )
        self.assertEqual({}, missing)

    def test_rejects_a_legacy_python_version(self) -> None:
        stale, missing = polyglot_smoke.artifact_version_findings(
            self.versions("0.4.0")
        )

        self.assertEqual(
            {
                "sdk-python": {
                    "expected": PYTHON_SDK_VERSION,
                    "actual": "0.4.0",
                }
            },
            stale,
        )
        self.assertEqual({}, missing)

    def test_preserves_python_pep_440_beta_compatibility(self) -> None:
        self.assertTrue(
            polyglot_smoke.artifact_versions_match(
                "sdk-python",
                "2.0.0b17",
                "2.0.0-beta.17",
            )
        )

    def test_does_not_apply_python_prerelease_spelling_to_other_artifacts(
        self,
    ) -> None:
        self.assertFalse(
            polyglot_smoke.artifact_versions_match(
                "sdk-php",
                "2.0.0rc1",
                PYTHON_SDK_VERSION,
            )
        )

    def test_rust_artifact_metadata_uses_cargo_exact_requirement_syntax(self) -> None:
        versions = self.versions(PYTHON_PEP_440_VERSION)
        versions["sdk-rust"] = PYTHON_SDK_VERSION
        rust = polyglot_smoke.artifact_metadata(versions)["sdk_rust"]

        self.assertEqual(
            f"cargo add durable-workflow@={PYTHON_SDK_VERSION}",
            rust["pin"],
        )


class NativeBinaryEvidenceTest(unittest.TestCase):
    def evidence(self, runtime: str, native_type: str, text_type: str) -> dict[str, object]:
        return {
            "runtime": runtime,
            "native_type": native_type,
            "base64": "cG9seWdsb3QtYmluYXJ5AP8B",
            "byte_length": 18,
            "matches_expected": True,
            "text_type": text_type,
            "text_value": "polyglot-binary",
            "text_and_bytes_distinct": True,
        }

    def test_accepts_executable_native_byte_evidence_from_both_boundaries(self) -> None:
        result = {
            "binary_evidence": {
                "workflow": self.evidence("python", "bytes", "str"),
                "activity": self.evidence("rust", "AvroValue::Bytes", "String"),
            },
        }

        self.assertEqual(
            result["binary_evidence"],
            polyglot_smoke.assert_native_binary_evidence(
                result,
                direction="python_to_rust",
                workflow_runtime="python",
                activity_runtime="rust",
                expected_base64="cG9seWdsb3QtYmluYXJ5AP8B",
            ),
        )

    def test_rejects_a_metadata_claim_without_native_byte_equality(self) -> None:
        result = {
            "binary_evidence": {
                "workflow": self.evidence("php", "AvroBinaryValue", "string"),
                "activity": {
                    **self.evidence("python", "bytes", "str"),
                    "matches_expected": False,
                },
            },
        }

        with self.assertRaisesRegex(RuntimeError, "activity native bytes evidence changed"):
            polyglot_smoke.assert_native_binary_evidence(
                result,
                direction="php_to_python",
                workflow_runtime="php",
                activity_runtime="python",
                expected_base64="cG9seWdsb3QtYmluYXJ5AP8B",
            )


class WorkerRegistrationReadinessTest(unittest.IsolatedAsyncioTestCase):
    async def test_waits_for_every_required_registration_concurrently(self) -> None:
        expected_count = len(polyglot_smoke.REQUIRED_WORKER_REGISTRATIONS)
        calls: list[dict[str, object]] = []
        all_started = asyncio.Event()
        original_wait_for_worker = polyglot_smoke.wait_for_worker

        async def wait_for_worker(**arguments: object) -> str | None:
            calls.append(arguments)
            if len(calls) == expected_count:
                all_started.set()
            await asyncio.wait_for(all_started.wait(), timeout=0.5)
            return PYTHON_SDK_VERSION if arguments["runtime"] in {"php", "rust"} else None

        polyglot_smoke.wait_for_worker = wait_for_worker
        try:
            evidence = await polyglot_smoke.wait_for_required_worker_registrations(
                timeout_seconds=37.0,
            )
        finally:
            polyglot_smoke.wait_for_worker = original_wait_for_worker

        self.assertEqual(expected_count, len(calls))
        self.assertEqual(expected_count, len(evidence))
        self.assertEqual({"registered"}, {item["status"] for item in evidence})
        self.assertEqual(
            {
                "python-workflow-worker",
                "python-activity-worker",
                "php-same-workflow-worker",
                "php-same-activity-worker",
                "php-workflow-worker",
                "polyglot-workflow-worker",
                "php-to-rust-workflow-worker",
                "php-query-worker",
                "php-activity-worker",
                "rust-workflow-worker",
                "rust-activity-worker",
            },
            {item["service"] for item in evidence},
        )
        self.assertEqual({37.0}, {call["timeout_seconds"] for call in calls})


if __name__ == "__main__":
    unittest.main()
