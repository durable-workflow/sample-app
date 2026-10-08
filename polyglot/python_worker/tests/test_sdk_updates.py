"""Reject incomplete or contradictory durable update observations."""

import copy
import importlib.util
import json
import os
import pathlib
import sys
import types
import unittest
from unittest.mock import AsyncMock, patch

# Isolate history/identity checks from codec execution. The real published
# experiment uses the installed SDK's official Avro serializer.
serializer = types.SimpleNamespace(envelope=lambda value: {"decoded": value},
                                   decode_envelope=lambda value, **_options: value["decoded"])

SCRIPT = pathlib.Path(__file__).parents[1] / "scripts" / "sdk_updates.py"
spec = importlib.util.spec_from_file_location("sdk_updates", SCRIPT)
updates = importlib.util.module_from_spec(spec)
class UpdateFailed(Exception):
    def __init__(self, message, *, status, body):
        super().__init__(message)
        self.status = status
        self.body = body
        for name in ("workflow_id", "run_id", "update_id", "failure_id"):
            setattr(self, name, body.get(name))


with patch.dict(sys.modules, {"durable_workflow": types.SimpleNamespace(
        Client=object, UpdateFailed=UpdateFailed, serializer=serializer)}):
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
        for replacement in ("php", {"request": "changed"}, {"count": "42"}, {"count": 42.0}, {"enabled": 1}):
            with self.subTest(replacement=replacement):
                history = copy.deepcopy(self.history)
                result = copy.deepcopy(self.result)
                if isinstance(replacement, str):
                    result["handler_runtime"] = replacement
                elif "count" in replacement or "enabled" in replacement:
                    result["request"]["nested"].update(replacement)
                else:
                    result.update(replacement)
                history["events"][-1]["payload"]["result"] = serializer.envelope(result)
                with self.assertRaises(RuntimeError):
                    self.verify(history)


class ClientResultTest(unittest.IsolatedAsyncioTestCase):
    async def test_client_decodes_the_envelope_field(self):
        result = {"handler_runtime": "rust", "request": updates.request("python", "request")}
        client = types.SimpleNamespace(update_workflow=AsyncMock(return_value={
            "update_status": "completed", "result": result,
            "result_envelope": serializer.envelope(result),
        }))
        with patch.object(updates, "emit") as emit:
            await updates.call(client, "workflow", "request", "echo")
        self.assertEqual(emit.call_args.kwargs["result"], result)

    async def test_sdk_failure_requires_a_matching_durable_handler_failure(self):
        request_id = "example-failure"
        body = {"update_status": "failed", "command_status": "accepted", "workflow_id": "example-rust",
                "run_id": "run", "update_id": "original", "failure_id": "failure"}
        client = types.SimpleNamespace(update_workflow=AsyncMock(
            side_effect=UpdateFailed("update-probe-failure", status=422, body=body)))
        execution = types.SimpleNamespace(status="waiting", workflow_id="example-rust", run_id="run")
        history = {"events": [
            {"event_type": "UpdateAccepted", "payload": {"update_id": "original",
                "arguments": serializer.envelope([updates.request("python", request_id)])}},
            {"event_type": "UpdateCompleted", "payload": {"update_id": "original",
                "failure_id": "failure", "message": "update-probe-failure"}},
        ]}
        with patch.dict(os.environ, {"DURABLE_WORKFLOW_UPDATE_ID": "example"}):
            with patch.object(updates, "observe", AsyncMock(return_value=(execution, history))):
                with patch.object(updates, "emit") as emit:
                    await updates.failure(client)
                    self.assertEqual(emit.call_args.kwargs["update_id"], "original")
                    self.assertEqual(client.update_workflow.await_count, 2)
                    self.assertEqual(emit.call_args.kwargs["sdk_error"],
                                     emit.call_args.kwargs["duplicate_sdk_error"])
                history["events"][-1]["payload"]["message"] = "unrelated validation error"
                with self.assertRaisesRegex(RuntimeError, "durable handler failure"):
                    await updates.failure(client)

    async def test_sdk_failure_rejects_wrong_diagnostics(self):
        body = {"update_status": "failed", "command_status": "accepted", "workflow_id": "example-rust",
                "run_id": "run", "update_id": "original", "failure_id": "failure"}
        execution = types.SimpleNamespace(status="waiting", workflow_id="example-rust", run_id="run")
        history = {"events": [
            {"event_type": "UpdateAccepted", "payload": {"update_id": "original",
                "arguments": serializer.envelope([updates.request("python", "example-failure")])}},
            {"event_type": "UpdateCompleted", "payload": {"update_id": "original",
                "failure_id": "failure", "message": "update-probe-failure"}},
        ]}
        for change in ({"message": ""}, {"status": 409}, {"failure_id": "other"},
                       {"run_id": "replacement"}, {"workflow_id": "other"}, {"update_id": "other"},
                       {"accepted": False}, {"update_status": "rejected"}, {"command_status": "rejected"}):
            with self.subTest(change=change):
                error = UpdateFailed(change.get("message", "update-probe-failure"),
                                     status=change.get("status", 422), body={**body, **change})
                client = types.SimpleNamespace(update_workflow=AsyncMock(side_effect=error))
                with (patch.dict(os.environ, {"DURABLE_WORKFLOW_UPDATE_ID": "example"}),
                      patch.object(updates, "observe", AsyncMock(return_value=(execution, history)))):
                    with self.assertRaisesRegex(RuntimeError, "diagnostics"):
                        await updates.failure(client)


class SnapshotObservationTest(unittest.IsolatedAsyncioTestCase):
    async def test_update_and_query_read_the_same_committed_snapshot(self):
        signal = {"request_id": "example-touch", "delta": 7}
        expected = {"workflow_id": "example-rust", "run_id": "original",
                    "workflow_input": ["example-rust"], "signals": [[signal], [[1, 2]], []]}
        execution = types.SimpleNamespace(workflow_id="example-rust", run_id="original")
        history = {"events": [
            {"event_type": "SignalReceived", "payload": {"signal_name": "updates-touch",
                "signal_id": "map", "arguments": serializer.envelope([signal])}},
            {"event_type": "SignalReceived", "payload": {"signal_name": "updates-touch",
                "signal_id": "nested", "arguments": serializer.envelope([[1, 2]])}},
            {"event_type": "SignalReceived", "payload": {"signal_name": "updates-touch",
                "signal_id": "empty", "arguments": serializer.envelope([])}},
            *[{"event_type": "SignalApplied", "payload": {"signal_name": "updates-touch", "signal_id": identity}}
              for identity in ("map", "nested", "empty")],
            {"event_type": "UpdateAccepted", "payload": {"update_id": "snapshot-update",
                "arguments": serializer.envelope([updates.request("python", "example-snapshot-initial")])}},
            {"event_type": "UpdateCompleted", "payload": {"update_id": "snapshot-update",
                "result": serializer.envelope(expected)}},
        ]}
        client = types.SimpleNamespace(
            get_workflow_handle=lambda _id: types.SimpleNamespace(signal=AsyncMock()),
            query_workflow=AsyncMock(return_value={"result": expected}),
            update_workflow=AsyncMock(return_value={"update_status": "completed",
                "update_id": "snapshot-update", "result_envelope": serializer.envelope(expected)}),
        )
        with (patch.dict(os.environ, {"DURABLE_WORKFLOW_UPDATE_ID": "example"}),
              patch.object(updates, "observe", AsyncMock(return_value=(execution, history))),
              patch.object(updates, "emit")):
            await updates.snapshot(client)
            for change in ({"workflow_input": None}, {"signals": []}, {"run_id": "replacement"}):
                with self.subTest(change=change):
                    result = {**expected, **change}
                    client.update_workflow.return_value["result_envelope"] = serializer.envelope(result)
                    history["events"][-1]["payload"]["result"] = serializer.envelope(result)
                    with self.assertRaisesRegex(RuntimeError, "committed signal snapshot"):
                        await updates.snapshot(client)
            client.update_workflow.return_value["result_envelope"] = serializer.envelope(expected)
            with self.assertRaisesRegex(RuntimeError, "original completion"):
                await updates.snapshot(client)
            history["events"][-1]["payload"]["result"] = serializer.envelope(expected)
            history["events"][3]["payload"]["signal_id"] = "nested"
            with self.assertRaisesRegex(RuntimeError, "applied once each"):
                await updates.snapshot(client)


class StatefulMutationObservationTest(unittest.TestCase):
    def setUp(self):
        self.request_id = "example-increment-python-rust"
        self.state = {"counter": 3, "mutations": ["example-increment-php-rust", self.request_id]}
        self.request = updates.increment_request("python", self.request_id, 2)
        self.result = {"handler_runtime": "rust", "request": self.request, "state": self.state}
        self.history = {"events": [
            {"event_type": kind, "payload": {"update_id": "original", "update_name": "increment",
                "arguments": serializer.envelope([self.request])}}
            for kind in ("UpdateAccepted", "UpdateApplied")
        ] + [{"event_type": "UpdateCompleted", "payload": {"update_id": "original",
                "result": serializer.envelope(self.result)}}]}

    def verify(self, history):
        return updates.verify_increment(history, self.request_id, "rust", "python", 2, self.state)

    def test_accumulated_state_retains_its_original_applied_identity(self):
        self.assertEqual(self.verify(self.history)[0], "original")

    def test_reset_double_count_or_reordered_state_is_rejected(self):
        for state in ({"counter": 2, "mutations": [self.request_id]},
                      {"counter": 5, "mutations": self.state["mutations"] + [self.request_id]},
                      {"counter": 3, "mutations": list(reversed(self.state["mutations"]))},
                      {"counter": 3.0, "mutations": self.state["mutations"]}):
            with self.subTest(state=state):
                history = copy.deepcopy(self.history)
                history["events"][-1]["payload"]["result"] = serializer.envelope({**self.result, "state": state})
                with self.assertRaisesRegex(RuntimeError, "accumulated state"):
                    self.verify(history)

    def test_missing_repeated_or_out_of_order_application_is_rejected(self):
        for events in ([self.history["events"][0], self.history["events"][-1]],
                       self.history["events"] + [self.history["events"][1]],
                       [self.history["events"][1], self.history["events"][0], self.history["events"][-1]]):
            with self.subTest(events=events), self.assertRaises(RuntimeError):
                self.verify({"events": events})

    def test_changed_durable_delta_or_handler_is_rejected(self):
        for index in (0, 1):
            history = copy.deepcopy(self.history)
            history["events"][index]["payload"]["arguments"] = serializer.envelope([{**self.request, "delta": 200}])
            with self.subTest(index=index), self.assertRaisesRegex(RuntimeError, "original request"):
                self.verify(history)
        history = copy.deepcopy(self.history)
        history["events"][1]["payload"]["update_name"] = "echo"
        with self.assertRaisesRegex(RuntimeError, "original request"):
            self.verify(history)

    def test_failed_mutation_is_not_a_successful_state_change(self):
        self.history["events"][-1]["payload"]["failure_id"] = "failed"
        with self.assertRaisesRegex(RuntimeError, "accumulated state"):
            self.verify(self.history)


class HistoryPaginationTest(unittest.IsolatedAsyncioTestCase):
    async def test_observer_reads_later_pages(self):
        client = types.SimpleNamespace(
            describe_workflow=AsyncMock(return_value=types.SimpleNamespace(workflow_id="example-rust", run_id="original")),
            get_history=AsyncMock(side_effect=[
                {"events": [{"event_type": "UpdateAccepted"}], "next_page_token": "page-2"},
                {"events": [{"event_type": "UpdateCompleted"}], "next_page_token": None},
            ]),
        )
        with patch.dict(os.environ, {"DURABLE_WORKFLOW_UPDATE_ID": "example",
                                     "DURABLE_WORKFLOW_UPDATE_RUNS": json.dumps({"runtime": "rust", "run_id": "original"})}):
            _, history = await updates.observe(client, "rust")
        self.assertEqual([event["event_type"] for event in history["events"]], ["UpdateAccepted", "UpdateCompleted"])
        self.assertEqual(client.get_history.await_args.kwargs["next_page_token"], "page-2")

    async def test_replacement_run_is_rejected(self):
        client = types.SimpleNamespace(describe_workflow=AsyncMock(
            return_value=types.SimpleNamespace(workflow_id="example-rust", run_id="replacement")))
        with patch.dict(os.environ, {"DURABLE_WORKFLOW_UPDATE_ID": "example",
                                     "DURABLE_WORKFLOW_UPDATE_RUNS": json.dumps({"runtime": "rust", "run_id": "original"})}):
            with self.assertRaisesRegex(RuntimeError, "original run"):
                await updates.observe(client, "rust")


if __name__ == "__main__":
    unittest.main()
