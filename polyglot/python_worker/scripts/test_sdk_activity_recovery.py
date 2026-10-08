"""Reject contradictory attempt histories rather than trusting a pass label."""

import copy
import unittest

from sdk_activity_recovery import verify_attempts, verify_terminal_failure


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
