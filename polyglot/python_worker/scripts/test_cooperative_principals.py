"""Reject forged requesters, misleading internal actors and incomplete receipts."""

import copy
import unittest

from principal_gateway import BODY_FIELDS, HEADERS
from sdk_children import CANCELLATION_ACTOR, cancellation_actor, verify_cancellation_transport, verify_cascade_request


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
