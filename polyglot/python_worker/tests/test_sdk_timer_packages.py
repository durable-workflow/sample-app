from __future__ import annotations

import copy
import json
import types
import unittest
from unittest.mock import patch

import test_sdk_timers as fixtures


timers = fixtures.timers
PREVIOUS = {"php": "2.5.0", "python": "2.5.15", "rust": "3.5.4"}
CURRENT = {"php": "2.5.1", "python": "2.6.0", "rust": "3.5.5"}


class PackageUpgradeEvidenceTest(unittest.IsolatedAsyncioTestCase):
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
        self.workers = [fixtures.WorkerRosterEvidenceTest.worker(runtime) for runtime in timers.RUNTIMES]
        for worker in self.workers:
            worker.raw["sdk_version"] = CURRENT[worker.runtime]
            for suffix in ("timer", "concurrent-timers"):
                name = f"polyglot.{worker.runtime}.{suffix}"
                worker.supported_workflow_types.append(name)
                worker.raw["workflow_definition_fingerprints"][name] = "sha256:" + "a" * 64
        self.capture_workers()
        self.case()

    def capture_workers(self):
        self.snapshot = {"phase": "workers_ready", "project": "owned-project", "workers": [
            {**timers.worker_snapshot(worker), "sdk_version": worker.raw.get("sdk_version")}
            for worker in self.workers]}
        self.environment = patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_WORKERS": json.dumps(self.snapshot)})
        self.environment.start()
        self.addCleanup(self.environment.stop)

    def case(self, *, concurrent=False, fresh=False):
        self.fixture = fixtures.ConcurrentTimerEvidenceTest() if concurrent else fixtures.TimerEvidenceTest()
        self.fixture.setUp()
        self.runtime = "python" if concurrent else "rust"
        self.scenario = ("concurrent-" if concurrent else "") + "sdk-upgrade" + ("-fresh" if fresh else "")
        prefix_length = 4 if concurrent else 2
        self.fixture.history["events"][0]["payload"].update(
            input=[self.fixture.execution.workflow_id],
            workflow_definition_fingerprint_source="worker", workflow_definition_fingerprint="sha256:" + "a" * 64)
        self.fixture.original.update(
            history_prefix=copy.deepcopy(self.fixture.history["events"][:prefix_length]),
            sdk_version=(CURRENT if fresh else PREVIOUS)[self.runtime],
            worker_id=f"owned-{self.runtime}" if fresh else f"old-{self.runtime}")

    async def verify(self):
        with patch.object(timers, "observe", fixtures.AsyncMock(return_value=(
                self.fixture.handle, self.fixture.execution, self.fixture.history))), \
                patch.object(timers, "original_pending", return_value=self.fixture.original), \
                patch.object(timers, "emit"):
            await timers.verify(None, self.runtime, self.scenario)

    async def test_same_original_completes_on_new_package_for_both_shapes(self):
        for concurrent in (False, True):
            with self.subTest(concurrent=concurrent):
                self.case(concurrent=concurrent)
                await self.verify()

    async def test_ready_refuses_a_current_image_label_backed_by_the_wrong_sdk(self):
        self.workers[-1].raw["sdk_version"] = PREVIOUS["rust"]
        self.assertIsNone(timers.ready_snapshot(types.SimpleNamespace(workers=self.workers)))

    async def test_ready_refuses_missing_or_non_string_sdk_identity(self):
        for value in (None, 17, True, ""):
            with self.subTest(value=value):
                self.workers[-1].raw["sdk_version"] = value
                self.assertIsNone(timers.ready_snapshot(types.SimpleNamespace(workers=self.workers)))

    async def test_replay_must_preserve_the_complete_original_history_prefix(self):
        self.fixture.history["events"][0]["payload"]["input"] = ["rewritten"]
        with self.assertRaisesRegex(RuntimeError, "prefix"):
            await self.verify()

    async def test_an_unchanged_sdk_cannot_be_called_an_upgrade(self):
        self.fixture.original["sdk_version"] = CURRENT[self.runtime]
        with self.assertRaisesRegex(RuntimeError, "SDK"):
            await self.verify()

    async def test_an_unselected_previous_sdk_cannot_pass(self):
        self.fixture.original["sdk_version"] = "0.0.1"
        with self.assertRaisesRegex(RuntimeError, "SDK"):
            await self.verify()

    async def test_upgrade_requires_an_actual_replacement_worker(self):
        self.fixture.original["worker_id"] = f"owned-{self.runtime}"
        with self.assertRaisesRegex(RuntimeError, "worker"):
            await self.verify()

    async def test_worker_replacement_cannot_rebind_the_python_definition(self):
        self.case(concurrent=True)
        self.snapshot["workers"][1]["workflow_definition_fingerprints"][
            "polyglot.python.concurrent-timers"] = "sha256:" + "b" * 64
        with patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_WORKERS": json.dumps(self.snapshot)}), \
                self.assertRaisesRegex(RuntimeError, "definition"):
            await self.verify()

    async def test_upgrade_does_not_introduce_an_unrequested_version_command(self):
        self.fixture.history["events"].insert(-1, fixtures.event(
            "VersionMarkerRecorded", sequence=2, version=1))
        with self.assertRaisesRegex(RuntimeError, "marker"):
            await self.verify()

    async def test_result_keeps_integer_types_for_single_and_concurrent_timers(self):
        for concurrent in (False, True):
            with self.subTest(concurrent=concurrent):
                self.case(concurrent=concurrent)
                result = self.fixture.handle.result.return_value
                result["timer_seconds"] = [60.0, 30.0, 45.0] if concurrent else 30.0
                with self.assertRaises(RuntimeError):
                    await self.verify()

    async def test_fresh_controls_use_the_current_package_without_a_fictitious_upgrade(self):
        for concurrent in (False, True):
            with self.subTest(concurrent=concurrent):
                self.case(concurrent=concurrent, fresh=True)
                await self.verify()

    async def test_fresh_control_cannot_run_on_the_previous_sdk(self):
        self.case(fresh=True)
        self.fixture.original["sdk_version"] = PREVIOUS[self.runtime]
        with self.assertRaisesRegex(RuntimeError, "SDK"):
            await self.verify()


if __name__ == "__main__":
    unittest.main()
