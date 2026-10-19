from __future__ import annotations

import copy
import importlib.util
import json
import sys
import types
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import AsyncMock, Mock, patch


if "durable_workflow" not in sys.modules:
    stub = types.ModuleType("durable_workflow")
    stub.Client = object
    sys.modules["durable_workflow"] = stub
path = Path(__file__).parents[1] / "scripts" / "sdk_timers.py"
spec = importlib.util.spec_from_file_location("sdk_timers", path)
timers = importlib.util.module_from_spec(spec)
spec.loader.exec_module(timers)


def event(kind, **payload):
    return {"event_type": kind, "payload": payload}


class ApplicationUpgradeEvidenceTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.case()

    def case(self, *, version=1, concurrent=False):
        self.scenario = ("concurrent-" if concurrent else "") + "application-upgrade"
        if version == 2:
            self.scenario += "-fresh"
        self.execution = types.SimpleNamespace(workflow_id="upgrade-python", run_id="original-run", status="completed")
        delays = [60, 30, 45] if concurrent else [30]
        if version == 2:
            delays = [delay + 15 for delay in delays]
        marker = event("VersionMarkerRecorded", change_id="sample-timer-application", sequence=1,
                       version=version, min_supported=1, max_supported=version)
        scheduled = []
        start = datetime(2026, 10, 5, tzinfo=timezone.utc)
        for index, delay in enumerate(delays):
            payload = {"timer_id": f"timer-{index}", "sequence": index + 2, "delay_seconds": delay,
                       "fire_at": (start + timedelta(seconds=delay)).isoformat()}
            if concurrent:
                payload.update(parallel_group_id="original-group", parallel_group_kind="timer",
                               parallel_group_base_sequence=2, parallel_group_size=3,
                               parallel_group_index=index, parallel_group_mode="all")
            scheduled.append(event("TimerScheduled", **payload))
        prefix = [event("WorkflowStarted", input=["upgrade-python"],
                        workflow_definition_fingerprint="sha256:" + ("a" if version == 1 else "b") * 64,
                        workflow_definition_fingerprint_source="worker"), marker, *scheduled]
        self.history = {"events": [*copy.deepcopy(prefix), *(
            event("TimerFired", **copy.deepcopy(item["payload"]), fired_at=item["payload"]["fire_at"])
            for item in scheduled), event("WorkflowCompleted")]}
        self.original = {"workflow_id": "upgrade-python", "run_id": "original-run",
                         "version_marker": copy.deepcopy(marker["payload"]), "history_prefix": copy.deepcopy(prefix)}
        key = "timers" if concurrent else "timer"
        self.original[key] = [copy.deepcopy(item["payload"]) for item in scheduled] if concurrent else copy.deepcopy(scheduled[0]["payload"])
        self.result = {"workflow_runtime": "python", "request": "upgrade-python", "application_revision": "v2",
                       "version": version, "timer_seconds": delays if concurrent else delays[0]}

    async def verify(self):
        handle = types.SimpleNamespace(result=AsyncMock(return_value=self.result))
        with patch.object(timers, "observe", AsyncMock(return_value=(handle, self.execution, self.history))), \
                patch.object(timers, "original_pending", return_value=self.original), patch.object(timers, "emit"):
            await timers.verify(None, "python", self.scenario)

    async def test_old_and_fresh_branches_complete_on_replacement_definition(self):
        for version in (1, 2):
            for concurrent in (False, True):
                with self.subTest(version=version, concurrent=concurrent):
                    self.case(version=version, concurrent=concurrent)
                    await self.verify()

    async def test_fired_waits_for_every_upgrade_barrier_member(self):
        for version in (1, 2):
            with self.subTest(version=version):
                self.case(version=version, concurrent=True)
                self.execution.status = "waiting"
                resolved = timers.events(self.history, "TimerFired")
                prefix = self.original["history_prefix"]
                first = min(resolved, key=lambda item: item["payload"]["delay_seconds"])
                partial = {"events": [*copy.deepcopy(prefix), copy.deepcopy(first)]}
                complete = {"events": [*copy.deepcopy(prefix), *copy.deepcopy(resolved)]}
                observer = AsyncMock(side_effect=[
                    (None, self.execution, partial), (None, self.execution, complete),
                ])
                with patch.object(timers, "observe", observer), \
                        patch.object(timers, "original_pending", return_value=self.original), \
                        patch.object(timers, "emit"), patch.object(timers.asyncio, "sleep", AsyncMock()):
                    await timers.fired(None, "python", self.scenario)
                self.assertEqual(observer.await_count, 2)

    async def test_replacement_cannot_report_the_old_application(self):
        self.result["application_revision"] = "v1"
        with self.assertRaises(RuntimeError):
            await self.verify()

    async def test_unbound_python_class_upgrade_cannot_pass(self):
        for version in (1, 2):
            for concurrent in (False, True):
                with self.subTest(version=version, concurrent=concurrent):
                    self.case(version=version, concurrent=concurrent)
                    for prefix in (self.history["events"], self.original["history_prefix"]):
                        prefix[0]["payload"].pop("workflow_definition_fingerprint")
                        prefix[0]["payload"].pop("workflow_definition_fingerprint_source")
                    with self.assertRaisesRegex(RuntimeError, "definition fingerprint"):
                        await self.verify()

    async def test_definition_binding_must_be_worker_sourced_and_nonempty(self):
        for key, value in (
            ("workflow_definition_fingerprint", None), ("workflow_definition_fingerprint", ""),
            ("workflow_definition_fingerprint", "  "), ("workflow_definition_fingerprint", True),
            ("workflow_definition_fingerprint", 17),
            ("workflow_definition_fingerprint_source", None),
            ("workflow_definition_fingerprint_source", "legacy"),
        ):
            with self.subTest(key=key, value=value):
                self.case()
                for prefix in (self.history["events"], self.original["history_prefix"]):
                    prefix[0]["payload"][key] = value
                with self.assertRaisesRegex(RuntimeError, "definition fingerprint"):
                    await self.verify()

    async def test_definition_binding_requires_one_original_workflow_start(self):
        for count in (0, 2):
            with self.subTest(count=count):
                self.case()
                for prefix in (self.history["events"], self.original["history_prefix"]):
                    start = prefix.pop(0)
                    prefix[:0] = [copy.deepcopy(start) for _ in range(count)]
                with self.assertRaisesRegex(RuntimeError, "definition fingerprint"):
                    await self.verify()

    async def test_unbound_pending_class_cannot_emit_qualified_evidence(self):
        self.execution.status = "waiting"
        history = {"events": copy.deepcopy(self.original["history_prefix"])}
        history["events"][0]["payload"].pop("workflow_definition_fingerprint")
        for item in timers.events(history, "TimerScheduled"):
            item["payload"]["fire_at"] = (datetime.now(timezone.utc) + timedelta(seconds=30)).isoformat()
        client = types.SimpleNamespace(start_workflow=AsyncMock())
        with patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_ID": "upgrade"}), \
                patch.object(timers, "observe", AsyncMock(return_value=(None, self.execution, history))), \
                patch.object(timers, "emit") as emit:
            with self.assertRaisesRegex(RuntimeError, "definition fingerprint"):
                await timers.pending(client, "python", self.scenario)
        emit.assert_not_called()

    async def test_recorded_marker_cannot_be_missing_duplicated_or_changed(self):
        for change in ("missing", "duplicate", "change_id", "version", "max_supported"):
            with self.subTest(change=change):
                self.case()
                marker = self.history["events"][1]
                if change == "missing":
                    self.history["events"].remove(marker)
                elif change == "duplicate":
                    self.history["events"].insert(2, copy.deepcopy(marker))
                else:
                    marker["payload"][change] = "other" if change == "change_id" else 2
                with self.assertRaises(RuntimeError):
                    await self.verify()

    async def test_initial_marker_must_select_the_expected_typed_branch(self):
        for value in (True, 1.0, 2):
            with self.subTest(value=value):
                self.case()
                self.original["version_marker"]["version"] = value
                self.history["events"][1]["payload"]["version"] = value
                self.original["history_prefix"][1]["payload"]["version"] = value
                with self.assertRaises(RuntimeError):
                    await self.verify()

    async def test_cold_replay_preserves_the_entire_initial_history_prefix(self):
        self.history["events"][0]["payload"]["input"] = ["changed"]
        with self.assertRaises(RuntimeError):
            await self.verify()

    async def test_initial_marker_bounds_retain_authored_values_and_types(self):
        for key, value in (("min_supported", None), ("min_supported", True), ("min_supported", 1.0),
                           ("min_supported", 0), ("min_supported", 2), ("max_supported", None),
                           ("max_supported", True), ("max_supported", 1.0),
                           ("max_supported", 0), ("max_supported", 2)):
            with self.subTest(key=key, value=value):
                self.case()
                for marker in (self.history["events"][1]["payload"], self.original["history_prefix"][1]["payload"],
                               self.original["version_marker"]):
                    marker[key] = value
                with self.assertRaises(RuntimeError):
                    await self.verify()

    async def test_result_branch_preserves_scalar_types(self):
        for key, value in (("version", True), ("version", 1.0), ("timer_seconds", 30.0)):
            with self.subTest(key=key, value=value):
                self.case()
                self.result[key] = value
                with self.assertRaises(RuntimeError):
                    await self.verify()

    async def test_single_upgrade_checks_resolved_metadata_and_sequence(self):
        self.history["events"][-2]["payload"]["sequence"] = 99
        with self.assertRaises(RuntimeError):
            await self.verify()

    async def test_upgrade_cannot_replace_the_original_run(self):
        self.execution.run_id = "replacement-run"
        with self.assertRaises(RuntimeError):
            await self.verify()

    async def test_pending_captures_marker_and_prefix_for_both_versions_and_shapes(self):
        for version in (1, 2):
            for concurrent in (False, True):
                with self.subTest(version=version, concurrent=concurrent):
                    self.case(version=version, concurrent=concurrent)
                    self.execution.status = "waiting"
                    history = {"events": copy.deepcopy(self.original["history_prefix"])}
                    for item in timers.events(history, "TimerScheduled"):
                        item["payload"]["fire_at"] = (datetime.now(timezone.utc) + timedelta(
                            seconds=item["payload"]["delay_seconds"])).isoformat()
                    client = types.SimpleNamespace(start_workflow=AsyncMock())
                    with patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_ID": "upgrade"}), \
                            patch.object(timers, "observe", AsyncMock(return_value=(None, self.execution, history))), \
                            patch.object(timers, "emit") as emit:
                        await timers.pending(client, "python", self.scenario)
                    suffix = "concurrent-timers" if concurrent else "timer"
                    self.assertEqual(client.start_workflow.await_args.kwargs["workflow_type"],
                                     f"polyglot.python.upgrade-{suffix}")
                    self.assertEqual(emit.call_args.kwargs["version_marker"]["version"], version)
                    self.assertEqual(emit.call_args.kwargs["history_prefix"], history["events"])


class UnversionedUpgradeEvidenceTest(unittest.IsolatedAsyncioTestCase):
    def case(self, *, fresh=False, concurrent=False):
        self.fixture = ApplicationUpgradeEvidenceTest()
        self.fixture.case(version=2 if fresh else -1, concurrent=concurrent)
        self.fixture.scenario = ("concurrent-" if concurrent else "") + "unversioned-upgrade"
        if fresh:
            self.fixture.scenario += "-fresh"
        for history in (self.fixture.history["events"], self.fixture.original["history_prefix"]):
            if fresh:
                history[1]["payload"]["min_supported"] = -1
            else:
                del history[1]
                for item in history:
                    if item["event_type"] in ("TimerScheduled", "TimerFired"):
                        item["payload"]["sequence"] -= 1
                        if concurrent:
                            item["payload"]["parallel_group_base_sequence"] = 1
        if fresh:
            self.fixture.original["version_marker"]["min_supported"] = -1
        else:
            self.fixture.original["version_marker"] = None
            scheduled = timers.events(self.fixture.history, "TimerScheduled")
            self.fixture.original["timers" if concurrent else "timer"] = (
                [copy.deepcopy(item["payload"]) for item in scheduled] if concurrent
                else copy.deepcopy(scheduled[0]["payload"]))

    async def test_unmarked_original_replays_without_a_new_marker(self):
        for concurrent in (False, True):
            with self.subTest(concurrent=concurrent):
                self.case(concurrent=concurrent)
                await self.fixture.verify()

    async def test_fresh_original_records_the_new_version_and_legacy_range(self):
        for concurrent in (False, True):
            with self.subTest(concurrent=concurrent):
                self.case(fresh=True, concurrent=concurrent)
                await self.fixture.verify()

    async def test_replay_cannot_append_a_version_marker_to_the_old_history(self):
        self.case()
        self.fixture.history["events"].insert(-1, event("VersionMarkerRecorded",
            change_id="sample-timer-application", sequence=2, version=2, min_supported=-1, max_supported=2))
        with self.assertRaises(RuntimeError):
            await self.fixture.verify()

    async def test_new_marker_must_retain_its_exact_authored_bounds(self):
        for field, value in (("min_supported", 1), ("min_supported", -1.0),
                             ("max_supported", 3), ("max_supported", True), ("sequence", 2)):
            with self.subTest(field=field, value=value):
                self.case(fresh=True)
                for marker in (self.fixture.history["events"][1]["payload"],
                               self.fixture.original["history_prefix"][1]["payload"],
                               self.fixture.original["version_marker"]):
                    marker[field] = value
                with self.assertRaises(RuntimeError):
                    await self.fixture.verify()

    async def test_old_timer_sequences_cannot_be_rebased_to_make_room_for_a_marker(self):
        for concurrent in (False, True):
            with self.subTest(concurrent=concurrent):
                self.case(concurrent=concurrent)
                for history in (self.fixture.history["events"], self.fixture.original["history_prefix"]):
                    for item in history:
                        if item["event_type"] in ("TimerScheduled", "TimerFired"):
                            item["payload"]["sequence"] += 1
                originals = (self.fixture.original["timers"] if concurrent
                             else [self.fixture.original["timer"]])
                for timer in originals:
                    timer["sequence"] += 1
                with self.assertRaises(RuntimeError):
                    await self.fixture.verify()


class VersionIntroductionAuthoringTest(unittest.TestCase):
    def test_first_revision_has_no_marker_and_replacement_preserves_both_decisions(self):
        path = Path(__file__).parents[2] / "python_workflow" / "timer_upgrades.py"
        spec = importlib.util.spec_from_file_location("timer_upgrade_authoring", path)
        application = importlib.util.module_from_spec(spec)
        decorator = types.SimpleNamespace(defn=lambda **_: lambda definition: definition)
        with patch.object(sys.modules["durable_workflow"], "workflow", decorator, create=True):
            spec.loader.exec_module(application)
        for revision, version in (("v1", -1), ("v2", -1), ("v2", 2)):
            for concurrent in (False, True):
                with self.subTest(revision=revision, version=version, concurrent=concurrent):
                    application.applications(revision)
                    context = types.SimpleNamespace(get_version=Mock(return_value="version-command"),
                        sleep=Mock(side_effect=lambda seconds: ("timer", seconds)))
                    definition = (application.IntroductionConcurrentTimers if concurrent
                                  else application.IntroductionSingleTimer)
                    execution = definition().run(context, "original-request")
                    if revision == "v2":
                        self.assertEqual(next(execution), "version-command")
                        context.get_version.assert_called_once_with("sample-timer-application", -1, 2)
                        operation = execution.send(version)
                    else:
                        operation = next(execution)
                        context.get_version.assert_not_called()
                    delays = [60, 30, 45] if concurrent else [30]
                    if version == 2:
                        delays = [delay + 15 for delay in delays]
                    expected = [("timer", delay) for delay in delays]
                    self.assertEqual(operation, expected if concurrent else expected[0])
                    with self.assertRaises(StopIteration) as completed:
                        next(execution)
                    self.assertEqual(completed.exception.value, {"workflow_runtime": "python",
                        "request": "original-request", "application_revision": revision,
                        "version": version, "timer_seconds": delays if concurrent else delays[0]})


class WorkerRosterEvidenceTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        environment = patch.dict("os.environ", {"DURABLE_WORKFLOW_NAMESPACE": "default",
                                               "DURABLE_WORKFLOW_TIMER_ID": "owned-project"})
        environment.start()
        self.addCleanup(environment.stop)
        self.workers = [self.worker(runtime) for runtime in timers.RUNTIMES]
        self.snapshot = {"phase": "workers_ready", "project": "owned-project", "workers": [
            {"worker_id": worker.worker_id, "namespace": worker.namespace, "runtime": worker.runtime,
             "task_queue": worker.task_queue, "workflow_definition_fingerprints": copy.deepcopy(
                 worker.raw["workflow_definition_fingerprints"])} for worker in self.workers]}

    @staticmethod
    def worker(runtime):
        types_ = [f"polyglot.{runtime}.upgrade-timer", f"polyglot.{runtime}.upgrade-concurrent-timers"]
        types_ += [f"polyglot.{runtime}.unversioned-upgrade-timer",
                   f"polyglot.{runtime}.unversioned-upgrade-concurrent-timers"]
        if runtime == "python":
            types_ += ["polyglot.python.class-upgrade-timer", "polyglot.python.class-upgrade-concurrent-timers"]
        return types.SimpleNamespace(worker_id=f"owned-{runtime}", namespace="default", runtime=runtime,
            task_queue=f"polyglot-{runtime}", status="active", supported_workflow_types=types_,
            raw={"workflow_definition_fingerprints": {name: "sha256:" + "b" * 64 for name in types_}})

    def client(self, before=None, after=None):
        async def deregister(worker_id):
            return {"worker_id": worker_id, "outcome": "deregistered", "recovered_workflow_task_count": 0}
        return types.SimpleNamespace(list_workers=AsyncMock(side_effect=[
            types.SimpleNamespace(workers=self.workers if before is None else before),
            types.SimpleNamespace(workers=[] if after is None else after),
        ]), deregister_worker=AsyncMock(side_effect=deregister))

    async def retire(self, client, snapshot=None):
        with patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_WORKERS": json.dumps(
                self.snapshot if snapshot is None else snapshot)}), patch.object(timers, "emit"):
            await timers.retire_workers(client, "application-upgrade")

    async def test_ready_waits_for_registration_before_emitting_the_cohort(self):
        client = self.client(before=[self.workers[0]], after=self.workers)
        with patch.object(timers, "emit") as emit, patch.object(timers.asyncio, "sleep", AsyncMock()):
            await timers.ready_workers(client, "application-upgrade")
        self.assertEqual(client.list_workers.await_count, 2)
        self.assertEqual(emit.call_args.kwargs["workers"], self.snapshot["workers"])

    async def test_ready_refuses_ambiguous_current_definitions(self):
        client = self.client(before=[*self.workers, copy.deepcopy(self.workers[1])])
        with self.assertRaisesRegex(RuntimeError, "Ambiguous"), patch.object(timers, "emit") as emit:
            await timers.ready_workers(client, "application-upgrade")
        emit.assert_not_called()

    async def test_foreign_namespace_queue_and_stale_rows_cannot_supply_readiness(self):
        for field, value in (("namespace", "other"), ("task_queue", "foreign"), ("status", "stale")):
            with self.subTest(field=field):
                foreign = copy.deepcopy(self.workers[1])
                setattr(foreign, field, value)
                self.assertIsNone(timers.ready_snapshot(types.SimpleNamespace(
                    workers=[self.workers[0], foreign, self.workers[2]])))

    async def test_retirement_removes_only_the_captured_owned_cohort(self):
        foreign = self.worker("python")
        foreign.worker_id, foreign.task_queue = "foreign", "foreign-queue"
        client = self.client(before=[*self.workers, foreign], after=[foreign])
        await self.retire(client)
        self.assertEqual([call.args[0] for call in client.deregister_worker.await_args_list],
                         [worker.worker_id for worker in self.workers])

    async def test_invalid_scope_refuses_before_any_deletion(self):
        for field, value in (("namespace", "other"), ("task_queue", "foreign"), ("worker_id", "")):
            with self.subTest(field=field):
                snapshot = copy.deepcopy(self.snapshot)
                snapshot["workers"][-1][field] = value
                client = self.client()
                with self.assertRaises(RuntimeError):
                    await self.retire(client, snapshot)
                client.deregister_worker.assert_not_awaited()
        snapshot = copy.deepcopy(self.snapshot)
        snapshot["project"] = "another-project"
        client = self.client()
        with self.assertRaises(RuntimeError):
            await self.retire(client, snapshot)
        client.deregister_worker.assert_not_awaited()

    async def test_changed_later_registration_cannot_partially_delete_the_cohort(self):
        changed = copy.deepcopy(self.workers)
        changed[-1].raw["workflow_definition_fingerprints"] = {"other": "different"}
        client = self.client(before=changed)
        with self.assertRaisesRegex(RuntimeError, "registration changed"):
            await self.retire(client)
        client.deregister_worker.assert_not_awaited()

    async def test_already_retired_cohort_is_idempotent(self):
        client = self.client(before=[])
        await self.retire(client)
        client.deregister_worker.assert_not_awaited()

    async def test_retirement_cannot_hide_recovered_leases_or_wrong_receipts(self):
        for field, value in (("recovered_workflow_task_count", 1), ("recovered_workflow_task_count", False),
                             ("worker_id", "other"), ("outcome", "unknown")):
            with self.subTest(field=field, value=value):
                client = self.client()
                response = {"worker_id": "owned-php", "outcome": "deregistered", "recovered_workflow_task_count": 0}
                response[field] = value
                client.deregister_worker = AsyncMock(return_value=response)
                with self.assertRaisesRegex(RuntimeError, "unexpected leased task|wrong identity"):
                    await self.retire(client)


class BoundClassEvidenceTest(unittest.IsolatedAsyncioTestCase):
    def case(self, concurrent=False):
        self.fixture = ApplicationUpgradeEvidenceTest()
        self.fixture.case(concurrent=concurrent)
        self.fixture.scenario = "concurrent-class-replacement" if concurrent else "class-replacement"
        self.fixture.execution.status = "waiting"
        self.fixture.history["events"].pop()
        self.worker = WorkerRosterEvidenceTest.worker("python")
        self.debug = {"workflow_id": self.fixture.execution.workflow_id, "run_id": self.fixture.execution.run_id,
                      "pending_workflow_tasks": [{"task_id": "original-resume", "task_type": "workflow",
                          "status": "ready", "attempt_count": 0, "repair_count": 0}]}

    async def fenced(self):
        client = types.SimpleNamespace(list_workers=AsyncMock(return_value=types.SimpleNamespace(workers=[self.worker])))
        with patch.dict("os.environ", {"DURABLE_WORKFLOW_NAMESPACE": "default"}), \
                patch.object(timers, "observe", AsyncMock(return_value=(None, self.fixture.execution, self.fixture.history))), \
                patch.object(timers, "original_pending", return_value=self.fixture.original), \
                patch.object(timers, "control_read", return_value=self.debug), \
                patch.object(timers.asyncio, "sleep", AsyncMock()), patch.object(timers, "emit"):
            await timers.verify_definition_fence(client, self.fixture.scenario)

    async def test_different_class_stays_fenced_after_single_and_group_timers_fire(self):
        for concurrent in (False, True):
            with self.subTest(concurrent=concurrent):
                self.case(concurrent)
                await self.fenced()

    async def test_same_definition_cannot_masquerade_as_a_class_refusal(self):
        self.case()
        self.worker.raw["workflow_definition_fingerprints"]["polyglot.python.class-upgrade-timer"] = "sha256:" + "a" * 64
        with self.assertRaisesRegex(RuntimeError, "different class"):
            await self.fenced()

    async def test_claimed_repaired_or_foreign_tasks_cannot_qualify_refusal(self):
        for field, value in (("status", "leased"), ("lease_owner", "worker"), ("attempt_count", 1),
                             ("repair_count", 1), ("attempt_count", False), ("task_type", "activity")):
            with self.subTest(field=field, value=value):
                self.case()
                self.debug["pending_workflow_tasks"][0][field] = value
                with self.assertRaisesRegex(RuntimeError, "ready, unclaimed"):
                    await self.fenced()
        self.case()
        self.debug["run_id"] = "another-run"
        with self.assertRaisesRegex(RuntimeError, "ready, unclaimed"):
            await self.fenced()

    async def test_unexpected_history_or_completion_cannot_qualify_refusal(self):
        for kind in ("WorkflowCompleted", "VersionMarkerRecorded", "RepairRequested"):
            with self.subTest(kind=kind):
                self.case()
                self.fixture.history["events"].append(event(kind))
                with self.assertRaises(RuntimeError):
                    await self.fenced()

    async def test_original_class_restoration_and_fresh_new_class_results_stay_distinct(self):
        for concurrent in (False, True):
            for version in (1, 2):
                with self.subTest(concurrent=concurrent, version=version):
                    fixture = ApplicationUpgradeEvidenceTest()
                    fixture.case(version=version, concurrent=concurrent)
                    fixture.scenario = ("concurrent-" if concurrent else "") + "class-replacement"
                    if version == 2:
                        fixture.scenario += "-fresh"
                    fixture.result["application_revision"] = "v1" if version == 1 else "v2"
                    await fixture.verify()
                    fixture.result["application_revision"] = "v2" if version == 1 else "v1"
                    with self.assertRaises(RuntimeError):
                        await fixture.verify()


class TimerEvidenceTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.execution = types.SimpleNamespace(workflow_id="timer-rust", run_id="run-1", status="completed")
        self.handle = types.SimpleNamespace(result=AsyncMock(return_value={
            "workflow_runtime": "rust", "request": "timer-rust", "timer_seconds": 30,
        }))
        self.history = {"events": [
            event("WorkflowStarted"),
            event("TimerScheduled", timer_id="timer-1", fire_at="2026-10-05T00:00:30Z"),
            event("TimerFired", timer_id="timer-1", fired_at="2026-10-05T00:00:30Z"),
            event("WorkflowCompleted"),
        ]}
        self.original = {"workflow_id": "timer-rust", "run_id": "run-1",
                         "timer": copy.deepcopy(self.history["events"][1]["payload"])}

    async def verify(self, history, scenario="completion"):
        with patch.object(timers, "observe", AsyncMock(return_value=(self.handle, self.execution, history))), \
                patch.object(timers, "original_pending", return_value=self.original), \
                patch.object(timers, "emit"):
            await timers.verify(None, "rust", scenario)

    async def test_matching_timer_and_one_result_pass(self):
        await self.verify(self.history)

    async def test_pending_waits_for_status_after_timer_history_arrives(self):
        client = types.SimpleNamespace(start_workflow=AsyncMock())
        history = {"events": [event(
            "TimerScheduled", timer_id="timer-1", delay_seconds=30,
            fire_at=(datetime.now(timezone.utc) + timedelta(seconds=30)).isoformat(),
        )]}
        snapshots = [types.SimpleNamespace(workflow_id="timer-completion-rust", run_id="run-1", status=status)
                     for status in ("running", "waiting")]
        observer = AsyncMock(side_effect=[(self.handle, execution, history) for execution in snapshots])
        with patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_ID": "timer"}), \
                patch.object(timers, "observe", observer), patch.object(timers, "emit"), \
                patch.object(timers.asyncio, "sleep", AsyncMock()):
            await timers.pending(client, "rust", "completion")
        self.assertEqual(observer.await_count, 2)

    async def test_scheduling_timeout_identifies_the_original_run_and_observed_state(self):
        client = types.SimpleNamespace(start_workflow=AsyncMock())
        self.execution.status = "running"
        history = {"events": [event("WorkflowStarted", sequence=1)]}
        observer = AsyncMock(return_value=(self.handle, self.execution, history))
        loop = types.SimpleNamespace(time=unittest.mock.Mock(side_effect=[0, 20]))
        with patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_ID": "timer"}), \
                patch.object(timers, "observe", observer), \
                patch.object(timers.asyncio, "get_running_loop", return_value=loop), \
                self.assertRaises(TimeoutError) as failure:
            await timers.pending(client, "rust", "application-upgrade")
        for field in ("application-upgrade", "timer-rust", "run-1", "running", "WorkflowStarted"):
            self.assertIn(field, str(failure.exception))
        self.assertEqual(observer.await_count, 1)

    async def test_duplicate_or_missing_durable_events_cannot_pass(self):
        for kind in ("TimerScheduled", "TimerFired", "WorkflowCompleted"):
            for duplicate in (False, True):
                with self.subTest(kind=kind, duplicate=duplicate):
                    history = copy.deepcopy(self.history)
                    selected = next(item for item in history["events"] if item["event_type"] == kind)
                    if duplicate:
                        history["events"].append(selected)
                    else:
                        history["events"].remove(selected)
                    with self.assertRaises(RuntimeError):
                        await self.verify(history)

    async def test_early_fire_cannot_pass(self):
        self.history["events"][2]["payload"]["fired_at"] = "2026-10-05T00:00:29Z"
        with self.assertRaisesRegex(RuntimeError, "before its original deadline"):
            await self.verify(self.history)

    async def test_wrong_timer_identity_cannot_pass(self):
        self.history["events"][2]["payload"]["timer_id"] = "another-timer"
        with self.assertRaisesRegex(RuntimeError, "identity differs"):
            await self.verify(self.history)

    async def test_rewritten_original_deadline_cannot_pass(self):
        self.history["events"][1]["payload"]["fire_at"] = "2026-10-05T00:00:29Z"
        with self.assertRaisesRegex(RuntimeError, "original scheduled timer changed"):
            await self.verify(self.history)

    async def test_replacement_run_cannot_pass(self):
        self.execution.run_id = "run-2"
        with self.assertRaisesRegex(RuntimeError, "Run identity"):
            await self.verify(self.history)

    async def test_original_timer_metadata_keeps_its_scalar_types(self):
        original_history, original_snapshot = copy.deepcopy(self.history), copy.deepcopy(self.original)
        for field, original, changed in (("sequence", 1, True), ("delay_seconds", 30, 30.0)):
            with self.subTest(field=field):
                self.history, self.original = copy.deepcopy(original_history), copy.deepcopy(original_snapshot)
                self.original["timer"][field] = original
                self.history["events"][1]["payload"][field] = changed
                with self.assertRaisesRegex(RuntimeError, "original scheduled timer changed"):
                    await self.verify(self.history)

    async def test_fire_outside_worker_absence_cannot_pass(self):
        with patch.dict("os.environ", {
            "DURABLE_WORKFLOW_WORKER_STOPPED_AT": "2026-10-05T00:00:10Z",
            "DURABLE_WORKFLOW_WORKER_RESTART_AT": "2026-10-05T00:00:20Z",
        }), self.assertRaisesRegex(RuntimeError, "while the SDK worker was absent"):
            await self.verify(self.history, "worker-restart")

    async def test_cancelled_timer_is_checked_after_due_time(self):
        self.execution.status = "cancelled"
        self.history["events"] = [
            self.history["events"][1],
            event("CooperativeCancellationRequested"),
            event("CooperativeCancellationDelivered"),
            event("TimerCancelled", timer_id="timer-1"),
            event("WorkflowCancelled"),
        ]
        await self.verify(self.history, "cancellation")
        self.history["events"].append(event("TimerFired", timer_id="timer-1"))
        with self.assertRaisesRegex(RuntimeError, "Cancelled timer fired"):
            await self.verify(self.history, "cancellation")

    async def test_server_restart_must_cross_original_deadline(self):
        self.history["events"][2]["payload"]["fired_at"] = "2026-10-05T00:00:33Z"
        with patch.dict("os.environ", {
            "DURABLE_WORKFLOW_SERVER_STOPPED_AT": "2026-10-05T00:00:10Z",
            "DURABLE_WORKFLOW_SERVER_RESTART_AT": "2026-10-05T00:00:32Z",
        }):
            await self.verify(self.history, "server-restart")
            with patch.dict("os.environ", {
                "DURABLE_WORKFLOW_SERVER_STOPPED_AT": "2026-10-05T00:00:31Z",
            }), self.assertRaisesRegex(RuntimeError, "did not cross"):
                await self.verify(self.history, "server-restart")
            self.history["events"][2]["payload"]["fired_at"] = "2026-10-05T00:00:31Z"
            with self.assertRaisesRegex(RuntimeError, "before Server restart"):
                await self.verify(self.history, "server-restart")


class ConcurrentTimerEvidenceTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.execution = types.SimpleNamespace(workflow_id="timers-python", run_id="run-1", status="completed")
        self.handle = types.SimpleNamespace(result=AsyncMock(return_value={
            "workflow_runtime": "python", "request": "timers-python", "timer_seconds": [60, 30, 45],
        }))
        started = datetime(2026, 10, 5, tzinfo=timezone.utc)
        scheduled = [event("TimerScheduled", timer_id=f"timer-{delay}", delay_seconds=delay,
                           fire_at=(started + timedelta(seconds=delay)).isoformat()) for delay in (60, 30, 45)]
        for index, item in enumerate(scheduled):
            item["payload"].update(sequence=1 + index, parallel_group_id="parallel-timers:1:3",
                                   parallel_group_kind="timer", parallel_group_base_sequence=1,
                                   parallel_group_size=3, parallel_group_index=index)
        fired = [event("TimerFired", **item["payload"],
                       fired_at=item["payload"]["fire_at"])
                 for item in sorted(scheduled, key=lambda item: item["payload"]["fire_at"])]
        self.history = {"events": [event("WorkflowStarted"), *scheduled, *fired, event("WorkflowCompleted")]}
        self.original = {"workflow_id": "timers-python", "run_id": "run-1",
                         "timers": [copy.deepcopy(item["payload"]) for item in scheduled]}

    async def verify(self, scenario="concurrent"):
        with patch.object(timers, "observe", AsyncMock(return_value=(self.handle, self.execution, self.history))), \
                patch.object(timers, "original_pending", return_value=self.original), \
                patch.object(timers, "emit"):
            await timers.verify(None, "python", scenario)

    def cancel_group(self):
        self.execution.status = "cancelled"
        scheduled = copy.deepcopy(timers.events(self.history, "TimerScheduled"))
        self.history = {"events": [event("WorkflowStarted"), *scheduled,
            event("CooperativeCancellationRequested"), event("CooperativeCancellationDelivered"),
            *(event("TimerCancelled", **copy.deepcopy(item["payload"]), cancelled_at="2026-10-05T00:00:10Z")
              for item in scheduled), event("WorkflowCancelled")]}

    def add_group_paths(self):
        scheduled = timers.events(self.history, "TimerScheduled")
        for item in scheduled:
            item["payload"]["parallel_group_path"] = [{
                key: value for key, value in item["payload"].items() if key.startswith("parallel_group_")
            }]
        members = {item["payload"]["timer_id"]: item["payload"] for item in scheduled}
        for item in timers.events(self.history, "TimerFired"):
            item["payload"]["parallel_group_path"] = copy.deepcopy(members[item["payload"]["timer_id"]]["parallel_group_path"])
        self.original["timers"] = [copy.deepcopy(item["payload"]) for item in scheduled]

    async def test_one_original_with_three_concurrent_timers_passes(self):
        await self.verify()

    async def test_worker_recovery_preserves_every_fire_during_the_absence(self):
        with patch.dict("os.environ", {
            "DURABLE_WORKFLOW_WORKER_STOPPED_AT": "2026-10-05T00:00:10Z",
            "DURABLE_WORKFLOW_WORKER_RESTART_AT": "2026-10-05T00:01:01Z",
        }):
            await self.verify("concurrent-worker-restart")
            self.history["events"][5]["payload"]["fired_at"] = "2026-10-05T00:01:02Z"
            with self.assertRaisesRegex(RuntimeError, "while the SDK worker was absent"):
                await self.verify("concurrent-worker-restart")

    async def test_server_outage_must_cross_every_original_deadline(self):
        for item in timers.events(self.history, "TimerFired"):
            item["payload"]["fired_at"] = "2026-10-05T00:01:06Z"
        with patch.dict("os.environ", {
            "DURABLE_WORKFLOW_SERVER_STOPPED_AT": "2026-10-05T00:00:10Z",
            "DURABLE_WORKFLOW_SERVER_RESTART_AT": "2026-10-05T00:01:05Z",
        }):
            await self.verify("concurrent-server-restart")
            for field, value in (("DURABLE_WORKFLOW_SERVER_STOPPED_AT", "2026-10-05T00:00:35Z"),
                                 ("DURABLE_WORKFLOW_SERVER_RESTART_AT", "2026-10-05T00:00:50Z")):
                with patch.dict("os.environ", {field: value}), self.assertRaisesRegex(RuntimeError, "did not cross"):
                    await self.verify("concurrent-server-restart")
            self.history["events"][5]["payload"]["fired_at"] = "2026-10-05T00:01:04Z"
            with self.assertRaisesRegex(RuntimeError, "before Server restart"):
                await self.verify("concurrent-server-restart")

    async def test_absent_worker_observation_waits_for_every_member(self):
        self.execution.status = "waiting"
        self.history["events"].pop()
        partial = copy.deepcopy(self.history)
        partial["events"].pop()
        observer = AsyncMock(side_effect=[(self.handle, self.execution, history)
                                         for history in (partial, self.history)])
        with patch.object(timers, "observe", observer), \
                patch.object(timers, "original_pending", return_value=self.original), \
                patch.object(timers, "emit"), patch.object(timers.asyncio, "sleep", AsyncMock()):
            await timers.fired(None, "python", "concurrent-worker-restart")
        self.assertEqual(observer.await_count, 2)

    async def test_absent_worker_observation_refuses_duplicate_or_foreign_fires(self):
        self.execution.status = "waiting"
        self.history["events"].pop()
        for timer_id in ("timer-60", "foreign-timer"):
            with self.subTest(timer_id=timer_id):
                self.history["events"][5]["payload"]["timer_id"] = timer_id
                with patch.object(timers, "observe", AsyncMock(return_value=(self.handle, self.execution, self.history))), \
                        patch.object(timers, "original_pending", return_value=self.original), \
                        patch.object(timers, "emit"), self.assertRaisesRegex(RuntimeError, "identities"):
                    await timers.fired(None, "python", "concurrent-worker-restart")

    async def test_concurrent_cancellation_closes_every_member_without_a_result(self):
        self.cancel_group()
        await self.verify("concurrent-cancellation")
        self.handle.result.assert_not_awaited()

    async def test_concurrent_cancellation_waits_beyond_the_last_original_deadline(self):
        self.cancel_group()
        instants = [datetime(2026, 10, 5, 0, 0, 45, tzinfo=timezone.utc),
                    datetime(2026, 10, 5, 0, 1, 1, tzinfo=timezone.utc)]

        class Clock(datetime):
            @classmethod
            def now(cls, tz=None):
                return instants.pop(0)

        with patch.object(timers, "datetime", Clock), patch.object(timers.asyncio, "sleep", AsyncMock()) as sleep:
            await self.verify("concurrent-cancellation")
        self.assertEqual(sleep.await_count, 1)

    async def test_concurrent_cancellation_refuses_missing_duplicate_or_foreign_members(self):
        self.cancel_group()
        original = copy.deepcopy(self.history)
        for change in ("missing", "duplicate", "foreign"):
            with self.subTest(change=change):
                self.history = copy.deepcopy(original)
                selected = timers.events(self.history, "TimerCancelled")
                if change == "missing":
                    self.history["events"].remove(selected[-1])
                else:
                    selected[-1]["payload"]["timer_id"] = "foreign" if change == "foreign" else selected[0]["payload"]["timer_id"]
                with self.assertRaisesRegex(RuntimeError, "cancelled timer identities"):
                    await self.verify("concurrent-cancellation")

    async def test_concurrent_cancellation_refuses_changed_original_metadata(self):
        self.cancel_group()
        original = copy.deepcopy(self.history)
        for field, value in (("sequence", 99), ("fire_at", "2026-10-05T00:01:01Z"),
                             ("parallel_group_index", 99), ("parallel_group_mode", "select"),
                             ("parallel_group_path", [{"parallel_group_id": "foreign"}])):
            with self.subTest(field=field):
                self.history = copy.deepcopy(original)
                timers.events(self.history, "TimerCancelled")[0]["payload"][field] = value
                with self.assertRaisesRegex(RuntimeError, "metadata"):
                    await self.verify("concurrent-cancellation")

    async def test_concurrent_cancellation_refuses_fires_results_and_repeated_terminal_events(self):
        self.cancel_group()
        original = copy.deepcopy(self.history)
        for kind in ("TimerFired", "WorkflowCompleted", "WorkflowCancelled", "CooperativeCancellationDelivered"):
            with self.subTest(kind=kind):
                self.history = copy.deepcopy(original)
                self.history["events"].append(event(kind, timer_id="timer-60"))
                with self.assertRaises(RuntimeError):
                    await self.verify("concurrent-cancellation")

    async def test_concurrent_cancellation_cannot_replace_the_original_run(self):
        self.cancel_group()
        self.execution.run_id = "replacement-run"
        with self.assertRaisesRegex(RuntimeError, "Run identity"):
            await self.verify("concurrent-cancellation")

    async def test_resolved_group_ordinals_keep_their_original_numeric_types(self):
        original = copy.deepcopy(self.history)
        for scenario, kind in (("concurrent", "TimerFired"), ("concurrent-cancellation", "TimerCancelled")):
            self.history = copy.deepcopy(original)
            self.execution.status = "completed"
            if kind == "TimerCancelled":
                self.cancel_group()
            baseline = copy.deepcopy(self.history)
            first = timers.events(self.history, kind)[0]["payload"]
            for field, value in (("parallel_group_index", bool(first["parallel_group_index"])),
                                 ("parallel_group_size", 3.0)):
                with self.subTest(scenario=scenario, field=field):
                    self.history = copy.deepcopy(baseline)
                    timers.events(self.history, kind)[0]["payload"][field] = value
                    with self.assertRaisesRegex(RuntimeError, "metadata"):
                        await self.verify(scenario)

    async def test_resolved_group_paths_keep_nested_ordinal_types(self):
        self.add_group_paths()
        original = copy.deepcopy(self.history)
        for scenario, kind in (("concurrent", "TimerFired"), ("concurrent-cancellation", "TimerCancelled")):
            with self.subTest(scenario=scenario):
                self.history = copy.deepcopy(original)
                self.execution.status = "completed"
                if kind == "TimerCancelled":
                    self.cancel_group()
                path = timers.events(self.history, kind)[0]["payload"]["parallel_group_path"][0]
                path["parallel_group_index"] = bool(path["parallel_group_index"])
                with self.assertRaisesRegex(RuntimeError, "metadata"):
                    await self.verify(scenario)

    async def test_opening_group_paths_cannot_rewrite_the_original_ordinal_type(self):
        self.add_group_paths()
        for item in self.history["events"]:
            if item["event_type"] in ("TimerScheduled", "TimerFired"):
                path = item["payload"]["parallel_group_path"][0]
                if path["parallel_group_index"] == 1:
                    path["parallel_group_index"] = True
        with self.assertRaisesRegex(RuntimeError, "original scheduled timers changed"):
            await self.verify()

    async def test_each_concurrent_recovery_start_uses_the_parallel_workflow(self):
        self.execution.status = "waiting"
        scheduled = copy.deepcopy(timers.events(self.history, "TimerScheduled"))
        now = datetime.now(timezone.utc)
        for item in scheduled:
            item["payload"]["fire_at"] = (now + timedelta(seconds=item["payload"]["delay_seconds"])).isoformat()
        history = {"events": scheduled}
        for scenario in ("concurrent-worker-restart", "concurrent-server-restart", "concurrent-cancellation"):
            with self.subTest(scenario=scenario):
                client = types.SimpleNamespace(start_workflow=AsyncMock())
                cancellation = {"request_id": "request-1", "cleanup_deadline_at": "2026-10-05T00:00:20Z"}
                self.handle.request_cancellation = AsyncMock(side_effect=[
                    {"duplicate": False, "cancellation_request": cancellation},
                    {"duplicate": True, "cancellation_request": cancellation}])
                with patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_ID": "fixture"}), \
                        patch.object(timers, "observe", AsyncMock(return_value=(self.handle, self.execution, history))), \
                        patch.object(timers, "emit"):
                    await timers.pending(client, "python", scenario)
                self.assertEqual(client.start_workflow.await_args.kwargs["workflow_type"], "polyglot.python.concurrent-timers")

    async def test_serial_timers_cannot_pass(self):
        first_fire = self.history["events"].pop(6)
        self.history["events"].insert(2, first_fire)
        with self.assertRaisesRegex(RuntimeError, "before the first fire"):
            await self.verify()

    async def test_duplicate_or_missing_members_cannot_pass(self):
        for kind in ("TimerScheduled", "TimerFired", "WorkflowCompleted"):
            original = copy.deepcopy(self.history)
            for duplicate in (False, True):
                with self.subTest(kind=kind, duplicate=duplicate):
                    self.history = copy.deepcopy(original)
                    selected = next(item for item in self.history["events"] if item["event_type"] == kind)
                    if duplicate:
                        self.history["events"].append(selected)
                    else:
                        self.history["events"].remove(selected)
                    with self.assertRaises(RuntimeError):
                        await self.verify()
            self.history = original

    async def test_foreign_or_repeated_timer_identity_cannot_pass(self):
        for timer_id in ("foreign-timer", "timer-60"):
            with self.subTest(timer_id=timer_id):
                self.history["events"][5]["payload"]["timer_id"] = timer_id
                with self.assertRaisesRegex(RuntimeError, "identities"):
                    await self.verify()

    async def test_rewritten_original_deadline_cannot_pass(self):
        self.history["events"][2]["payload"]["fire_at"] = "2026-10-05T00:00:29Z"
        with self.assertRaisesRegex(RuntimeError, "original scheduled timers changed"):
            await self.verify()

    async def test_early_member_cannot_pass(self):
        self.history["events"][5]["payload"]["fired_at"] = "2026-10-05T00:00:29Z"
        with self.assertRaisesRegex(RuntimeError, "before its original deadline"):
            await self.verify()

    async def test_completion_before_last_member_cannot_pass(self):
        self.history["events"][-1], self.history["events"][-2] = self.history["events"][-2], self.history["events"][-1]
        with self.assertRaisesRegex(RuntimeError, "before every timer fired"):
            await self.verify()

    async def test_independent_or_selection_groups_cannot_pass_as_one_barrier(self):
        original = copy.deepcopy(self.history)
        for field, value in (("parallel_group_id", "another-group"), ("parallel_group_size", 1),
                             ("parallel_group_index", 0), ("parallel_group_mode", "select")):
            with self.subTest(field=field):
                self.history = copy.deepcopy(original)
                self.history["events"][2]["payload"][field] = value
                self.original["timers"] = [copy.deepcopy(item["payload"])
                                          for item in timers.events(self.history, "TimerScheduled")]
                with self.assertRaisesRegex(RuntimeError, "parallel barrier"):
                    await self.verify()

    async def test_fired_member_cannot_change_its_original_group_or_sequence(self):
        original = copy.deepcopy(self.history)
        for field, value in (("parallel_group_id", "another-group"), ("parallel_group_index", 99),
                             ("sequence", 99), ("fire_at", "2026-10-05T00:00:01Z"),
                             ("parallel_group_mode", "select"), ("parallel_group_path", [{"parallel_group_id": "foreign"}])):
            with self.subTest(field=field):
                self.history = copy.deepcopy(original)
                self.history["events"][5]["payload"][field] = value
                with self.assertRaisesRegex(RuntimeError, "Fired timer metadata"):
                    await self.verify()

    async def test_coalesced_original_deadlines_cannot_pass(self):
        original = copy.deepcopy(self.history)
        same_instant = (
            ["2026-10-05T00:01:00+00:00"] * 3,
            ["2026-10-05T00:01:00+00:00", "2026-10-05T01:01:00+01:00", "2026-10-04T23:01:00-01:00"],
        )
        for deadlines in same_instant:
            with self.subTest(deadlines=deadlines):
                self.history = copy.deepcopy(original)
                scheduled = timers.events(self.history, "TimerScheduled")
                for item, deadline in zip(scheduled, deadlines, strict=True):
                    item["payload"]["fire_at"] = deadline
                members = {item["payload"]["timer_id"]: item["payload"] for item in scheduled}
                for item in timers.events(self.history, "TimerFired"):
                    item["payload"]["fire_at"] = members[item["payload"]["timer_id"]]["fire_at"]
                    item["payload"]["fired_at"] = "2026-10-05T00:01:00+00:00"
                self.original["timers"] = [copy.deepcopy(item["payload"]) for item in scheduled]
                with self.assertRaisesRegex(RuntimeError, "distinct original deadlines"):
                    await self.verify()


if __name__ == "__main__":
    unittest.main()
