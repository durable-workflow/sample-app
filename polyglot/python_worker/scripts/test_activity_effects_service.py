"""Exercise the downstream contract over HTTP, concurrency and actual restart."""

import concurrent.futures
import json
import os
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


class EffectServiceTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.db = Path(self.directory.name, "effects.db")
        self.start()

    def start(self):
        with socket.socket() as reserve:
            reserve.bind(("127.0.0.1", 0))
            port = reserve.getsockname()[1]
        self.url = f"http://127.0.0.1:{port}"
        self.process = subprocess.Popen([sys.executable, "-c",
            "import sys; from http.server import ThreadingHTTPServer; "
            "from activity_effects_service import EffectLedger,handler; "
            "ThreadingHTTPServer(('127.0.0.1',int(sys.argv[1])),handler(EffectLedger(sys.argv[2]))).serve_forever()",
            str(port), str(self.db)], cwd=Path(__file__).parent, env=os.environ,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        until = time.monotonic() + 5
        while time.monotonic() < until:
            try:
                with urlopen(self.url + "/health", timeout=0.2) as response:
                    self.assertEqual(json.load(response), {"ready": True})
                    return
            except (URLError, TimeoutError):
                time.sleep(0.02)
        self.fail("synthetic downstream did not start")

    def stop(self):
        self.process.kill()
        self.process.communicate(timeout=5)
        self.assertEqual(self.process.returncode, -9)

    def tearDown(self):
        if self.process.poll() is None:
            self.stop()
        self.directory.cleanup()

    def post(self, attempt, value=None):
        request = {"operation_key": "fixture-effect", "attempt_id": attempt,
                   "input": value if value is not None else {"units": 37, "note": "café ✓", "tags": [True, None]}}
        try:
            response = urlopen(Request(self.url + "/effects", data=json.dumps(request).encode(),
                                       headers={"Content-Type": "application/json"}), timeout=5)
        except HTTPError as response:
            return response.code, json.load(response)
        with response:
            return response.status, json.load(response)

    def observe(self):
        with urlopen(self.url + "/effects/fixture-effect", timeout=5) as response:
            return json.load(response)

    def test_two_attempts_reuse_one_committed_effect_after_physical_downstream_sigkill(self):
        status, first = self.post("attempt-1")
        self.assertEqual(status, 200)
        self.assertFalse(first["reused"])
        self.stop()
        self.start()
        status, second = self.post("attempt-2")
        self.assertEqual(status, 200)
        self.assertTrue(second["reused"])
        self.assertEqual(first["effect_id"], second["effect_id"])
        self.assertEqual(first["input_sha256"], second["input_sha256"])
        observed = self.observe()
        self.assertEqual(len(observed["effects"]), 1)
        self.assertEqual(observed["deliveries"], [{"attempt_id": "attempt-1", "outcome": "committed"},
                                                  {"attempt_id": "attempt-2", "outcome": "reused"}])

    def test_concurrent_deliveries_commit_once(self):
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            results = list(pool.map(self.post, [f"attempt-{n}" for n in range(8)]))
        self.assertTrue(all(status == 200 for status, _ in results))
        self.assertEqual(len({body["effect_id"] for _, body in results}), 1)
        self.assertEqual(sum(not body["reused"] for _, body in results), 1)
        observed = self.observe()
        self.assertEqual(len(observed["effects"]), 1)
        self.assertEqual(len(observed["deliveries"]), 8)

    def test_same_key_with_different_input_is_refused_without_another_effect(self):
        self.post("attempt-1")
        status, conflict = self.post("attempt-2", {"units": 99})
        self.assertEqual(status, 409)
        self.assertEqual(conflict["reason"], "idempotency_input_conflict")
        observed = self.observe()
        self.assertEqual(len(observed["effects"]), 1)
        self.assertEqual(observed["effects"][0]["input"]["units"], 37)
        self.assertEqual(observed["deliveries"][-1]["outcome"], "conflict")


if __name__ == "__main__":
    unittest.main()
