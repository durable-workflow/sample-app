"""Reject lost or replaced child relationships and contradictory outcomes."""

import asyncio
import copy
import importlib.util
import json
import pathlib
import sys
import tempfile
import types
import unittest
from unittest.mock import AsyncMock, patch

serializer = types.SimpleNamespace(decode_envelope=lambda value, **_: value["decoded"])
SCRIPT = pathlib.Path(__file__).parents[1] / "scripts" / "sdk_children.py"
spec = importlib.util.spec_from_file_location("sdk_children", SCRIPT)
children = importlib.util.module_from_spec(spec)
with patch.dict(sys.modules, {"durable_workflow": types.SimpleNamespace(Client=object, serializer=serializer)}):
    spec.loader.exec_module(children)


def event(kind, **payload):
    return {"event_type": kind, "payload": payload, "principal": None}


class CancellationIdentityTest(unittest.TestCase):
    def setUp(self):
        self.root = {"request_id": "root", "requested_at": "2026-10-08T01:00:00Z",
                     "cleanup_deadline_at": "2026-10-08T01:00:30Z"}
        self.parent = {"schema": "durable-workflow.cancellation-context/v1", **self.root,
            "root_request_id": "root", "root_workflow_instance_id": "parent", "root_workflow_run_id": "parent-run",
            "parent_request_id": None, "reason": "SDK child cancellation conformance",
            "requester": children.CANCELLATION_ACTOR, "source": "control_plane",
            "lineage": [{"request_id": "root", "workflow_instance_id": "parent", "workflow_run_id": "parent-run"}]}
        self.child = {**self.parent, "request_id": "child-request", "parent_request_id": "root",
            "lineage": [*self.parent["lineage"], {"request_id": "child-request", "workflow_instance_id": "child",
                                                 "workflow_run_id": "child-run"}]}
        self.record = {"root_request": self.root, "root_context": self.parent, "parent_workflow_id": "parent",
            "parent_run_id": "parent-run", "child_workflow_instance_id": "child", "child_workflow_run_id": "child-run"}

    def test_original_parent_and_child_context_pass(self):
        for role, context in (("parent", self.parent), ("child", self.child)):
            history = {"events": [event("CooperativeCancellationRequested", cancellation=context)]}
            history["events"][0]["principal"] = children.CANCELLATION_ACTOR if role == "parent" else None
            self.assertEqual(children.cancellation_context(history, self.record, role), context)

    def test_changed_child_identity_budget_or_metadata_fails(self):
        for key in ("schema", "root_request_id", "root_workflow_instance_id", "root_workflow_run_id",
                    "requested_at", "cleanup_deadline_at", "reason", "parent_request_id", "requester", "source"):
            changed = copy.deepcopy(self.child)
            changed[key] = "replacement"
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                children.cancellation_context({"events": [event("CooperativeCancellationRequested", cancellation=changed)]}, self.record, "child")
        for index in (0, 1):
            for key in ("request_id", "workflow_instance_id", "workflow_run_id"):
                changed = copy.deepcopy(self.child)
                changed["lineage"][index][key] = "replacement"
                with self.subTest(index=index, key=key), self.assertRaises(RuntimeError):
                    children.cancellation_context({"events": [event("CooperativeCancellationRequested", cancellation=changed)]}, self.record, "child")

    def test_cleanup_markers_require_original_role_runtime_and_order(self):
        values = [{"role": "child", "runtime": "rust", "stage": stage} for stage in ("entry", "finished")]
        def history(items):
            return {"events": [event("SideEffectRecorded", result={"decoded": item}) for item in items]}
        self.assertEqual(children.markers(history(values), "child", "rust"), values)
        for changed in ([], values[::-1], values + values[:1], [values[1]],
                        [{**values[0], "role": "parent"}], [{**values[0], "runtime": "php"}]):
            with self.subTest(changed=changed), self.assertRaises(RuntimeError):
                children.markers(history(changed), "child", "rust")

    def cancelled_histories(self):
        record = {**self.record, "parent": "python", "child": "rust", "child_type": "child",
                  "workflow_link_id": "link", "child_call_id": "call", "wait_id": "wait", "wait_sequence": 1}
        link = {key: record[key] for key in
                ("child_workflow_instance_id", "child_workflow_run_id", "workflow_link_id", "child_call_id")}
        histories = []
        for role, runtime, context, delivered_at, finished_at in (
                ("parent", "python", self.parent, "21", "22"), ("child", "rust", self.child, "02", "20")):
            requested = event("CooperativeCancellationRequested", cancellation=context)
            requested["principal"] = children.CANCELLATION_ACTOR if role == "parent" else None
            delivery = {**event("CooperativeCancellationDelivered", cancellation=context, sequence=3),
                        "timestamp": f"2026-10-08T01:00:{delivered_at}Z"}
            values = [{"role": role, "runtime": runtime, "stage": stage, "context": context, "remaining": remaining}
                      for stage, remaining in (("entry", 28), ("finished", 5))]
            timer = event("TimerScheduled", timer_id="timer")
            cleanup = {"outcome": "completed", "request_id": context["request_id"],
                       "cleanup_deadline_at": context["cleanup_deadline_at"], "delivery_sequence": 3,
                       "delivery_history_event_id": "delivery", "finished_at": f"2026-10-08T01:00:{finished_at}Z"}
            terminal = {**event("WorkflowCancelled", cancellation_cleanup=cleanup),
                        "timestamp": f"2026-10-08T01:00:{finished_at}Z"}
            history = {"events": [requested, delivery, timer, event("TimerFired", timer_id="timer"),
                *[event("SideEffectRecorded", result={"decoded": value}) for value in values], terminal]}
            if role == "parent":
                history["events"] += [event("ChildWorkflowScheduled", **link, child_workflow_type="child"),
                    event("ChildRunStarted", **link, child_workflow_type="child"), event("ChildRunCancelled", **link)]
            else:
                history["events"].append(event("SignalWaitOpened", signal_wait_id="wait", sequence=1,
                                               signal_name="child-finish"))
                record.update(child_delivery=delivery, child_cleanup_entry=values[0], child_cleanup_timer=timer)
            histories.append(history)
        return *histories, record

    def test_durable_timestamps_allow_late_observation_but_refuse_late_or_missing_completion(self):
        parent, child, record = self.cancelled_histories()
        children.verify_cancelled(parent, child, record)
        for role in (0, 1):
            for field in ("terminal", "cleanup", "missing"):
                histories = copy.deepcopy([parent, child])
                terminal = children.one(histories[role], "WorkflowCancelled")
                if field == "missing":
                    histories[role]["events"].remove(terminal)
                elif field == "terminal":
                    terminal["timestamp"] = record["root_request"]["cleanup_deadline_at"]
                else:
                    terminal["payload"]["cancellation_cleanup"]["finished_at"] = record["root_request"]["cleanup_deadline_at"]
                with self.subTest(role=role, field=field), self.assertRaises(RuntimeError):
                    children.verify_cancelled(*histories, record)


class ChildRelationshipTest(unittest.TestCase):
    def setUp(self):
        self.record = {"parent": "python", "child": "rust", "value": "marker",
            "parent_workflow_id": "original-parent", "parent_run_id": "parent-run",
            "child_type": "sample-app.child-matrix.rust.child",
            "child_workflow_instance_id": "original-child", "child_workflow_run_id": "child-run",
            "workflow_link_id": "link", "child_call_id": "call"}
        self.link = {key: self.record[key] for key in
                     ("child_workflow_instance_id", "child_workflow_run_id", "workflow_link_id", "child_call_id")}
        self.result = children.expected_result(self.record)
        self.parent = {"events": [event("WorkflowStarted"),
            event("ChildWorkflowScheduled", **self.link, child_workflow_type=self.record["child_type"]),
            event("ChildRunStarted", **self.link, child_workflow_type=self.record["child_type"]),
            event("ChildRunCompleted", **self.link),
            event("WorkflowCompleted", output={"decoded": self.result})]}
        self.child = {"events": [event("WorkflowStarted", workflow_run_id="child-run", parent_workflow_run_id="parent-run"),
            event("WorkflowCompleted", output={"decoded": self.result["child_result"]})]}

    def verify(self, parent=None, child=None, result=None):
        children.verify_pair(parent or self.parent, child or self.child, self.record, result or self.result)

    def test_original_relationship_and_results_pass(self):
        self.verify()

    def test_parked_records_can_be_reported_in_the_next_phase(self):
        parked = [{**self.record, "parent": parent, "child": child, "scenario": "child-parked"}
                  for parent, child in children.RUST_DIRECTIONS]
        with patch.dict(children.os.environ, {"DURABLE_WORKFLOW_CHILD_RUNS":
                "\n".join(json.dumps(record) for record in parked)}):
            pending = children.records()
        self.assertEqual(len(pending), 5)
        with patch("builtins.print") as output:
            for record in pending:
                children.emit(scenario="child-signal-without-workers", **record)
        self.assertEqual(output.call_count, 5)
        for call in output.call_args_list:
            self.assertEqual(json.loads(call.args[0])["scenario"], "child-signal-without-workers")

    def test_recovery_matches_each_runtimes_original_wait_and_signal(self):
        for runtime in children.RUNTIMES:
            signal_wait = runtime == "rust"
            opened_kind = "SignalWaitOpened" if signal_wait else "ConditionWaitOpened"
            resolved_kind = "SignalApplied" if signal_wait else "ConditionWaitSatisfied"
            wait_key = "signal_wait_id" if signal_wait else "condition_wait_id"
            signal_key = "signal_id" if signal_wait else "workflow_signal_id"
            history = {"events": [event(opened_kind, **{wait_key: "original-wait", "sequence": 1,
                "signal_name" if signal_wait else "condition_key": "child-finish"}),
                event("SignalReceived", signal_id="accepted-signal", signal_name="child-finish"),
                event(resolved_kind, **{wait_key: "original-wait", "sequence": 1,
                    signal_key: "accepted-signal", "signal_name": "child-finish"}),
                event("WorkflowCompleted")]}
            record = {**self.record, "child": runtime, "wait_id": "original-wait", "wait_sequence": 1}
            with self.subTest(runtime=runtime):
                children.verify_resume(history, record)
            for index, key in ((0, wait_key), (2, wait_key), (2, signal_key), (2, "sequence"), (2, "signal_name")):
                changed = copy.deepcopy(history)
                changed["events"][index]["payload"][key] = "replacement"
                with self.subTest(runtime=runtime, index=index, key=key), self.assertRaises(RuntimeError):
                    children.verify_resume(changed, record)
            for index in (0, 1, 2):
                changed = copy.deepcopy(history)
                changed["events"].append(copy.deepcopy(changed["events"][index]))
                with self.subTest(runtime=runtime, duplicate=index), self.assertRaises(RuntimeError):
                    children.verify_resume(changed, record)
            changed = copy.deepcopy(history)
            changed["events"][2], changed["events"][3] = changed["events"][3], changed["events"][2]
            with self.subTest(runtime=runtime, out_of_order=True), self.assertRaises(RuntimeError):
                children.verify_resume(changed, record)

    def test_missing_or_duplicate_schedule_start_completion_is_rejected(self):
        for index in (1, 2, 3, 4):
            for duplicate in (False, True):
                parent = copy.deepcopy(self.parent)
                if duplicate:
                    parent["events"].append(copy.deepcopy(parent["events"][index]))
                else:
                    parent["events"].pop(index)
                with self.subTest(index=index, duplicate=duplicate), self.assertRaises(RuntimeError):
                    self.verify(parent=parent)

    def test_changed_link_call_or_child_identity_is_rejected(self):
        for key in self.link:
            for index in (1, 2, 3):
                parent = copy.deepcopy(self.parent)
                parent["events"][index]["payload"][key] = "replacement"
                with self.subTest(key=key, index=index), self.assertRaises(RuntimeError):
                    self.verify(parent=parent)
        parent = copy.deepcopy(self.parent)
        for index in (1, 2, 3):
            parent["events"][index]["payload"]["child_workflow_run_id"] = "replacement"
        with self.assertRaisesRegex(RuntimeError, "original child"):
            self.verify(parent=parent)

    def test_wrong_runtime_or_input_and_changed_persisted_results_are_rejected(self):
        for result in ({**self.result, "parent_runtime": "php"},
                       {**self.result, "child_result": {"runtime": "php", "value": "marker"}},
                       {**self.result, "child_result": {"runtime": "rust", "value": "different"}}):
            with self.subTest(result=result), self.assertRaises(RuntimeError):
                self.verify(result=result)
        parent, child = copy.deepcopy(self.parent), copy.deepcopy(self.child)
        parent["events"][-1]["payload"]["output"]["decoded"] = {"changed": True}
        child["events"][-1]["payload"]["output"]["decoded"]["value"] = "changed"
        with self.assertRaises(RuntimeError):
            self.verify(parent=parent)
        with self.assertRaises(RuntimeError):
            self.verify(child=child)

    def test_wrong_parent_or_child_start_and_duplicate_child_completion_are_rejected(self):
        for key in ("workflow_run_id", "parent_workflow_run_id"):
            child = copy.deepcopy(self.child)
            child["events"][0]["payload"][key] = "replacement"
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                self.verify(child=child)
        child = copy.deepcopy(self.child)
        child["events"].append(copy.deepcopy(child["events"][-1]))
        with self.assertRaises(RuntimeError):
            self.verify(child=child)

    def test_out_of_order_or_wrong_terminal_parent_lifecycle_is_rejected(self):
        parent = copy.deepcopy(self.parent)
        parent["events"][1], parent["events"][2] = parent["events"][2], parent["events"][1]
        with self.assertRaises(RuntimeError):
            self.verify(parent=parent)
        parent["events"][-1]["event_type"] = "WorkflowFailed"
        with self.assertRaises(RuntimeError):
            self.verify(parent=parent)

    def test_typed_failure_must_match_the_original_durable_failure(self):
        result = children.expected_result(self.record, failed=True)
        message = result["child_failure"]["message"]
        parent, child = copy.deepcopy(self.parent), copy.deepcopy(self.child)
        parent["events"][-1]["payload"]["output"] = {"decoded": result}
        parent["events"][3] = event("ChildRunFailed", **self.link, failure_id="failure", message=message)
        child["events"][-1] = event("WorkflowFailed", failure_id="failure", message=message)
        children.verify_pair(parent, child, self.record, result, failed=True)
        for field, replacement in (("failure_id", "replacement"), ("message", "other")):
            changed = copy.deepcopy(child)
            changed["events"][-1]["payload"][field] = replacement
            with self.subTest(field=field), self.assertRaises(RuntimeError):
                children.verify_pair(parent, changed, self.record, result, failed=True)
        wrong_result = {**result, "child_failure": {**result["child_failure"], "type": "RuntimeError"}}
        with self.assertRaises(RuntimeError):
            children.verify_pair(parent, child, self.record, wrong_result, failed=True)


class ChildObservationTest(unittest.IsolatedAsyncioTestCase):
    async def test_replacement_parent_or_child_run_is_rejected(self):
        client = types.SimpleNamespace(describe_workflow=AsyncMock(return_value=types.SimpleNamespace(run_id="replacement")))
        with self.assertRaisesRegex(RuntimeError, "original workflow run"):
            await children.observe(client, "workflow", "original")

    async def test_later_history_pages_are_read(self):
        client = types.SimpleNamespace(describe_workflow=AsyncMock(return_value=types.SimpleNamespace(run_id="original")),
            get_history=AsyncMock(side_effect=[{"events": [event("ChildRunStarted")], "next_page_token": "next"},
                {"events": [event("ChildRunCompleted")], "next_page_token": None}]))
        _, history = await children.observe(client, "workflow", "original")
        self.assertEqual([item["event_type"] for item in history["events"]], ["ChildRunStarted", "ChildRunCompleted"])
        self.assertEqual(client.get_history.await_args.kwargs["next_page_token"], "next")

    async def test_cancellation_phase_observes_every_direction_after_its_original_deadline(self):
        pending = [{"parent": parent, "child": child, "parent_workflow_id": f"parent-{index}",
                    "parent_run_id": f"parent-run-{index}", "child_workflow_instance_id": f"child-{index}",
                    "child_workflow_run_id": f"child-run-{index}", "root_request": {
                        "requested_at": "2020-01-01T00:00:00Z", "cleanup_deadline_at": "2020-01-01T00:00:30Z"}}
                   for index, (parent, child) in enumerate(children.RUST_DIRECTIONS)]
        history = {"events": [{**event("WorkflowCancelled"), "timestamp": "2020-01-01T00:00:20Z"}]}
        observed = AsyncMock(return_value=(types.SimpleNamespace(status="cancelled"), history))
        with tempfile.TemporaryDirectory() as directory:
            (pathlib.Path(directory) / "gateway.jsonl").write_text("")
            with patch.object(children, "records", return_value=pending), \
                    patch.object(children, "observe", observed), \
                    patch.object(children, "verify_cancelled") as verified, \
                    patch.object(children, "inspect_cascade", AsyncMock(return_value={})), \
                    patch.object(children, "verify_cancellation_transport", return_value={}), \
                    patch.object(children, "emit"), \
                    patch.dict(children.os.environ, {"CHILD_PROOF_DIR": directory}):
                await children.cancellation_verify(object())
        self.assertEqual(observed.await_count, 10)
        self.assertEqual(verified.call_count, 5)
        self.assertEqual([call.args[1] for call in observed.await_args_list],
                         [identity for row in pending for identity in (row["parent_workflow_id"], row["child_workflow_instance_id"])])

    async def test_cancellation_observation_timeout_bounds_a_stalled_request(self):
        async def stalled(*_):
            await asyncio.Future()
        record = {"parent_workflow_id": "parent", "parent_run_id": "run",
                  "child_workflow_instance_id": "child", "child_workflow_run_id": "child-run"}
        with patch.object(children, "CANCELLATION_OBSERVATION_SECONDS", .01), \
                patch.object(children, "observe", stalled):
            with self.assertRaisesRegex(RuntimeError, "observation budget"):
                await children.observe_cancellation(object(), record)

    async def test_cancellation_observation_polls_pending_and_refuses_wrong_terminal_outcomes(self):
        record = {"parent_workflow_id": "parent", "parent_run_id": "run",
                  "child_workflow_instance_id": "child", "child_workflow_run_id": "child-run"}
        def result(status):
            return types.SimpleNamespace(status=status), {"events": []}
        observed = AsyncMock(side_effect=[result("running"), result("cancelled"),
                                         result("cancelled"), result("cancelled")])
        with patch.object(children, "observe", observed), patch.object(children.asyncio, "sleep", AsyncMock()):
            await children.observe_cancellation(object(), record)
        self.assertEqual(observed.await_count, 4)
        for status in ("failed", "terminated", "completed"):
            with self.subTest(status=status), patch.object(children, "emit"), \
                    patch.object(children, "observe", AsyncMock(side_effect=[result(status), result("cancelled")])):
                with self.assertRaisesRegex(RuntimeError, "unexpected terminal"):
                    await children.observe_cancellation(object(), record)


if __name__ == "__main__":
    unittest.main()
