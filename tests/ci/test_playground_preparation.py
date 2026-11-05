import contextlib
import io
import os
import pathlib
import runpy
import sys
import tempfile
import unittest
from unittest.mock import patch


PLAYGROUND = runpy.run_path(str(pathlib.Path(__file__).resolve().parents[2] / "scripts/playground"))
PREPARE = PLAYGROUND["prepare_source"]
GLOBALS = PREPARE.__globals__


class SourcePreparationTests(unittest.TestCase):
    def setUp(self):
        self.output = self.enterContext(contextlib.redirect_stdout(io.StringIO()))

    def test_preparation_uses_caller_source_without_runtime_credentials(self):
        with tempfile.TemporaryDirectory(prefix="sample-app-prepare-") as directory:
            source = pathlib.Path(directory)
            authored = source / "authored.txt"
            authored.write_text("caller-owned")
            command = [sys.executable, "-c", """
import os
from pathlib import Path
assert not any(name in os.environ for name in (
    'DURABLE_WORKFLOW_AUTH_TOKEN', 'DURABLE_WORKFLOW_CLIENT_TOKEN',
    'DURABLE_WORKFLOW_CONTROL_TOKEN', 'DURABLE_WORKFLOW_PROCESS_ROLE',
    'DURABLE_WORKFLOW_PROCESS_TOKEN', 'DURABLE_WORKFLOW_TOKEN',
    'DURABLE_WORKFLOW_WORKER_TOKEN',
))
assert Path('authored.txt').read_text() == 'caller-owned'
Path('prepared.txt').write_text(str(Path.cwd()))
"""]
            ambient = {name: "synthetic" for name in PLAYGROUND["CREDENTIAL_VARIABLES"]}
            with patch.dict(os.environ, ambient):
                PREPARE({"prepare_command": command}, source)
            self.assertEqual((source / "prepared.txt").read_text(), str(source))
            self.assertEqual(authored.read_text(), "caller-owned")

    def test_build_failure_has_its_own_status(self):
        with tempfile.TemporaryDirectory(prefix="sample-app-prepare-") as directory:
            with self.assertRaisesRegex(PLAYGROUND["PlaygroundError"], "Source preparation failed with exit status 17"):
                PREPARE({"prepare_command": [sys.executable, "-c", "raise SystemExit(17)"]}, pathlib.Path(directory))

    def test_timeout_terminates_the_build_process_group(self):
        # Real inert parent/child processes prove cleanup without Cargo or a runtime.
        with tempfile.TemporaryDirectory(prefix="sample-app-prepare-") as directory:
            source = pathlib.Path(directory)
            child = """
import signal, sys, time
from pathlib import Path
def stop(*args):
    Path('child-stopped').write_text('terminated')
    sys.exit(0)
signal.signal(signal.SIGTERM, stop)
Path('child-ready').write_text('ready')
while True:
    time.sleep(0.05)
"""
            parent = """
import signal, subprocess, sys, time
from pathlib import Path
child = subprocess.Popen([sys.executable, '-c', sys.argv[1]])
def stop(*args):
    child.wait(timeout=2)
    sys.exit(0)
signal.signal(signal.SIGTERM, stop)
while not Path('child-ready').exists():
    time.sleep(0.01)
while True:
    time.sleep(0.05)
"""
            with patch.dict(GLOBALS, SOURCE_PREPARATION_TIMEOUT_SECONDS=3):
                with self.assertRaisesRegex(PLAYGROUND["PlaygroundError"], "Source preparation exceeded 3 seconds"):
                    PREPARE({"prepare_command": [sys.executable, "-c", parent, child]}, source)
            self.assertEqual((source / "child-stopped").read_text(), "terminated")

    def test_languages_without_preparation_do_not_start_a_process(self):
        with patch.object(PLAYGROUND["subprocess"], "Popen", side_effect=AssertionError("unexpected preparation")):
            PREPARE({}, pathlib.Path("unused"))


if __name__ == "__main__":
    unittest.main()
