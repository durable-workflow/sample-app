"""Reject lost or replaced child relationships and contradictory outcomes."""

import copy
import importlib.util
import pathlib
import sys
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
    return {"event_type": kind, "payload": payload}


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


if __name__ == "__main__":
    unittest.main()
