"""Reject contradictory attempt histories rather than trusting a pass label."""

import copy
import unittest

from sdk_activity_recovery import verify_attempts


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
