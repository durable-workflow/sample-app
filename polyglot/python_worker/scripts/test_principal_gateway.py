"""Exercise the real forwarding path, not a simulated injection receipt."""

import http.client
import json
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from principal_gateway import BODY_FIELDS, HEADERS, Gateway


class GatewayForwardingChecks(unittest.TestCase):
    def setUp(self):
        self.received = []
        received = self.received

        class Upstream(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_POST(self):
                received.append({"path": self.path, "headers": self.headers,
                                 "body": json.loads(self.rfile.read(int(self.headers["Content-Length"])) )})
                self.send_response(201)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(b'{"upstream": true}')

        self.temp = tempfile.TemporaryDirectory()
        self.upstream = ThreadingHTTPServer(("127.0.0.1", 0), Upstream)
        class TestGateway(Gateway):
            upstream = self.upstream.server_address
            receipt_path = Path(self.temp.name, "gateway.jsonl")

        self.gateway = ThreadingHTTPServer(("127.0.0.1", 0), TestGateway)
        self.threads = [threading.Thread(target=server.serve_forever, daemon=True)
                        for server in (self.upstream, self.gateway)]
        for thread in self.threads:
            thread.start()

    def tearDown(self):
        for server in (self.gateway, self.upstream):
            server.shutdown()
            server.server_close()
        for thread in self.threads:
            thread.join()
        self.temp.cleanup()

    def send(self, path, body, *, anonymous=False):
        connection = http.client.HTTPConnection(*self.gateway.server_address, timeout=3)
        try:
            headers = {"X-Namespace": "default" if anonymous else "rust-namespace-a",
                       "X-Durable-Workflow-Control-Plane-Version": "2", "Content-Type": "application/json"}
            if not anonymous:
                headers["Authorization"] = "Bearer fixture-real-secret"
            connection.request("POST", path, body=json.dumps(body), headers=headers)
            response = connection.getresponse()
            self.assertEqual(response.status, 201)
            self.assertEqual(json.loads(response.read()), {"upstream": True})
        finally:
            connection.close()
        return self.received[-1]

    def test_start_signal_and_worker_payloads_reach_upstream_with_credentials_unchanged(self):
        for path, body in (
            ("/api/workflows", {"workflow_id": "original", "input": {"codec": "avro", "blob": "opaque-input"}}),
            ("/api/workflows/original/signal/namespace-finish", {"input": {"codec": "avro", "blob": "opaque-signal"}}),
            ("/api/worker/workflow-tasks/task/complete", {"lease_owner": "original-worker", "commands": [
                {"type": "complete_workflow", "result": {"codec": "avro", "blob": "opaque-result"}}]}),
            ("/api/worker/activity-tasks/task/complete", {"activity_attempt_id": "original-attempt", "result": {"codec": "avro", "blob": "opaque-activity"}}),
            ("/api/workflows/original/cancel", {"reason": "original-reason", "request_id": "original-request"}),
            ("/api/workflows/original/runs/original-run/request-cancellation", {"reason": "cleanup", "cleanup_timeout_seconds": 30}),
        ):
            actual = self.send(path, body)
            self.assertEqual(actual["path"], path)
            self.assertEqual(actual["body"], {**body, **BODY_FIELDS})
            for key, value in HEADERS.items():
                self.assertEqual(actual["headers"][key], value)
            self.assertEqual(actual["headers"]["Authorization"], "Bearer fixture-real-secret")
            self.assertEqual(actual["headers"]["X-Namespace"], "rust-namespace-a")
            self.assertEqual(actual["headers"]["X-Durable-Workflow-Control-Plane-Version"], "2")
        text = Path(self.temp.name, "gateway.jsonl").read_text()
        self.assertNotIn("fixture-real-secret", text)
        rows = [json.loads(line) for line in text.splitlines()]
        self.assertEqual(len(rows), 6)
        self.assertEqual({row["kind"] for row in rows}, {"start", "signal", "workflow-task-complete", "activity-complete", "cancel", "cooperative-cancel"})
        self.assertTrue(all(row["status"] == 201 for row in rows))
        self.assertTrue(all(row["authorization_present"] is True for row in rows))

    def test_anonymous_request_stays_credential_free_despite_forged_headers(self):
        actual = self.send("/api/workflows", {"workflow_id": "anonymous-original"}, anonymous=True)
        self.assertIsNone(actual["headers"].get("Authorization"))
        self.assertEqual(actual["headers"]["Authorization-Override"], HEADERS["Authorization-Override"])
        receipt = json.loads(Path(self.temp.name, "gateway.jsonl").read_text())
        self.assertIs(receipt["authorization_present"], False)
        self.assertEqual(receipt["namespace"], "default")
        self.assertEqual(receipt["body_fields"], BODY_FIELDS)

    def test_poll_body_is_preserved_and_cannot_stand_in_for_a_mutation(self):
        body = {"worker_id": "worker", "task_queue": "queue", "timeout_seconds": 0}
        actual = self.send("/api/worker/workflow-tasks/poll", body)
        self.assertEqual(actual["body"], body)
        self.assertFalse(Path(self.temp.name, "gateway.jsonl").exists())

    def test_query_receipt_uses_upstream_audit_field_instead_of_application_result(self):
        # The real gateway records query audit metadata only after HTTP 200.
        class QueryUpstream(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_POST(self):
                self.rfile.read(int(self.headers["Content-Length"]))
                payload = {"principal": {"id": "authenticated-actor", "type": "auth:runtime-token"},
                           "run_id": "original-run", "result_envelope": {"codec": "avro", "blob": "opaque-result"},
                           "result": {"principal": {"id": "mallory", "type": "attacker"}}}
                self.send_response(200)
                self.end_headers()
                self.wfile.write(json.dumps(payload).encode())

        backend = ThreadingHTTPServer(("127.0.0.1", 0), QueryUpstream)
        thread = threading.Thread(target=backend.serve_forever, daemon=True)
        thread.start()
        self.gateway.RequestHandlerClass.upstream = backend.server_address
        connection = http.client.HTTPConnection(*self.gateway.server_address, timeout=3)
        try:
            connection.request("POST", "/api/workflows/original/runs/original-run/query/current",
                               body=json.dumps({"input": {"codec": "avro", "blob": "opaque-input"}}),
                               headers={"Content-Type": "application/json", "X-Namespace": "rust-namespace-a"})
            response = connection.getresponse()
            self.assertEqual(response.status, 200)
            payload = json.loads(response.read())
            receipt = json.loads(Path(self.temp.name, "gateway.jsonl").read_text())
            self.assertEqual(receipt["response_principal"], payload["principal"])
            self.assertNotEqual(receipt["response_principal"], payload["result"]["principal"])
            self.assertEqual(receipt["response_run_id"], "original-run")
            self.assertIs(receipt["authorization_present"], False)
            self.assertEqual(receipt["result_envelope"], payload["result_envelope"])
        finally:
            connection.close()
            backend.shutdown()
            backend.server_close()
            thread.join()


if __name__ == "__main__":
    unittest.main()
