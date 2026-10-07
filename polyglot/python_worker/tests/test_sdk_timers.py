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


if __name__ == "__main__":
    unittest.main()
