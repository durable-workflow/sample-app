import copy
import unittest

from durable_workflow import serializer
from principal_gateway import BODY_FIELDS, HEADERS
from sdk_namespaces import (IDENTITIES, parked_identity, verify_anonymous_transport, verify_gateway, verify_history,
                            verify_operation_history, verify_principals, verify_query_receipt, verify_rotation)


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

    def operation_fixture(self, terminal):
        namespace = "rust-namespace-a"
        operator = {"type": "auth:runtime-token", "id": f"fixture-{namespace}-operator", "label": "Operator"}
        worker = {"type": "auth:runtime-token", "id": f"fixture-{namespace}-worker", "label": "Worker"}
        events = [{"event_type": "WorkflowStarted", "payload": {"workflow_run_id": "original"}, "principal": operator}]
        waiting = None
        if terminal == "WorkflowCancelled":
            events.append({"event_type": "SignalWaitOpened", "payload": {"signal_wait_id": "original-wait"}, "principal": None})
            waiting = {"history": {"events": copy.deepcopy(events)}}
        events.append({"event_type": terminal, "payload": {"message": "principal-fixture-failure"},
                       "principal": worker if terminal == "WorkflowFailed" else operator})
        return {"events": events}, namespace, {"run_id": "original"}, terminal, waiting

    def test_real_operation_actor_shapes_pass(self):
        for terminal in ("WorkflowFailed", "WorkflowCancelled"):
            self.assertEqual(verify_operation_history(*self.operation_fixture(terminal))["event_type"], terminal)

    def test_operation_cannot_change_run_actor_or_terminal_boundary(self):
        for terminal in ("WorkflowFailed", "WorkflowCancelled"):
            fixture = self.operation_fixture(terminal)
            for mutation in ("run", "actor", "missing", "duplicate", "wrong-status"):
                changed = copy.deepcopy(fixture)
                events = changed[0]["events"]
                if mutation == "run":
                    events[0]["payload"]["workflow_run_id"] = "replacement"
                elif mutation == "actor":
                    events[-1]["principal"] = {"id": "mallory", "type": "attacker"}
                elif mutation == "missing":
                    events.pop()
                elif mutation == "duplicate":
                    events.append(copy.deepcopy(events[-1]))
                else:
                    events[-1]["event_type"] = "WorkflowCompleted"
                with self.subTest(terminal=terminal, mutation=mutation), self.assertRaises(RuntimeError):
                    verify_operation_history(*changed)

    def test_terminal_cancel_cannot_rewrite_wait_or_resume(self):
        for mutation in ("wait", "signal", "applied"):
            fixture = self.operation_fixture("WorkflowCancelled")
            if mutation == "wait":
                fixture[0]["events"][1]["payload"]["signal_wait_id"] = "replacement"
            else:
                fixture[0]["events"].insert(2, {"event_type": "SignalReceived" if mutation == "signal" else "SignalApplied", "payload": {}})
            with self.subTest(mutation=mutation), self.assertRaises(RuntimeError):
                verify_operation_history(*fixture)

    def query_fixture(self):
        result = {"workflow_id": "original-workflow", "run_id": "original-run",
                  "principal": {"type": "attacker", "id": "mallory"}}
        record = {"namespace": "rust-namespace-a", **result, "result": result}
        receipt = {"response_principal": {"type": "auth:runtime-token", "id": "fixture-rust-namespace-a-operator", "label": "Operator"},
                   "response_run_id": "original-run", "status": 200, "headers": HEADERS,
                   "body_fields": BODY_FIELDS, "result_envelope": envelope(result)}
        history = {"events": [{"event_type": "WorkflowCompleted", "payload": {"output": envelope("original-result")}}]}
        return receipt, record, history, copy.deepcopy(history)

    def test_query_result_principal_is_distinct_from_authenticated_audit_identity(self):
        review = verify_query_receipt(*self.query_fixture())
        self.assertNotEqual(review["audit_principal"], review["application_principal"])

    def test_query_cannot_claim_workflow_supplied_principal_or_change_history(self):
        for mutation in ("missing-actor", "fake-actor", "run", "header", "body", "envelope", "history"):
            fixture = self.query_fixture()
            if mutation == "missing-actor":
                fixture[0]["response_principal"] = None
            elif mutation == "fake-actor":
                fixture[0]["response_principal"] = fixture[1]["result"]["principal"]
            elif mutation == "run":
                fixture[0]["response_run_id"] = "replacement"
            elif mutation == "header":
                fixture[0]["headers"] = {}
            elif mutation == "body":
                fixture[0]["body_fields"] = {}
            elif mutation == "envelope":
                fixture[0]["result_envelope"] = None
            else:
                fixture[3]["events"].append({"event_type": "WorkflowCompleted", "payload": {}})
            with self.subTest(mutation=mutation), self.assertRaises(RuntimeError):
                verify_query_receipt(*fixture)

    def test_anonymous_terminal_actors_cannot_be_null_named_or_forged(self):
        for terminal in ("WorkflowFailed", "WorkflowCancelled"):
            fixture = self.operation_fixture(terminal)
            for row in fixture[0]["events"]:
                if row.get("principal") is not None:
                    row["principal"] = {"type": "server", "id": "anonymous"}
            self.assertEqual(verify_operation_history(*fixture, anonymous=True)["event_type"], terminal)
            for actor in (None, {"type": "attacker", "id": "mallory"}, {"type": "auth:runtime-token", "id": "named"}):
                changed = copy.deepcopy(fixture)
                changed[0]["events"][-1]["principal"] = actor
                with self.subTest(terminal=terminal, actor=actor), self.assertRaises(RuntimeError):
                    verify_operation_history(*changed, anonymous=True)

    def test_anonymous_query_requires_server_actor_and_observed_absent_credentials(self):
        fixture = self.query_fixture()
        fixture[0]["response_principal"] = {"type": "server", "id": "anonymous"}
        fixture[0]["authorization_present"] = False
        verify_query_receipt(*fixture, anonymous=True)
        for mutation in ("credential", "missing-transport", "forged-actor"):
            changed = copy.deepcopy(fixture)
            if mutation == "credential":
                changed[0]["authorization_present"] = True
            elif mutation == "missing-transport":
                changed[0].pop("authorization_present")
            else:
                changed[0]["response_principal"] = {"type": "attacker", "id": "mallory"}
            with self.subTest(mutation=mutation), self.assertRaises(RuntimeError):
                verify_query_receipt(*changed, anonymous=True)

    def test_anonymous_transport_requires_all_actual_operations_without_credentials(self):
        kinds = ["start"] * 3 + ["signal", "activity-complete", "query", "cancel"] + ["workflow-task-complete"] * 5
        receipts = [{"kind": kind, "namespace": "default", "status": 200, "headers": HEADERS,
                     "body_fields": BODY_FIELDS, "authorization_present": False, "commands": []} for kind in kinds]
        receipts[-1]["commands"] = ["complete_workflow"]
        receipts[-2]["commands"] = ["fail_workflow"]
        self.assertEqual(len(verify_anonymous_transport(receipts)), 12)
        for mutation in ("credential", "missing-transport", "missing-start", "duplicate-start", "failure", "headers", "namespace"):
            changed = copy.deepcopy(receipts)
            if mutation == "credential":
                changed[0]["authorization_present"] = True
            elif mutation == "missing-transport":
                changed[0].pop("authorization_present")
            elif mutation == "missing-start":
                changed.pop(0)
            elif mutation == "duplicate-start":
                changed.append(copy.deepcopy(changed[0]))
            elif mutation == "failure":
                changed[-2]["commands"] = []
            elif mutation == "headers":
                changed[0]["headers"] = {}
            else:
                changed[0]["namespace"] = "missing"
            with self.subTest(mutation=mutation), self.assertRaises(RuntimeError):
                verify_anonymous_transport(changed)


if __name__ == "__main__":
    unittest.main()
