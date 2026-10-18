from __future__ import annotations

import copy
import importlib.util
import sys
import types
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import AsyncMock, patch


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
        for field, original, changed in (("sequence", 1, True), ("delay_seconds", 30, 30.0)):
            with self.subTest(field=field):
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
