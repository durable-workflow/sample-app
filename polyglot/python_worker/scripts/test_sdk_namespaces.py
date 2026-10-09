import copy
import unittest

from durable_workflow import serializer
from principal_gateway import BODY_FIELDS, HEADERS
from sdk_namespaces import IDENTITIES, parked_identity, verify_gateway, verify_history, verify_principals, verify_rotation


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

    def principal_fixture(self):
        history, record, _ = self.fixture()
        for row in history["events"]:
            role = {"WorkflowStarted": "operator", "SignalReceived": "operator", "WorkflowCompleted": "worker"}.get(row["event_type"])
            row["principal"] = None if role is None else {
                "type": "auth:runtime-token", "id": f"fixture-{record['namespace']}-{role}", "label": role.title()}
        return history, record

    def test_authenticated_principals_pass(self):
        history, record = self.principal_fixture()
        observed = verify_principals(history, record["namespace"], completed=True)
        self.assertEqual(set(observed), {"WorkflowStarted", "SignalReceived", "WorkflowCompleted"})

    def test_missing_forged_wrong_role_or_foreign_principals_fail(self):
        history, record = self.principal_fixture()
        for kind in ("WorkflowStarted", "SignalReceived", "WorkflowCompleted"):
            for wrong in (None, {"id": "mallory", "type": "attacker"},
                          {"id": "fixture-rust-namespace-b-worker", "type": "auth:runtime-token", "label": "Worker"},
                          {"id": "fixture-rust-namespace-a-worker", "type": "auth:runtime-token", "label": "Operator"}):
                changed = copy.deepcopy(history)
                next(row for row in changed["events"] if row["event_type"] == kind)["principal"] = wrong
                with self.subTest(kind=kind, principal=wrong), self.assertRaises(RuntimeError):
                    verify_principals(changed, record["namespace"], completed=True)

    def test_forged_optional_event_principal_fails(self):
        history, record = self.principal_fixture()
        history["events"][1]["principal"] = {"id": "mallory", "type": "attacker"}
        with self.assertRaises(RuntimeError):
            verify_principals(history, record["namespace"], completed=True)

    def gateway_fixture(self):
        records = []
        for namespace, workflow_id in IDENTITIES:
            common = {"status": 200, "namespace": namespace, "headers": HEADERS,
                      "body_fields": BODY_FIELDS, "commands": []}
            records.extend([
                {**common, "kind": "start", "workflow_id": workflow_id},
                {**common, "kind": "signal", "path": f"/api/workflows/{workflow_id}/signal/namespace-finish"},
                {**common, "kind": "workflow-task-complete", "commands": ["complete_workflow"]},
                {**common, "kind": "activity-complete", "activity_attempt_id": f"attempt-{workflow_id}"},
            ])
        return records

    def test_actual_gateway_matrix_passes(self):
        records = self.gateway_fixture()
        self.assertEqual(verify_gateway(records), records)

    def test_missing_duplicate_failed_or_uninjected_gateway_mutations_fail(self):
        records = self.gateway_fixture()
        for index in range(len(records)):
            for change in ("missing", "duplicate", "failed", "header", "body"):
                changed = copy.deepcopy(records)
                if change == "missing":
                    del changed[index]
                elif change == "duplicate":
                    changed.append(copy.deepcopy(changed[index]))
                elif change == "failed":
                    changed[index]["status"] = 403
                else:
                    changed[index]["headers" if change == "header" else "body_fields"] = {}
                with self.subTest(index=index, change=change), self.assertRaises(RuntimeError):
                    verify_gateway(changed)

    def rotation_fixture(self):
        before = {"id": "stable-credential", "subject": "stable-actor", "roles": ["worker"],
                  "tenant": "original-namespace", "claims": {}, "created_at": "original-created-at",
                  "rotated_at": None, "revoked_at": None, "expires_at": None}
        after = {**before, "rotated_at": "original-rotation-boundary"}
        return before, after, copy.deepcopy(after)

    def test_rotation_and_duplicate_preserve_identity(self):
        before, after, repeated = self.rotation_fixture()
        self.assertEqual(verify_rotation(before, after, repeated)["subject"], "stable-actor")

    def test_rotation_cannot_change_actor_authority_or_duplicate_boundary(self):
        before, after, repeated = self.rotation_fixture()
        for target in (1, 2):
            for key in ("id", "subject", "roles", "tenant", "claims", "created_at", "expires_at", "rotated_at", "revoked_at"):
                changed = copy.deepcopy([before, after, repeated])
                changed[target][key] = "replaced"
                with self.subTest(target=target, key=key), self.assertRaises(RuntimeError):
                    verify_rotation(*changed)


if __name__ == "__main__":
    unittest.main()
