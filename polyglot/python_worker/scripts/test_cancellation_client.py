"""Exercise published Python discovery errors through a real HTTP connection."""

import contextlib
import io
import json
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest.mock import patch

from cancellation_client import main
from durable_workflow.errors import RuntimeDiscoveryUnavailable, ServerError


class CancellationClientChecks(unittest.IsolatedAsyncioTestCase):
    async def exercise(self, status):
        requests = []

        class Discovery(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_GET(self):
                requests.append((self.command, self.path, self.headers.get("Authorization")))
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(json.dumps({"reason": "unauthorized" if status == 401 else "forbidden",
                                            "message": "fixture discovery refusal"}).encode())

        server = ThreadingHTTPServer(("127.0.0.1", 0), Discovery)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        output = io.StringIO()
        environment = {"DURABLE_WORKFLOW_SERVER_URL": f"http://127.0.0.1:{server.server_port}",
                       "DURABLE_WORKFLOW_AUTH_TOKEN": "", "DURABLE_WORKFLOW_NAMESPACE": "default",
                       "DURABLE_WORKFLOW_CANCELLATION_PHASE": "deny-anonymous",
                       "DURABLE_WORKFLOW_CANCELLATION_RUNS": json.dumps([{"parent": "python", "child": "rust",
                           "parent_workflow_id": "original", "parent_run_id": "original-run"}])}
        try:
            with patch.dict("os.environ", environment), contextlib.redirect_stdout(output):
                await main()
            return json.loads(output.getvalue()), requests
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    async def test_missing_credentials_preserve_the_typed_discovery_cause(self):
        record, requests = await self.exercise(401)
        self.assertEqual({"status": 401, "reason": "unauthorized"}, record["refusal"])
        self.assertEqual("RuntimeDiscoveryUnavailable", record["sdk_exception"])
        self.assertEqual("Unauthorized", record["discovery_cause"])
        self.assertEqual([("GET", "/api/cluster/info", None)], requests)

    async def test_another_discovery_failure_is_not_counted_as_missing_credentials(self):
        with self.assertRaises(RuntimeDiscoveryUnavailable) as caught:
            await self.exercise(403)
        self.assertIsInstance(caught.exception.cause, ServerError)
        self.assertEqual(403, caught.exception.cause.status)


if __name__ == "__main__":
    unittest.main()
