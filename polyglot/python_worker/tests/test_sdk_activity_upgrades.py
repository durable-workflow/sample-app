import copy
import importlib.util
import json
import os
from pathlib import Path
import types
import unittest
from unittest.mock import patch

path = Path(__file__).parents[1] / "scripts" / "sdk_activity_upgrades.py"
spec = importlib.util.spec_from_file_location("activity_upgrades", path)
upgrades = importlib.util.module_from_spec(spec)
spec.loader.exec_module(upgrades)

TARGETS = {"php": "2.5.1", "python": "2.6.0", "rust": "3.5.5"}


def event(kind, payload, seconds=0):
    return {"event_type": kind, "payload": payload, "timestamp": f"2026-10-19T00:00:{seconds:02d}+00:00"}


class ActivityUpgradeEvidenceTest(unittest.TestCase):
    def case(self, workflow="python", activity="rust", version=1):
        self.original = {"workflow_runtime": workflow, "activity_runtime": activity, "version": version,
                         "workflow_id": f"original-{workflow}-{activity}", "run_id": "original-run",
                         "worker_id": "previous-worker"}
        activity_result = {"activity_runtime": activity, "application_revision": "v2",
            "sdk_version": f"durable-workflow-{activity}/{TARGETS[activity]}", "attempt_number": 1,
            "request": {"case_id": self.original["workflow_id"], "version": version, "value": copy.deepcopy(upgrades.VALUE)}}
        self.result = {"workflow_runtime": workflow, "application_revision": "v2", "version": version,
                       "activity": activity_result}
        policy = {"max_attempts": version + 1, "backoff_seconds": [1] if version == 1 else [1, 2],
                  "start_to_close_timeout": 30, "schedule_to_close_timeout": 120 if version == 1 else 180}
        scheduled = {"activity_execution_id": "original-activity", "sequence": 2,
            "activity_type": f"polyglot.{activity}.upgrade-activity",
            "activity": {"id": "original-activity", "queue": f"polyglot-{activity}", "retry_policy": policy}}
        prefix = [event("WorkflowStarted", {"workflow_definition_fingerprint": "original-definition",
                                            "workflow_definition_fingerprint_source": "worker"}),
                  event("VersionMarkerRecorded", {"change_id": "sample-activity-application", "sequence": 1,
                        "version": version, "min_supported": 1, "max_supported": version}),
                  event("ActivityScheduled", scheduled)]
        self.original.update(scheduled=copy.deepcopy(scheduled), history_prefix=copy.deepcopy(prefix))
        attempt = {"activity_execution_id": "original-activity", "activity_attempt_id": "first-attempt", "attempt_number": 1}
        self.history = {"events": [*prefix, event("ActivityStarted", attempt, 10),
            event("ActivityCompleted", {**attempt, "result": copy.deepcopy(activity_result)}, 11),
            event("WorkflowCompleted", {"output": copy.deepcopy(self.result)}, 12)]}
        self.execution = types.SimpleNamespace(workflow_id=self.original["workflow_id"], run_id="original-run", status="completed")
        self.workers = [{"runtime": language, "worker_id": f"current-{language}",
                         "sdk_version": f"durable-workflow-{language}/{TARGETS[language]}",
                         "workflow_definition_fingerprints": {"polyglot.python.activity-upgrade": "original-definition"}}
                        for language in upgrades.RUNTIMES]

    def verify(self):
        upgrades.check_completed(self.original, self.execution, self.history, self.result,
                                 self.workers, TARGETS, "2026-10-19T00:00:09Z", lambda value: value)

    def test_all_nine_directions_accept_old_branch_and_fresh_current_branch(self):
        for workflow, activity in upgrades.DIRECTIONS:
            for version in (1, 2):
                with self.subTest(workflow=workflow, activity=activity, version=version):
                    self.case(workflow, activity, version)
                    self.verify()

    def test_rewritten_original_prefix_is_rejected_even_with_same_schedule(self):
        self.case()
        self.history["events"][0]["payload"]["workflow_definition_fingerprint_source"] = "replacement"
        with self.assertRaisesRegex(RuntimeError, "history prefix"):
            self.verify()

    def test_changed_marker_bounds_and_rebased_command_sequence_refuse(self):
        for event_index, field, value in ((1, "max_supported", 2), (1, "sequence", 7),
                                         (1, "version", True), (2, "sequence", 3)):
            with self.subTest(field=field, value=value):
                self.case()
                self.history["events"][event_index]["payload"][field] = value
                # Also replace the captured prefix to test the independent command contract.
                self.original["history_prefix"] = copy.deepcopy(self.history["events"][:3])
                with self.assertRaises(RuntimeError):
                    self.verify()

    def test_every_authored_policy_field_remains_checked(self):
        for field, value in (("max_attempts", 3), ("backoff_seconds", [1, 2]),
                             ("start_to_close_timeout", 60), ("schedule_to_close_timeout", 180)):
            with self.subTest(field=field):
                self.case()
                self.history["events"][2]["payload"]["activity"]["retry_policy"][field] = value
                self.original["history_prefix"] = copy.deepcopy(self.history["events"][:3])
                with self.assertRaisesRegex(RuntimeError, "policy"):
                    self.verify()

    def test_changed_original_run_attempt_or_worker_is_rejected(self):
        for field in ("run", "execution", "attempt", "worker", "package", "definition"):
            with self.subTest(field=field):
                self.case()
                if field == "run":
                    self.execution.run_id = "another-run"
                elif field == "execution":
                    self.history["events"][4]["payload"]["activity_execution_id"] = "another-activity"
                elif field == "attempt":
                    self.history["events"][4]["payload"]["activity_attempt_id"] = "another-attempt"
                elif field == "worker":
                    self.workers[1]["worker_id"] = self.original["worker_id"]
                elif field == "package":
                    self.workers[1]["sdk_version"] = "durable-workflow-python/2.5.15"
                else:
                    self.workers[1]["workflow_definition_fingerprints"]["polyglot.python.activity-upgrade"] = "another-definition"
                with self.assertRaises(RuntimeError):
                    self.verify()

    def test_early_activity_or_completion_beyond_original_budget_refuses(self):
        for value in ("2026-10-19T00:00:08Z", "2026-10-19T00:03:00Z"):
            self.case()
            self.history["events"][3 if value.endswith("08Z") else 4]["timestamp"] = value
            with self.assertRaises(RuntimeError):
                self.verify()

    def test_scalar_substitutions_do_not_pass_python_equality(self):
        for field, value in (("bigint", float(upgrades.VALUE["bigint"])), ("ready", 1),
                             ("optional", "null"), ("fraction", 1), ("items", [0, 0, None, "017"])):
            with self.subTest(field=field):
                self.case()
                self.result["activity"]["request"]["value"][field] = value
                with self.assertRaisesRegex(RuntimeError, "typed"):
                    self.verify()

    def test_changed_durable_result_duplicate_completion_and_unexpected_retry_refuse(self):
        for kind in ("result", "completion", "retry"):
            self.case()
            if kind == "result":
                self.history["events"][4]["payload"]["result"]["sdk_version"] = "old-package"
            elif kind == "completion":
                self.history["events"].append(copy.deepcopy(self.history["events"][-1]))
            else:
                self.history["events"].append(event("ActivityRetryScheduled", {}, 9))
            with self.assertRaises(RuntimeError):
                self.verify()

    def test_direction_set_requires_nine_distinct_originals(self):
        records = [{"phase": "pending", "scenario": "activity-upgrade", "workflow_runtime": workflow,
                    "activity_runtime": activity, "run_id": f"run-{workflow}-{activity}"}
                   for workflow, activity in upgrades.DIRECTIONS]
        with patch.dict(os.environ, {"DURABLE_WORKFLOW_TIMER_PENDING": "\n".join(map(json.dumps, records))}):
            self.assertEqual(len(upgrades.original_records("activity-upgrade")), 9)
        for changed in (records[:-1], records[:-1] + [records[0]], [*records, records[0]]):
            with patch.dict(os.environ, {"DURABLE_WORKFLOW_TIMER_PENDING": "\n".join(map(json.dumps, changed))}):
                with self.assertRaises(RuntimeError):
                    upgrades.original_records("activity-upgrade")

    def roster_case(self, current):
        selected = TARGETS if current else {runtime: "1.0.0" for runtime in upgrades.RUNTIMES}
        workers = [types.SimpleNamespace(runtime=runtime,
            supported_workflow_types=[f"polyglot.{runtime}.activity-upgrade"],
            supported_activity_types=[f"polyglot.{runtime}.upgrade-activity"] if current else [],
            raw={"sdk_version": f"durable-workflow-{runtime}/{selected[runtime]}"})
            for runtime in upgrades.RUNTIMES]
        common = types.SimpleNamespace(
            sdk_versions=lambda key: TARGETS,
            active_workers=lambda roster, runtime: [worker for worker in roster if worker.runtime == runtime],
            worker_snapshot=lambda worker: {"runtime": worker.runtime})
        return workers, common, selected

    def test_previous_roster_cannot_dispatch_activities_and_current_roster_must_dispatch_them(self):
        for current in (False, True):
            with self.subTest(current=current):
                workers, common, selected = self.roster_case(current)
                self.assertEqual(len(upgrades.ready_snapshot(workers, common, selected)), 3)
                workers[0].supported_activity_types = [] if current else ["polyglot.php.upgrade-activity"]
                self.assertIsNone(upgrades.ready_snapshot(workers, common, selected))

    def test_wrong_installed_package_or_missing_workflow_refuses_roster_readiness(self):
        for wrong in ("package", "workflow", "absent"):
            with self.subTest(wrong=wrong):
                workers, common, selected = self.roster_case(True)
                if wrong == "package":
                    workers[0].raw["sdk_version"] = "durable-workflow-php/1.0.0"
                elif wrong == "workflow":
                    workers[0].supported_workflow_types = []
                else:
                    workers.pop()
                self.assertIsNone(upgrades.ready_snapshot(workers, common, selected))

    def test_ambiguous_registered_workers_are_refused(self):
        workers, common, selected = self.roster_case(True)
        with self.assertRaisesRegex(RuntimeError, "Ambiguous"):
            upgrades.ready_snapshot([*workers, workers[0]], common, selected)


if __name__ == "__main__":
    unittest.main()
