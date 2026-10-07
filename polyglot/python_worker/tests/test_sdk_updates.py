"""Reject incomplete or contradictory durable update observations."""

import copy
import importlib.util
import pathlib
import sys
import types
import unittest
from unittest.mock import patch

# Isolate history/identity checks from codec execution. The real published
# experiment uses the installed SDK's official Avro serializer.
serializer = types.SimpleNamespace(envelope=lambda value: {"decoded": value},
                                   decode_envelope=lambda value, **_options: value["decoded"])

SCRIPT = pathlib.Path(__file__).parents[1] / "scripts" / "sdk_updates.py"
spec = importlib.util.spec_from_file_location("sdk_updates", SCRIPT)
updates = importlib.util.module_from_spec(spec)
with patch.dict(sys.modules, {"durable_workflow": types.SimpleNamespace(Client=object, serializer=serializer)}):
    spec.loader.exec_module(updates)


class DurableUpdateObservationTest(unittest.TestCase):
    def setUp(self):
        self.request_id = "example-python-rust"
        self.result = {"handler_runtime": "rust", "request": updates.request("python", self.request_id)}
        self.history = {"events": [
            {"event_type": "UpdateAccepted", "payload": {"update_id": "original",
                "arguments": serializer.envelope([self.result["request"]])}},
            {"event_type": "UpdateCompleted", "payload": {"update_id": "original",
                "result": serializer.envelope(self.result)}}]}

    def verify(self, history):
        return updates.verify_completed(history, self.request_id, "rust", "python")

    def test_completed_result_retains_update_identity(self):
        self.assertEqual(self.verify(self.history)[0], "original")

    def test_missing_acceptance_is_rejected(self):
        self.history["events"].pop(0)
        with self.assertRaises(RuntimeError):
            self.verify(self.history)

    def test_duplicate_acceptance_is_rejected(self):
        self.history["events"].append(copy.deepcopy(self.history["events"][0]))
        with self.assertRaises(RuntimeError):
            self.verify(self.history)

    def test_missing_completion_is_rejected(self):
        self.history["events"].pop()
        with self.assertRaises(RuntimeError):
            self.verify(self.history)

    def test_duplicate_completion_is_rejected(self):
        self.history["events"].append(copy.deepcopy(self.history["events"][-1]))
        with self.assertRaises(RuntimeError):
            self.verify(self.history)

    def test_completion_for_different_update_is_rejected(self):
        self.history["events"][-1]["payload"]["update_id"] = "replacement"
        with self.assertRaises(RuntimeError):
            self.verify(self.history)

    def test_completion_before_acceptance_is_rejected(self):
        self.history["events"].reverse()
        with self.assertRaises(RuntimeError):
            self.verify(self.history)

    def test_failed_completion_is_rejected(self):
        self.history["events"][-1]["payload"]["failure_id"] = "failed"
        with self.assertRaises(RuntimeError):
            self.verify(self.history)

    def test_wrong_handler_or_payload_is_rejected(self):
        for replacement in ("php", {"request": "changed"}, {"count": "42"}):
            with self.subTest(replacement=replacement):
                history = copy.deepcopy(self.history)
                result = copy.deepcopy(self.result)
                if isinstance(replacement, str):
                    result["handler_runtime"] = replacement
                elif "count" in replacement:
                    result["request"]["nested"].update(replacement)
                else:
                    result.update(replacement)
                history["events"][-1]["payload"]["result"] = serializer.envelope(result)
                with self.assertRaises(RuntimeError):
                    self.verify(history)


if __name__ == "__main__":
    unittest.main()
