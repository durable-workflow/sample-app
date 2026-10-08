import copy
import unittest

from durable_workflow import serializer
from sdk_namespaces import parked_identity, verify_history


def envelope(value):
    return {"codec": "avro", "blob": serializer.encode(value, codec="avro")}


class NamespaceHistoryChecks(unittest.TestCase):
    def fixture(self):
        namespace, workflow_id = "rust-namespace-a", "same-workflow-id"
        request = {"namespace": namespace, "workflow_id": workflow_id}
        worker_id = f"rust-namespace-{namespace}"
        activity = {"namespace": namespace, "worker_id": worker_id, "request": request}
        result = {"namespace": namespace, "worker_id": worker_id, "request": request,
                  "activity": activity, "signal": request}
        events = [
            ("WorkflowStarted", {"workflow_run_id": "original-run"}),
            ("ActivityScheduled", {"activity_execution_id": "original-activity"}),
            ("ActivityStarted", {"activity_execution_id": "original-activity", "activity_attempt_id": "original-attempt"}),
            ("ActivityCompleted", {"activity_execution_id": "original-activity", "activity_attempt_id": "original-attempt",
                                   "result": envelope(activity)}),
            ("SignalWaitOpened", {"signal_wait_id": "original-wait", "sequence": 1, "signal_name": "namespace-finish"}),
            ("SignalReceived", {"signal_id": "acknowledged-signal", "signal_name": "namespace-finish"}),
            ("SignalApplied", {"signal_id": "acknowledged-signal", "signal_wait_id": "original-wait", "sequence": 1}),
            ("WorkflowCompleted", {"output": envelope(result)}),
        ]
        history = {"events": [{"event_type": kind, "payload": payload} for kind, payload in events]}
        record = {"namespace": namespace, "workflow_id": workflow_id, **parked_identity(history)}
        return history, record, result

    def test_original_run_and_namespace_result_pass(self):
        history, record, result = self.fixture()
        self.assertEqual(verify_history(history, record), result)

    def test_replaced_run_activity_attempt_or_wait_fails(self):
        history, record, _ = self.fixture()
        for kind, key in (("WorkflowStarted", "workflow_run_id"), ("ActivityCompleted", "activity_execution_id"),
                          ("ActivityCompleted", "activity_attempt_id"), ("SignalWaitOpened", "signal_wait_id"),
                          ("SignalApplied", "signal_wait_id"), ("SignalApplied", "sequence"),
                          ("SignalApplied", "signal_id")):
            with self.subTest(kind=kind, key=key):
                changed = copy.deepcopy(history)
                next(row for row in changed["events"] if row["event_type"] == kind)["payload"][key] = "replacement"
                with self.assertRaises(RuntimeError):
                    verify_history(changed, record)

    def test_missing_or_duplicate_durable_boundaries_fail(self):
        history, record, _ = self.fixture()
        for index in range(len(history["events"])):
            with self.subTest(index=index, change="missing"):
                changed = copy.deepcopy(history)
                del changed["events"][index]
                with self.assertRaises(RuntimeError):
                    verify_history(changed, record)
            with self.subTest(index=index, change="duplicate"):
                changed = copy.deepcopy(history)
                changed["events"].append(copy.deepcopy(changed["events"][index]))
                with self.assertRaises(RuntimeError):
                    verify_history(changed, record)

    def test_foreign_namespace_or_worker_result_fails(self):
        history, record, result = self.fixture()
        for key in ("namespace", "worker_id", "request", "activity", "signal"):
            changed = copy.deepcopy(history)
            wrong = copy.deepcopy(result)
            wrong[key] = "foreign-namespace"
            changed["events"][-1]["payload"]["output"] = envelope(wrong)
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                verify_history(changed, record)

    def test_completion_before_signal_application_fails(self):
        history, record, _ = self.fixture()
        history["events"][-1], history["events"][-2] = history["events"][-2], history["events"][-1]
        with self.assertRaises(RuntimeError):
            verify_history(history, record)


if __name__ == "__main__":
    unittest.main()
