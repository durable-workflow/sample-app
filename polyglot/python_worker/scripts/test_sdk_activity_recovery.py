"""Reject contradictory attempt histories rather than trusting a pass label."""

import copy
import unittest

from sdk_activity_recovery import verify_attempts, verify_progress, verify_results, verify_terminal_failure, verify_unchanged_history


class RecoveryCoverageTest(unittest.TestCase):
    def setUp(self):
        self.results = [
            {"workflow_runtime": workflow, "activity_runtime": activity, "scenario": scenario,
             "first_claim": {"lease_owner": f"{activity}-worker"},
             "second_claim": {"lease_owner": f"{activity}-worker"}}
            for workflow in ("php", "python", "rust")
            for activity in ("php", "python", "rust")
            for scenario in ("retry", "worker-loss", "total-deadline", "retry-exhaustion", "progress-heartbeat")
        ]

    def test_complete_recovery_matrix(self):
        verify_results(self.results)

    def test_rust_only_missing_and_duplicate_cases_are_rejected(self):
        incomplete = [row for row in self.results
                      if "rust" in (row["workflow_runtime"], row["activity_runtime"])]
        previous = [row for row in self.results if row["scenario"] != "progress-heartbeat"]
        for results in (incomplete, previous, self.results[:-1], self.results[:-1] + [self.results[0]]):
            with self.subTest(case_count=len(results)):
                with self.assertRaisesRegex(RuntimeError, "Missing or duplicate"):
                    verify_results(results)

    def test_replacing_worker_after_deadline_cannot_hide_a_continuity_failure(self):
        for row in self.results:
            if row["scenario"] == "retry-exhaustion":
                row["first_claim"]["lease_owner"] = "replacement-worker"
                break
        with self.assertRaisesRegex(RuntimeError, "did not keep its identity"):
            verify_results(self.results)


class ApplicationProgressTest(unittest.TestCase):
    def setUp(self):
        self.record = {"scenario": "progress-heartbeat", "workflow_id": "original-run", "activity_runtime": "python"}
        self.first = {"claim": {"activity_attempt_id": "first"},
                      "status": {"deadlines": {"start_to_close": "2026-10-08T00:01:00Z",
                                               "schedule_to_close": "2026-10-08T00:02:00Z"}}}
        self.history = {"events": [
            {"event_type": "ActivityScheduled", "payload": {"activity_execution_id": "original"}},
            {"event_type": "ActivityStarted", "payload": {}},
        ]}
        for step, seconds in enumerate((0, 3, 6, 9, 12), start=1):
            self.history["events"].append({"event_type": "ActivityHeartbeatRecorded", "payload": {
                "activity_execution_id": "original", "activity_attempt_id": "first", "attempt_number": 1,
                "heartbeat_at": f"2026-10-08T00:00:{seconds:02d}Z",
                "progress": {"details": {"case_id": "original-run", "runtime": "python", "attempt": 1,
                    "step": step, "fraction": 0.5, "ready": True, "optional": None, "note": "café ✓"}},
            }})
        self.status = {"can_continue": True, "heartbeat_recorded": False, "last_heartbeat_at": "2026-10-08T00:00:12Z",
                       "deadlines": {**self.first["status"]["deadlines"], "heartbeat": "2026-10-08T00:00:22Z"}}

    def test_actual_progress_preserves_typed_details_and_fixed_deadlines(self):
        self.assertEqual(len(verify_progress(self.history, self.record, self.first, self.status)), 5)

    def test_wrong_attempt_progress_and_short_liveness_are_rejected(self):
        for mutation in ("wrong-attempt", "changed-progress", "short-span", "missing-step"):
            with self.subTest(mutation=mutation):
                history = copy.deepcopy(self.history)
                if mutation == "wrong-attempt":
                    history["events"][-1]["payload"]["activity_attempt_id"] = "other"
                elif mutation == "changed-progress":
                    history["events"][-1]["payload"]["progress"]["details"]["step"] = 1
                elif mutation == "short-span":
                    history["events"][-1]["payload"]["heartbeat_at"] = "2026-10-08T00:00:10Z"
                else:
                    history["events"].pop()
                with self.assertRaises(RuntimeError):
                    verify_progress(history, self.record, self.first, self.status)

    def test_read_only_status_and_immutable_total_budget_are_required(self):
        for mutation in ("withdrawn", "recorded", "stale-heartbeat", "reset-total"):
            status = copy.deepcopy(self.status)
            if mutation == "withdrawn":
                status["can_continue"] = False
            elif mutation == "recorded":
                status["heartbeat_recorded"] = True
            elif mutation == "stale-heartbeat":
                status["deadlines"]["heartbeat"] = "2026-10-08T00:00:10Z"
            else:
                status["deadlines"]["schedule_to_close"] = "2026-10-08T00:03:00Z"
            with self.assertRaises(RuntimeError):
                verify_progress(self.history, self.record, self.first, status)

    def test_only_the_live_retry_can_append_progress_during_a_stale_write(self):
        second = {"claim": {"activity_attempt_id": "second"}}
        history = copy.deepcopy(self.history)
        history["events"].append({"event_type": "ActivityHeartbeatRecorded", "payload": {"activity_attempt_id": "second"}})
        verify_unchanged_history(history, self.history, self.record, second)
        for event in ({"event_type": "ActivityHeartbeatRecorded", "payload": {"activity_attempt_id": "first"}},
                      {"event_type": "ActivityCompleted", "payload": {"activity_attempt_id": "first"}}):
            history["events"][-1] = event
            with self.assertRaises(RuntimeError):
                verify_unchanged_history(history, self.history, self.record, second)


class AttemptHistoryTest(unittest.TestCase):
    def setUp(self):
        self.record = {"activity_runtime": "rust", "scenario": "retry"}
        self.first = {"activity_attempt_id": "attempt-one"}
        self.second = {"activity_attempt_id": "attempt-two"}
        def event(kind, **payload):
            return {"event_type": kind, "timestamp": "2026-10-08T00:00:02Z", "payload": payload}
        self.history = {"events": [
            event("ActivityScheduled", activity_execution_id="original", activity_type="sample-app.activity-recovery.rust.work"),
            event("ActivityStarted", activity_execution_id="original", activity_attempt_id="attempt-one", attempt_number=1),
            event("ActivityRetryScheduled", activity_execution_id="original", retry_after_attempt_id="attempt-one",
                  retry_after_attempt=1, retry_backoff_seconds=2, retry_available_at="2026-10-08T00:00:02Z"),
            event("ActivityStarted", activity_execution_id="original", activity_attempt_id="attempt-two", attempt_number=2),
        ]}

    def test_actual_two_attempt_history(self):
        self.assertEqual(verify_attempts(self.history, self.record, self.first, self.second), "original")

    def test_changed_execution_reused_attempt_and_early_retry_are_rejected(self):
        changes = [(3, "activity_execution_id", "other"), (3, "activity_attempt_id", "attempt-one"),
                   (3, "attempt_number", 1), (2, "retry_after_attempt_id", "other"),
                   (2, "retry_backoff_seconds", 0), (2, "retry_available_at", "2026-10-08T00:00:03Z")]
        for index, key, value in changes:
            with self.subTest(key=key):
                history = copy.deepcopy(self.history)
                history["events"][index]["payload"][key] = value
                with self.assertRaises(RuntimeError):
                    verify_attempts(history, self.record, self.first, self.second)

    def test_unobserved_third_attempt_is_rejected(self):
        self.history["events"].append(copy.deepcopy(self.history["events"][-1]))
        with self.assertRaises(RuntimeError):
            verify_attempts(self.history, self.record, self.first, self.second)

    def test_worker_loss_requires_timeout_retry(self):
        self.record["scenario"] = "worker-loss"
        with self.assertRaises(RuntimeError):
            verify_attempts(self.history, self.record, self.first, self.second)
        self.history["events"][2]["payload"]["timeout_kind"] = "start_to_close"
        self.assertEqual(verify_attempts(self.history, self.record, self.first, self.second), "original")


class TerminalFailureTest(unittest.TestCase):
    def setUp(self):
        AttemptHistoryTest.setUp(self)
        self.record.update(scenario="retry-exhaustion", run_id="original-run")
        self.history["events"].insert(0, {"event_type": "WorkflowStarted",
            "timestamp": "2026-10-08T00:00:00Z", "payload": {"workflow_run_id": "original-run"}})
        self.first_snapshot = {"claim": self.first,
                               "status": {"deadlines": {"schedule_to_close": "2026-10-08T00:00:30Z"}}}
        self.second_snapshot = {"claim": self.second, "history": copy.deepcopy(self.history)}
        self.history["events"].extend([
            {"event_type": "ActivityFailed", "timestamp": "2026-10-08T00:00:05Z", "payload": {
                "activity_execution_id": "original", "activity_attempt_id": "attempt-two", "attempt_number": 2,
                "non_retryable": False, "message": "injected second-attempt failure"}},
            {"event_type": "WorkflowFailed", "timestamp": "2026-10-08T00:00:06Z",
             "payload": {"message": "injected second-attempt failure"}},
        ])

    def verify(self, history=None):
        return verify_terminal_failure(history or self.history, self.record, self.first_snapshot, self.second_snapshot)

    def test_retryable_exhaustion_preserves_actual_failure(self):
        self.assertEqual(self.verify(), "original")

    def test_wrong_attempt_run_or_failure_cause_is_rejected(self):
        changes = [(0, "workflow_run_id", "replacement-run"), (5, "activity_attempt_id", "attempt-one"),
                   (5, "attempt_number", 1), (5, "non_retryable", True), (5, "message", "unrelated failure"),
                   (6, "message", "generic lost cause")]
        for index, key, value in changes:
            with self.subTest(key=key):
                history = copy.deepcopy(self.history)
                history["events"][index]["payload"][key] = value
                with self.assertRaises(RuntimeError):
                    self.verify(history)

    def test_successful_result_and_duplicate_terminal_are_rejected(self):
        for kind in ("ActivityCompleted", "WorkflowCompleted", "WorkflowFailed"):
            with self.subTest(kind=kind):
                history = copy.deepcopy(self.history)
                history["events"].append({"event_type": kind, "timestamp": "2026-10-08T00:00:07Z", "payload": {}})
                with self.assertRaises(RuntimeError):
                    self.verify(history)

    def test_deadline_requires_original_boundary_and_total_timeout(self):
        self.record["scenario"] = "total-deadline"
        terminal = self.history["events"][-2]
        terminal.update(event_type="ActivityTimedOut", timestamp="2026-10-08T00:00:30Z")
        terminal["payload"]["timeout_kind"] = "schedule_to_close"
        terminal["payload"]["message"] = "Activity original total deadline expired."
        self.history["events"][-1]["timestamp"] = "2026-10-08T00:00:31Z"
        self.history["events"][-1]["payload"]["message"] = "Activity failed: Activity original total deadline expired."
        self.assertEqual(self.verify(), "original")
        for key, value in (("timestamp", "2026-10-08T00:00:29Z"), ("timeout_kind", "start_to_close")):
            history = copy.deepcopy(self.history)
            if key == "timestamp":
                history["events"][-2][key] = value
            else:
                history["events"][-2]["payload"][key] = value
            with self.assertRaises(RuntimeError):
                self.verify(history)
