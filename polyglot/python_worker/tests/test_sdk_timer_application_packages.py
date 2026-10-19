from __future__ import annotations

import copy
import json
import types
import unittest
from datetime import datetime, timezone
from unittest.mock import AsyncMock, patch

import test_sdk_timers as fixtures
from test_sdk_timer_packages import CURRENT, PREVIOUS


timers = fixtures.timers


def shift_command_sequences(payload):
    for field in ("sequence", "parallel_group_base_sequence"):
        if field in payload:
            payload[field] += 8


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

    async def test_consistently_renumbered_commands_cannot_qualify_a_new_sdk(self):
        for concurrent, fresh in ((False, False), (False, True), (True, False), (True, True)):
            with self.subTest(concurrent=concurrent, fresh=fresh):
                self.case(concurrent=concurrent, fresh=fresh)
                for history in (self.fixture.history["events"], self.fixture.original["history_prefix"]):
                    for item in history:
                        shift_command_sequences(item["payload"])
                shift_command_sequences(self.fixture.original["version_marker"])
                scheduled = self.fixture.original["timers"] if concurrent else [self.fixture.original["timer"]]
                for timer in scheduled:
                    shift_command_sequences(timer)
                with self.assertRaisesRegex(RuntimeError, "sequence"):
                    await self.verify()

    def test_ready_requires_application_timer_contracts_on_the_selected_packages(self):
        self.assertIsNotNone(timers.ready_snapshot(self.roster(), self.scenario))
        for worker in self.workers:
            worker.supported_workflow_types = [f"polyglot.{worker.runtime}.timer", f"polyglot.{worker.runtime}.concurrent-timers"]
        self.assertIsNone(timers.ready_snapshot(self.roster(), self.scenario))

    def test_combined_readiness_cannot_omit_the_package_selection(self):
        with patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_SDK_VERSIONS": ""}):
            with self.assertRaisesRegex(RuntimeError, "selected SDK"):
                timers.ready_snapshot(self.roster(), self.scenario)

    async def test_pending_capture_binds_both_package_identity_and_application_marker(self):
        for fresh in (False, True):
            with self.subTest(fresh=fresh):
                self.case(fresh=fresh)
                selected = CURRENT if fresh else PREVIOUS
                for worker in self.workers:
                    worker.raw["sdk_version"] = f"durable-workflow-{worker.runtime}/{selected[worker.runtime]}"
                snapshot = {"phase": "workers_ready", "project": "owned-project",
                            "workers": [timers.worker_snapshot(worker) for worker in self.workers]}
                workflow_id = f"owned-project-{self.scenario}-python"
                execution = types.SimpleNamespace(workflow_id=workflow_id, run_id="original-run", status="waiting")
                history = {"events": copy.deepcopy(self.fixture.original["history_prefix"])}
                history["events"][0]["payload"]["input"] = [workflow_id]
                client = types.SimpleNamespace(start_workflow=AsyncMock())
                with patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_WORKERS": json.dumps(snapshot),
                        "DURABLE_WORKFLOW_TIMER_SDK_VERSIONS": json.dumps(selected)}), \
                        patch.object(timers, "observe", AsyncMock(return_value=(None, execution, history))), \
                        patch.object(timers, "datetime", wraps=datetime) as clock, patch.object(timers, "emit") as output:
                    clock.now.return_value = datetime(2026, 10, 5, tzinfo=timezone.utc)
                    await timers.pending(client, "python", self.scenario)
                recorded = output.call_args.kwargs
                self.assertEqual(recorded["sdk_version"], selected["python"])
                self.assertEqual(recorded["worker_id"], "owned-python")
                self.assertEqual(recorded["version_marker"], self.fixture.original["version_marker"])
                self.assertEqual(recorded["history_prefix"], history["events"])
                self.assertEqual(client.start_workflow.call_args.kwargs["workflow_type"], "polyglot.python.upgrade-timer")


if __name__ == "__main__":
    unittest.main()
