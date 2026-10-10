"""Reject forged requesters, misleading internal actors and incomplete receipts."""

import copy
import json
import unittest
from unittest.mock import patch

from principal_gateway import BODY_FIELDS, HEADERS
from sdk_children import CANCELLATION_ACTOR, cancellation_actor, caller_responses, verify_cancellation_transport, verify_cascade_request


def history(role):
    return {"events": [{"event_type": "CooperativeCancellationRequested",
                        "principal": CANCELLATION_ACTOR if role == "parent" else None,
                        "payload": {"cancellation": {"requester": CANCELLATION_ACTOR, "source": "control_plane"}}}]}


def transport():
    pending = [{"parent_workflow_id": "original", "parent_run_id": "original-run", "root_request": {
        "request_id": "original-request", "requested_at": "2026-10-09T00:00:00Z",
        "cleanup_deadline_at": "2026-10-09T00:00:30Z"}}]
    receipts = [{"kind": "cooperative-cancel", "path": "/api/workflows/original/runs/original-run/request-cancellation",
                 "status": status, "namespace": "default", "authorization_present": True,
                 "body_fields": copy.deepcopy(BODY_FIELDS), "headers": copy.deepcopy(HEADERS),
                 "response_duplicate": duplicate, "response_cancellation_request": copy.deepcopy(pending[0]["root_request"])}
                for status, duplicate in ((202, False), (200, True))]
    return pending, receipts


class CooperativePrincipalChecks(unittest.TestCase):
    def test_each_actual_sdk_caller_and_original_run_is_required(self):
        pending = [{"parent": parent, "child": child, "parent_workflow_id": parent,
                    "parent_run_id": f"run-{parent}"}
                   for parent, child in (("php", "python"), ("python", "rust"), ("rust", "php"))]
        for phase in ("request", "duplicate", "deny-worker", "deny-anonymous"):
            rows = [{"caller": run["child" if phase == "duplicate" else "parent"], "phase": phase,
                     "workflow_id": run["parent_workflow_id"], "run_id": run["parent_run_id"]} for run in pending]
            with patch.dict("os.environ", {"DURABLE_WORKFLOW_CANCELLATION_RESPONSES": "\n".join(map(json.dumps, rows))}):
                self.assertEqual(3, len(caller_responses(pending, phase)))
            for mutation in ("missing", "duplicate", "caller", "phase", "run"):
                changed = copy.deepcopy(rows)
                if mutation == "missing":
                    changed.pop()
                elif mutation == "duplicate":
                    changed.append(copy.deepcopy(changed[0]))
                else:
                    changed[0][{"run": "run_id"}.get(mutation, mutation)] = "replacement"
                with self.subTest(phase=phase, mutation=mutation), patch.dict("os.environ", {
                        "DURABLE_WORKFLOW_CANCELLATION_RESPONSES": "\n".join(map(json.dumps, changed))}), self.assertRaises(RuntimeError):
                    caller_responses(pending, phase)

    def test_runtime_and_anonymous_requesters_keep_internal_events_unattributed(self):
        for actor in ({"type": "auth:runtime-token", "id": "fixture-cancellation-operator", "label": "Operator"},
                      {"type": "server", "id": "anonymous", "label": "Admin"}):
            for role in ("parent", "child"):
                value = history(role)
                value["events"][0]["principal"] = actor if role == "parent" else None
                value["events"][0]["payload"]["cancellation"]["requester"] = actor
                self.assertEqual(actor, cancellation_actor(value, role, actor)["requester"])
                value["events"][0]["payload"]["cancellation"]["requester"] = CANCELLATION_ACTOR
                with self.subTest(actor=actor, role=role), self.assertRaises(RuntimeError):
                    cancellation_actor(value, role, actor)

    def test_anonymous_transport_really_has_no_authorization_header(self):
        pending, receipts = transport()
        pending[0].update(cancellation_mode="anonymous", cancellation_actor={"type": "server", "id": "anonymous", "label": "Admin"})
        for row in receipts:
            row["authorization_present"] = False
        self.assertEqual(2, verify_cancellation_transport(pending, receipts)["original_and_duplicate_requests"])
        receipts[1]["authorization_present"] = True
        with self.assertRaises(RuntimeError):
            verify_cancellation_transport(pending, receipts)

    def test_cascade_projection_preserves_request_metadata_and_uses_edges_for_lineage(self):
        context = {**transport()[0][0]["root_request"], "requester": CANCELLATION_ACTOR,
                   "source": "control_plane", "lineage": [{"request_id": "original-request"}]}
        metadata = {"request_id": "original-request", "requested_at": "2026-10-09T00:00:00Z",
                    "cleanup_deadline_at": "2026-10-09T00:00:30Z", "requester": CANCELLATION_ACTOR,
                    "source": "control_plane"}
        verify_cascade_request(metadata, context)
        for key in metadata:
            changed = copy.deepcopy(metadata)
            changed[key] = "replacement"
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                verify_cascade_request(changed, context)

    def test_root_and_internal_propagation_preserve_authenticated_requester(self):
        for role in ("parent", "child"):
            self.assertEqual(CANCELLATION_ACTOR, cancellation_actor(history(role), role)["requester"])

    def test_rejects_null_forged_or_missing_requester_and_wrong_source(self):
        for role in ("parent", "child"):
            for mutation in ("null", "forged", "missing", "source"):
                value = copy.deepcopy(history(role))
                context = value["events"][0]["payload"]["cancellation"]
                if mutation == "source":
                    context["source"] = "spoofed-gateway"
                elif mutation == "missing":
                    context.pop("requester")
                else:
                    context["requester"] = None if mutation == "null" else {"id": "mallory", "type": "attacker"}
                with self.subTest(role=role, mutation=mutation), self.assertRaises(RuntimeError):
                    cancellation_actor(value, role)

    def test_rejects_missing_principal_field_and_wrong_internal_classification(self):
        for role in ("parent", "child"):
            for mutation in ("missing", "wrong"):
                value = copy.deepcopy(history(role))
                if mutation == "missing":
                    value["events"][0].pop("principal")
                else:
                    value["events"][0]["principal"] = None if role == "parent" else CANCELLATION_ACTOR
                with self.subTest(role=role, mutation=mutation), self.assertRaises(RuntimeError):
                    cancellation_actor(value, role)

    def test_original_and_duplicate_actual_receipts(self):
        result = verify_cancellation_transport(*transport())
        self.assertEqual(2, result["original_and_duplicate_requests"])
        self.assertEqual(CANCELLATION_ACTOR, result["authenticated_requester"])

    def test_rejects_missing_duplicate_denied_wrong_run_and_incomplete_injection(self):
        for mutation in ("missing", "duplicate", "denied", "path", "namespace", "credential", "body", "headers"):
            pending, receipts = transport()
            if mutation == "missing":
                receipts.pop()
            elif mutation == "duplicate":
                receipts.append(copy.deepcopy(receipts[0]))
            elif mutation == "denied":
                receipts[0]["status"] = 401
            elif mutation == "path":
                receipts[0]["path"] = receipts[0]["path"].replace("original-run", "replacement")
            elif mutation == "namespace":
                receipts[0]["namespace"] = "foreign"
            elif mutation == "credential":
                receipts[0]["authorization_present"] = False
            else:
                receipts[0]["body_fields" if mutation == "body" else "headers"].popitem()
            with self.subTest(mutation=mutation), self.assertRaises(RuntimeError):
                verify_cancellation_transport(pending, receipts)

    def test_rejects_swapped_response_missing_duplicate_and_changed_identity_budget(self):
        for mutation in ("order", "duplicate", "request_id", "requested_at", "cleanup_deadline_at"):
            pending, receipts = transport()
            if mutation == "order":
                receipts.reverse()
            elif mutation == "duplicate":
                receipts[1]["response_duplicate"] = False
            else:
                receipts[1]["response_cancellation_request"][mutation] = "replacement"
            with self.subTest(mutation=mutation), self.assertRaises(RuntimeError):
                verify_cancellation_transport(pending, receipts)


if __name__ == "__main__":
    unittest.main()
