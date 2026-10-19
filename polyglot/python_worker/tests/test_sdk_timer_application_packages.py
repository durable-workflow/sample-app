from __future__ import annotations

import json
import types
import unittest
from unittest.mock import AsyncMock, patch

import test_sdk_timers as fixtures
from test_sdk_timer_packages import CURRENT, PREVIOUS


timers = fixtures.timers


class CombinedUpgradeEvidenceTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        environment = patch.dict("os.environ", {
            "DURABLE_WORKFLOW_NAMESPACE": "default", "DURABLE_WORKFLOW_TIMER_ID": "owned-project",
            "DURABLE_WORKFLOW_TIMER_SDK_VERSIONS": json.dumps(CURRENT),
            "DURABLE_WORKFLOW_TIMER_SDK_TARGETS": json.dumps(CURRENT),
            "DURABLE_WORKFLOW_TIMER_SDK_PREVIOUS": json.dumps(PREVIOUS),
            "DURABLE_WORKFLOW_WORKER_STOPPED_AT": "2026-10-05T00:00:10Z",
            "DURABLE_WORKFLOW_WORKER_RESTART_AT": "2026-10-05T00:01:01Z",
        })
        environment.start()
        self.addCleanup(environment.stop)
        self.case()

    def case(self, *, runtime="python", concurrent=False, fresh=False):
        self.runtime = runtime
        self.fixture = fixtures.ApplicationUpgradeEvidenceTest()
        self.fixture.case(version=2 if fresh else 1, concurrent=concurrent)
        self.fixture.result["workflow_runtime"] = runtime
        self.scenario = ("concurrent-" if concurrent else "") + "sdk-application-upgrade" + ("-fresh" if fresh else "")
        self.fixture.original.update(sdk_version=(CURRENT if fresh else PREVIOUS)[runtime],
                                     worker_id=f"owned-{runtime}" if fresh else f"old-{runtime}")
        self.workers = [fixtures.WorkerRosterEvidenceTest.worker(language) for language in timers.RUNTIMES]
        fingerprint = self.fixture.history["events"][0]["payload"]["workflow_definition_fingerprint"]
        for worker in self.workers:
            worker.raw["sdk_version"] = f"durable-workflow-{worker.runtime}/{CURRENT[worker.runtime]}"
            for suffix in ("upgrade-timer", "upgrade-concurrent-timers"):
                worker.raw["workflow_definition_fingerprints"][f"polyglot.{worker.runtime}.{suffix}"] = fingerprint

    def roster(self):
        return types.SimpleNamespace(workers=self.workers)

    async def verify(self):
        snapshot = {"phase": "workers_ready", "project": "owned-project",
                    "workers": [timers.worker_snapshot(worker) for worker in self.workers]}
        handle = types.SimpleNamespace(result=AsyncMock(return_value=self.fixture.result))
        with patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_WORKERS": json.dumps(snapshot)}), \
                patch.object(timers, "observe", AsyncMock(return_value=(handle, self.fixture.execution, self.fixture.history))), \
                patch.object(timers, "original_pending", return_value=self.fixture.original), \
                patch.object(timers, "emit") as output:
            await timers.verify(None, self.runtime, self.scenario)
        return output.call_args.kwargs

    async def test_all_three_runtimes_preserve_old_branches_and_select_new_fresh_branches(self):
        for runtime in timers.RUNTIMES:
            for concurrent in (False, True):
                for fresh in (False, True):
                    with self.subTest(runtime=runtime, concurrent=concurrent, fresh=fresh):
                        self.case(runtime=runtime, concurrent=concurrent, fresh=fresh)
                        result = await self.verify()
                        self.assertEqual(result["sdk_version"], CURRENT[runtime])
                        self.assertEqual(result["previous_sdk_version"], (CURRENT if fresh else PREVIOUS)[runtime])
                        self.assertEqual(result["version_marker"], self.fixture.original["version_marker"])

    async def test_replacement_sdk_must_match_the_selected_package(self):
        self.workers[1].raw["sdk_version"] = "durable-workflow-python/9.9.9"
        with self.assertRaisesRegex(RuntimeError, "SDK"):
            await self.verify()

    async def test_original_package_identity_is_required_even_with_a_valid_version_marker(self):
        self.fixture.original["sdk_version"] = CURRENT["python"]
        with self.assertRaisesRegex(RuntimeError, "SDK"):
            await self.verify()

    async def test_replacement_cannot_change_a_bound_python_definition(self):
        self.workers[1].raw["workflow_definition_fingerprints"]["polyglot.python.upgrade-timer"] = "sha256:" + "c" * 64
        with self.assertRaisesRegex(RuntimeError, "definition"):
            await self.verify()

    async def test_original_timer_must_fire_inside_worker_absence(self):
        self.fixture.history["events"][-2]["payload"]["fired_at"] = "2026-10-05T00:01:10+00:00"
        with self.assertRaisesRegex(RuntimeError, "absent"):
            await self.verify()

    async def test_original_prefix_marker_bounds_and_scalar_types_are_not_relaxed(self):
        for mutation in ("prefix", "marker", "type"):
            with self.subTest(mutation=mutation):
                self.case()
                if mutation == "prefix":
                    self.fixture.original["history_prefix"][0]["payload"]["extra"] = True
                elif mutation == "marker":
                    self.fixture.history["events"][1]["payload"]["max_supported"] = 2
                else:
                    self.fixture.result["version"] = True
                with self.assertRaises(RuntimeError):
                    await self.verify()

    def test_ready_requires_application_timer_contracts_on_the_selected_packages(self):
        self.assertIsNotNone(timers.ready_snapshot(self.roster(), self.scenario))
        for worker in self.workers:
            worker.supported_workflow_types = [f"polyglot.{worker.runtime}.timer", f"polyglot.{worker.runtime}.concurrent-timers"]
        self.assertIsNone(timers.ready_snapshot(self.roster(), self.scenario))


if __name__ == "__main__":
    unittest.main()
