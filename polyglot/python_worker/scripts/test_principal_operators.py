"""Reject misleading operator views before comparing real published executions."""

import copy
import unittest

from principal_operators import verify_views


def views(*, anonymous=False, terminal="WorkflowCompleted"):
    operator = {"type": "server", "id": "anonymous", "label": "Admin"} if anonymous else {
        "type": "auth:runtime-token", "id": "fixture-operator", "label": "Operator"}
    worker = operator if anonymous else {"type": "auth:runtime-token", "id": "fixture-worker", "label": "Worker"}
    events = [
        {"sequence": 1, "event_type": "WorkflowStarted", "principal": operator,
         "timestamp": "2026-10-09T00:00:00Z", "payload": {"command": {"id": "start"}}},
        {"sequence": 2, "event_type": "SignalWaitOpened", "principal": None,
         "timestamp": "2026-10-09T00:00:01Z", "payload": {"signal_wait_id": "wait"}},
        {"sequence": 3, "event_type": terminal, "principal": operator if terminal == "WorkflowCancelled" else worker,
         "timestamp": "2026-10-09T00:00:02Z", "payload": {"command": {"id": "cancel"}} if terminal == "WorkflowCancelled" else {}},
    ]
    expected = {"namespace": "default" if anonymous else "rust-namespace-a", "workflow_id": "original-instance",
                "run_id": "original-run", "status": {"WorkflowCompleted": "completed", "WorkflowFailed": "failed",
                                                       "WorkflowCancelled": "cancelled"}[terminal], "history": {"events": events}}
    cli = {"namespace": expected["namespace"], "events": copy.deepcopy(events)}
    waterline = {"engine_source": "service", "namespace": expected["namespace"],
                 "workflow_instance_id": expected["workflow_id"], "run_id": expected["run_id"],
                 "selected_run_id": expected["run_id"], "status": expected["status"], "timeline_truncated": False,
                 "history_next_page_token": None, "timeline": copy.deepcopy(events), "commands": []}
    table = "| # | Event Type | Time | Principal | Details |\n"
    for event in events:
        actor = event["principal"]
        label = f"{actor['label']} ({actor['type']})" if actor else "-"
        table += f"| {event['sequence']} | {event['event_type']} | {event['timestamp']} | {label} | {{}} |\n"
        command = event["payload"].get("command")
        if command:
            waterline["commands"].append({"id": command["id"], "principal_type": actor["type"],
                                           "principal_id": actor["id"], "principal_label": actor["label"]})
    return expected, cli, table, waterline


class OperatorViewChecks(unittest.TestCase):
    def test_named_and_anonymous_terminal_views(self):
        for anonymous in (False, True):
            for terminal in ("WorkflowCompleted", "WorkflowFailed", "WorkflowCancelled"):
                with self.subTest(anonymous=anonymous, terminal=terminal):
                    result = verify_views(*views(anonymous=anonymous, terminal=terminal))
                    self.assertEqual("original-run", result["original_run_id"])

    def test_cli_rejects_wrong_namespace_changed_history_and_missing_actor(self):
        for mutation in ("namespace", "missing-event", "actor", "payload", "timestamp"):
            expected, cli, table, waterline = views()
            if mutation == "namespace":
                cli["namespace"] = "foreign"
            elif mutation == "missing-event":
                cli["events"].pop()
            elif mutation == "actor":
                cli["events"][0]["principal"] = None
            elif mutation == "payload":
                cli["events"][1]["payload"]["signal_wait_id"] = "replacement"
            else:
                cli["events"][0]["timestamp"] = "changed"
            with self.subTest(mutation=mutation), self.assertRaises(RuntimeError):
                verify_views(expected, cli, table, waterline)

    def test_waterline_rejects_wrong_selection_backend_status_and_history_window(self):
        mutations = {"engine_source": "v2", "namespace": "foreign", "workflow_instance_id": "replacement",
                     "run_id": "replacement", "selected_run_id": "replacement", "status": "running",
                     "timeline_truncated": True, "history_next_page_token": "another-page"}
        for key, value in mutations.items():
            expected, cli, table, waterline = views()
            waterline[key] = value
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                verify_views(expected, cli, table, waterline)

    def test_waterline_rejects_missing_history_and_forged_actor(self):
        for mutation in ("missing", "null", "forged"):
            expected, cli, table, waterline = views(anonymous=True)
            if mutation == "missing":
                waterline["timeline"].pop()
            else:
                waterline["timeline"][0]["principal"] = None if mutation == "null" else {
                    "id": "mallory", "type": "auth:runtime-token", "label": "Mallory"}
            with self.subTest(mutation=mutation), self.assertRaises(RuntimeError):
                verify_views(expected, cli, table, waterline)

    def test_waterline_rejects_missing_duplicate_or_misattributed_command(self):
        for mutation in ("missing", "duplicate", "principal_type", "principal_id", "principal_label"):
            expected, cli, table, waterline = views(terminal="WorkflowCancelled")
            if mutation == "missing":
                waterline["commands"].pop()
            elif mutation == "duplicate":
                waterline["commands"].append(copy.deepcopy(waterline["commands"][0]))
            else:
                waterline["commands"][0][mutation] = "forged"
            with self.subTest(mutation=mutation), self.assertRaises(RuntimeError):
                verify_views(expected, cli, table, waterline)

    def test_human_cli_requires_actor_in_correct_sequence_and_column(self):
        expected, cli, table, waterline = views()
        for changed in (table.replace("Principal", "Actor"), table.replace("Operator (auth:runtime-token)", "-"),
                        table.replace("| 1 |", "| 9 |"), table.replace("| Operator (auth:runtime-token) | {} |", "| - | Operator (auth:runtime-token) |")):
            with self.subTest(table=changed), self.assertRaises(RuntimeError):
                verify_views(expected, cli, changed, waterline)


if __name__ == "__main__":
    unittest.main()
