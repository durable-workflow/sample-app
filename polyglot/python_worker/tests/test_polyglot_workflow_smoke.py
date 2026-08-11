from __future__ import annotations

import asyncio
import importlib.util
import os
import sys
import types
import unittest
from pathlib import Path


os.environ.update(
    {
        "DURABLE_WORKFLOW_SERVER_URL": "http://server:8080",
        "DURABLE_SERVER_IMAGE": "durableworkflow/server:2.0.0-rc.1",
        "DURABLE_WORKFLOW_PHP_SDK_VERSION": "2.0.0-rc.1",
        "DURABLE_WORKFLOW_PYTHON_SDK_VERSION": "2.0.0-rc.1",
        "DURABLE_WORKFLOW_RUST_SDK_VERSION": "2.0.0-rc.1",
    }
)

durable_workflow = sys.modules.get("durable_workflow") or types.ModuleType("durable_workflow")
durable_workflow.Client = object
sys.modules["durable_workflow"] = durable_workflow

module_path = Path(__file__).parents[1] / "scripts" / "polyglot_workflow_smoke.py"
spec = importlib.util.spec_from_file_location("polyglot_workflow_smoke_contract", module_path)
assert spec is not None and spec.loader is not None
polyglot_workflow_smoke = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = polyglot_workflow_smoke
spec.loader.exec_module(polyglot_workflow_smoke)


def request() -> dict[str, object]:
    return {
        "name": "Ada",
        "items": [
            {"quantity": 2, "unit_price_cents": 1500},
            {"quantity": 1, "unit_price_cents": 4200},
        ],
    }


def result() -> dict[str, object]:
    return {
        "workflow": "PolyglotWorkflow",
        "workflow_runtime": "php",
        "activity_runtimes": {"calculation": "python", "receipt": "rust"},
        "task_queues": {
            "workflow": "polyglot-workflow",
            "python_activity": "polyglot-php-to-python",
            "rust_activity": "polyglot-to-rust",
        },
        "request": request(),
        "python_calculation": {
            "runtime": "python",
            "operation": "calculate_order_total",
            "item_count": 2,
            "total_cents": 7200,
        },
        "rust_receipt": {
            "runtime": "rust",
            "operation": "format_receipt",
            "calculation_runtime": "python",
            "name": "Ada",
            "item_count": 2,
            "total_cents": 7200,
            "display_total": "$72.00",
            "message": "Ada: 2 items total $72.00",
        },
        "summary": (
            "PHP orchestrated a Python order calculation and a Rust receipt: "
            "Ada: 2 items total $72.00"
        ),
    }


class PolyglotWorkflowResultTest(unittest.TestCase):
    def test_accepts_the_combined_three_runtime_result(self) -> None:
        value = result()

        self.assertIs(value, polyglot_workflow_smoke.validate_result(value, request()))

    def test_rejects_a_rust_result_that_did_not_receive_python_evidence(self) -> None:
        value = result()
        value["rust_receipt"]["calculation_runtime"] = "php"

        with self.assertRaisesRegex(RuntimeError, "pass the Python result to Rust"):
            polyglot_workflow_smoke.validate_result(value, request())

    def test_accepts_python_pep_440_prerelease_registration_versions(self) -> None:
        self.assertTrue(
            polyglot_workflow_smoke.versions_match(
                "python",
                "durable-workflow-python/2.0.0rc1",
                "2.0.0-rc.1",
            )
        )


class PolyglotWorkflowReadinessTest(unittest.IsolatedAsyncioTestCase):
    async def test_waits_for_php_python_and_rust_workers_concurrently(self) -> None:
        calls: list[dict[str, str]] = []
        all_started = asyncio.Event()
        original = polyglot_workflow_smoke.wait_for_worker

        async def wait_for_worker(
            requirement: dict[str, str], timeout_seconds: float
        ) -> dict[str, str]:
            calls.append(requirement)
            if len(calls) == 3:
                all_started.set()
            await asyncio.wait_for(all_started.wait(), timeout=0.5)
            return {
                "service": requirement["service"],
                "runtime": requirement["runtime"],
                "task_queue": requirement["task_queue"],
                "status": "ready",
            }

        polyglot_workflow_smoke.wait_for_worker = wait_for_worker
        try:
            evidence = await polyglot_workflow_smoke.wait_for_workers(timeout_seconds=37.0)
        finally:
            polyglot_workflow_smoke.wait_for_worker = original

        self.assertEqual({"php", "python", "rust"}, {item["runtime"] for item in evidence})
        self.assertEqual(
            {"polyglot-workflow", "polyglot-php-to-python", "polyglot-to-rust"},
            {item["task_queue"] for item in evidence},
        )


if __name__ == "__main__":
    unittest.main()
