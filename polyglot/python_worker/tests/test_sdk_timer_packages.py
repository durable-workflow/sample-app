from __future__ import annotations

import copy
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

import test_sdk_timers as fixtures


timers = fixtures.timers
PREVIOUS = {"php": "2.5.0", "python": "2.5.15", "rust": "3.5.4"}
CURRENT = {"php": "2.5.1", "python": "2.6.0", "rust": "3.5.5"}

FAKE_DOCKER = r'''#!/usr/bin/env python3
import json, os, sys
args = sys.argv[1:]
versions = {runtime: os.environ.get("DURABLE_WORKFLOW_" + runtime.upper() + "_SDK_VERSION")
            for runtime in ("php", "python", "rust")}
record = {"args": args, "versions": versions, "generation": os.environ.get("SDK_TIMERS_PACKAGE_GENERATION"),
          "application": os.environ.get("POLYGLOT_TIMER_APPLICATION"),
          "php_pin": os.environ.get("DURABLE_WORKFLOW_PHP_SDK_PIN"),
          "server": os.environ["DURABLE_SERVER_IMAGE"]}
with open(os.environ["FAKE_DOCKER_LOG"], "a") as stream:
    stream.write(json.dumps(record) + "\n")
if args[:2] == ["image", "inspect"]:
    if args[2] == os.environ["DURABLE_SERVER_IMAGE"]:
        print("[]")
        sys.exit(0)
    sys.exit(1)
if "run" not in args:
    sys.exit(0)
if "/app/scripts/sdk_timers.py" in args:
    print(json.dumps({"phase": args[-2], "scenario": args[-1]}))
elif "php-same-workflow-worker" in args:
    print(json.dumps({"sdk_version": versions["php"], "reference": "constructed"}))
elif "python-workflow-worker" in args:
    print(json.dumps({"sdk_version": versions["python"]}))
elif "rust-workflow-worker" in args:
    version = versions["rust"]
    if os.environ.get("FAKE_WRONG_PACKAGE") and record["generation"] == "previous":
        version = "9.9.9"
    print(json.dumps({"sdk_identity": "durable-workflow-rust/" + version}))
'''


@unittest.skipUnless(shutil.which("bash") and shutil.which("jq"), "runner control requires bash and jq")
class PackageUpgradeRunnerTest(unittest.TestCase):
    def run_control(self, *, wrong_package=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            docker = root / "docker"
            docker.write_text(FAKE_DOCKER)
            docker.chmod(0o755)
            log = root / "commands.jsonl"
            environment = {**os.environ, "PATH": f"{root}:{os.environ['PATH']}",
                "FAKE_DOCKER_LOG": str(log), "SDK_TIMERS_COMPOSE_PROJECT_NAME": "owned-sdk-packages",
                "DURABLE_SERVER_IMAGE": "server:constructed", "DURABLE_WORKFLOW_CLI_VERSION": "0.0.1",
                "DURABLE_WORKFLOW_WORKFLOW_VERSION": "0.0.1", "DURABLE_WORKFLOW_WATERLINE_VERSION": "0.0.1",
                "DURABLE_WORKFLOW_PHP_SDK_PIN": "durable-workflow/sdk:2.5.1@target-reference"}
            for runtime, version in CURRENT.items():
                environment[f"DURABLE_WORKFLOW_{runtime.upper()}_SDK_VERSION"] = version
            if wrong_package:
                environment["FAKE_WRONG_PACKAGE"] = "1"
            result = subprocess.run(["bash", "scripts/sdk-timers.sh", "--sdk-upgrades"],
                cwd=Path(__file__).parents[3], env=environment, capture_output=True, text=True, timeout=15)
            self.assertTrue(log.exists(), result.stderr or result.stdout)
            records = [json.loads(line) for line in log.read_text().splitlines()]
            return result, records

    def test_distinct_packages_are_built_before_admission_and_replacements_keep_application_code(self):
        result, records = self.run_control()
        self.assertEqual(result.returncode, 0, result.stderr)
        builds = [record for record in records if "build" in record["args"]]
        self.assertEqual([record["versions"] for record in builds], [CURRENT, PREVIOUS])
        self.assertEqual([record["generation"] for record in builds], ["current", "previous"])
        self.assertEqual(builds[-1]["php_pin"], f"durable-workflow/sdk:{PREVIOUS['php']}")
        starts = [record for record in records if "/app/scripts/sdk_timers.py" in record["args"]
                  and record["args"][-2] == "start"]
        self.assertEqual([record["args"][-1] for record in starts], [
            "sdk-upgrade", "sdk-upgrade-fresh", "concurrent-sdk-upgrade", "concurrent-sdk-upgrade-fresh"])
        self.assertEqual([record["versions"] for record in starts], [PREVIOUS, CURRENT, PREVIOUS, CURRENT])
        self.assertEqual({record["application"] for record in starts}, {"v2"})
        self.assertEqual({record["server"] for record in records}, {"server:constructed"})
        self.assertTrue(any("down" in record["args"] for record in records))

    def test_mislabelled_previous_package_refuses_before_any_original_and_still_cleans_up(self):
        result, records = self.run_control(wrong_package=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(any("/app/scripts/sdk_timers.py" in record["args"]
                             and record["args"][-2] == "start" for record in records))
        self.assertTrue(any("down" in record["args"] for record in records))


class PackageUpgradeEvidenceTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        environment = patch.dict("os.environ", {
            "DURABLE_WORKFLOW_NAMESPACE": "default", "DURABLE_WORKFLOW_TIMER_ID": "owned-project",
            "DURABLE_WORKFLOW_TIMER_SDK_VERSIONS": json.dumps(CURRENT),
            "DURABLE_WORKFLOW_TIMER_SDK_TARGETS": json.dumps(CURRENT),
            "DURABLE_WORKFLOW_TIMER_SDK_PREVIOUS": json.dumps(PREVIOUS),
            "DURABLE_WORKFLOW_WORKER_STOPPED_AT": "2026-10-05T00:00:10Z",
            "DURABLE_WORKFLOW_WORKER_RESTART_AT": "2026-10-05T00:01:01Z",
        })
        environment.start()
        self.addCleanup(environment.stop)
        self.workers = [fixtures.WorkerRosterEvidenceTest.worker(runtime) for runtime in timers.RUNTIMES]
        for worker in self.workers:
            worker.raw["sdk_version"] = f"durable-workflow-{worker.runtime}/{CURRENT[worker.runtime]}"
            for suffix in ("timer", "concurrent-timers"):
                name = f"polyglot.{worker.runtime}.{suffix}"
                worker.supported_workflow_types.append(name)
                worker.raw["workflow_definition_fingerprints"][name] = "sha256:" + "a" * 64
        self.capture_workers()
        self.case()

    def capture_workers(self):
        self.snapshot = {"phase": "workers_ready", "project": "owned-project", "workers": [
            {**timers.worker_snapshot(worker), "sdk_version": worker.raw.get("sdk_version")}
            for worker in self.workers]}
        self.environment = patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_WORKERS": json.dumps(self.snapshot)})
        self.environment.start()
        self.addCleanup(self.environment.stop)

    def case(self, *, concurrent=False, fresh=False):
        self.fixture = fixtures.ConcurrentTimerEvidenceTest() if concurrent else fixtures.TimerEvidenceTest()
        self.fixture.setUp()
        self.runtime = "python" if concurrent else "rust"
        self.scenario = ("concurrent-" if concurrent else "") + "sdk-upgrade" + ("-fresh" if fresh else "")
        prefix_length = 4 if concurrent else 2
        self.fixture.history["events"][0]["payload"].update(
            input=[self.fixture.execution.workflow_id],
            workflow_definition_fingerprint_source="worker", workflow_definition_fingerprint="sha256:" + "a" * 64)
        self.fixture.original.update(
            history_prefix=copy.deepcopy(self.fixture.history["events"][:prefix_length]),
            sdk_version=(CURRENT if fresh else PREVIOUS)[self.runtime],
            worker_id=f"owned-{self.runtime}" if fresh else f"old-{self.runtime}")

    async def verify(self):
        with patch.object(timers, "observe", fixtures.AsyncMock(return_value=(
                self.fixture.handle, self.fixture.execution, self.fixture.history))), \
                patch.object(timers, "original_pending", return_value=self.fixture.original), \
                patch.object(timers, "emit"):
            await timers.verify(None, self.runtime, self.scenario)

    async def test_same_original_completes_on_new_package_for_both_shapes(self):
        for concurrent in (False, True):
            with self.subTest(concurrent=concurrent):
                self.case(concurrent=concurrent)
                await self.verify()

    async def test_codec_diagnostics_survive_package_replay(self):
        self.fixture.handle.result.return_value["codec"] = {
            "codec": "avro", "framing": "single_object", "implementation": "apache-avro"}
        await self.verify()

    async def test_ready_refuses_a_current_image_label_backed_by_the_wrong_sdk(self):
        self.workers[-1].raw["sdk_version"] = f"durable-workflow-rust/{PREVIOUS['rust']}"
        self.assertIsNone(timers.ready_snapshot(types.SimpleNamespace(workers=self.workers)))

    async def test_ready_refuses_missing_or_non_string_sdk_identity(self):
        for value in (None, 17, True, ""):
            with self.subTest(value=value):
                self.workers[-1].raw["sdk_version"] = value
                self.assertIsNone(timers.ready_snapshot(types.SimpleNamespace(workers=self.workers)))

    async def test_ready_refuses_the_wrong_language_prefix_or_missing_timer_type(self):
        self.workers[-1].raw["sdk_version"] = f"durable-workflow-python/{CURRENT['rust']}"
        self.assertIsNone(timers.ready_snapshot(types.SimpleNamespace(workers=self.workers)))
        self.workers[-1].raw["sdk_version"] = f"durable-workflow-rust/{CURRENT['rust']}"
        self.workers[-1].supported_workflow_types.remove("polyglot.rust.concurrent-timers")
        self.assertIsNone(timers.ready_snapshot(types.SimpleNamespace(workers=self.workers)))

    async def test_replay_must_preserve_the_complete_original_history_prefix(self):
        self.fixture.history["events"][0]["payload"]["input"] = ["rewritten"]
        with self.assertRaisesRegex(RuntimeError, "prefix"):
            await self.verify()

    async def test_an_unchanged_sdk_cannot_be_called_an_upgrade(self):
        self.fixture.original["sdk_version"] = CURRENT[self.runtime]
        with self.assertRaisesRegex(RuntimeError, "SDK"):
            await self.verify()

    async def test_an_unselected_previous_sdk_cannot_pass(self):
        self.fixture.original["sdk_version"] = "0.0.1"
        with self.assertRaisesRegex(RuntimeError, "SDK"):
            await self.verify()

    async def test_upgrade_requires_an_actual_replacement_worker(self):
        self.fixture.original["worker_id"] = f"owned-{self.runtime}"
        with self.assertRaisesRegex(RuntimeError, "worker"):
            await self.verify()

    async def test_missing_original_worker_identity_cannot_pass(self):
        for value in (None, "", True):
            with self.subTest(value=value):
                self.fixture.original["worker_id"] = value
                with self.assertRaisesRegex(RuntimeError, "worker"):
                    await self.verify()

    async def test_worker_replacement_cannot_rebind_the_python_definition(self):
        self.case(concurrent=True)
        self.snapshot["workers"][1]["workflow_definition_fingerprints"][
            "polyglot.python.concurrent-timers"] = "sha256:" + "b" * 64
        with patch.dict("os.environ", {"DURABLE_WORKFLOW_TIMER_WORKERS": json.dumps(self.snapshot)}), \
                self.assertRaisesRegex(RuntimeError, "definition"):
            await self.verify()

    async def test_upgrade_does_not_introduce_an_unrequested_version_command(self):
        self.fixture.history["events"].insert(-1, fixtures.event(
            "VersionMarkerRecorded", sequence=2, version=1))
        with self.assertRaisesRegex(RuntimeError, "marker"):
            await self.verify()

    async def test_result_keeps_integer_types_for_single_and_concurrent_timers(self):
        for concurrent in (False, True):
            with self.subTest(concurrent=concurrent):
                self.case(concurrent=concurrent)
                result = self.fixture.handle.result.return_value
                result["timer_seconds"] = [60.0, 30.0, 45.0] if concurrent else 30.0
                with self.assertRaises(RuntimeError):
                    await self.verify()

    async def test_fresh_controls_use_the_current_package_without_a_fictitious_upgrade(self):
        for concurrent in (False, True):
            with self.subTest(concurrent=concurrent):
                self.case(concurrent=concurrent, fresh=True)
                await self.verify()

    async def test_fresh_control_cannot_run_on_the_previous_sdk(self):
        self.case(fresh=True)
        self.fixture.original["sdk_version"] = PREVIOUS[self.runtime]
        with self.assertRaisesRegex(RuntimeError, "SDK"):
            await self.verify()


if __name__ == "__main__":
    unittest.main()
