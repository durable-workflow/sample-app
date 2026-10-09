"""Inject forged attribution into real SDK traffic to the disposable Server."""

from __future__ import annotations

import http.client
import json
import os
import threading
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit

BODY_FIELDS = {"principal": "mallory", "principal_id": "mallory",
               "principal_type": "attacker", "actor": "mallory", "user": "mallory"}
HEADERS = {
    "X-Workflow-Principal-Id": "mallory",
    "X-Workflow-Principal-Type": "attacker",
    "X-Workflow-Principal-Label": "Mallory",
    "X-Workflow-Caller-Type": "spoofed-gateway",
    "X-Workflow-Caller-Label": "Mallory Gateway",
    "X-Workflow-Auth-Status": "trusted_elsewhere",
    "X-Workflow-Auth-Method": "gateway_token",
    "X-Forwarded-User": "mallory",
    "X-Forwarded-Email": "mallory@example.invalid",
    "X-Remote-User": "mallory",
    "Authorization-Override": "Bearer mallory",
}
HOP_HEADERS = {"connection", "content-length", "host", "transfer-encoding", "keep-alive",
               "proxy-authenticate", "proxy-authorization", "te", "trailer", "upgrade"}
RECEIPT_LOCK = threading.Lock()


def mutation_kind(method, path):
    if method != "POST":
        return None
    if path == "/api/workflows":
        return "start"
    if path.startswith("/api/workflows/") and "/signal/" in path:
        return "signal"
    if path.startswith("/api/workflows/") and "/query/" in path:
        return "query"
    if path.startswith("/api/workflows/") and path.endswith("/cancel"):
        return "cancel"
    if path.startswith("/api/worker/workflow-tasks/") and path.endswith("/complete"):
        return "workflow-task-complete"
    if path.startswith("/api/worker/activity-tasks/") and path.endswith("/complete"):
        return "activity-complete"
    return None


class Gateway(BaseHTTPRequestHandler):
    upstream = ("server", 8080)
    receipt_path = None

    def log_message(self, *_):
        # Request headers, including Authorization, never enter logs.
        pass

    def do_GET(self):
        if self.path == "/health":
            self.send_response(200)
            self.end_headers()
            self.wfile.write(b"ready")
        else:
            self.forward()

    def do_POST(self):
        self.forward()

    def forward(self):
        path = urlsplit(self.path).path
        if not path.startswith("/api/"):
            self.send_error(404)
            return
        size = int(self.headers.get("Content-Length", "0"))
        if size < 0 or size > 2 * 1024 * 1024:
            self.send_error(413)
            return
        body = self.rfile.read(size)
        headers = {key: value for key, value in self.headers.items() if key.lower() not in HOP_HEADERS}
        headers.update(HEADERS)
        kind = mutation_kind(self.command, path)
        payload = None
        if kind:
            payload = json.loads(body)
            if not isinstance(payload, dict) or set(BODY_FIELDS) & payload.keys():
                self.send_error(400, "Fixture SDK request unexpectedly supplies identity fields")
                return
            body = json.dumps({**payload, **BODY_FIELDS}).encode()
        connection = http.client.HTTPConnection(*self.upstream, timeout=35)
        try:
            connection.request(self.command, self.path, body=body or None, headers=headers)
            response = connection.getresponse()
            result = response.read()
            if kind and self.receipt_path:
                receipt = {"at": datetime.now(timezone.utc).isoformat(), "kind": kind,
                           "path": path, "namespace": self.headers.get("X-Namespace"),
                           "status": response.status, "body_fields": BODY_FIELDS, "headers": HEADERS,
                           "workflow_id": payload.get("workflow_id"),
                           "activity_attempt_id": payload.get("activity_attempt_id"),
                           "commands": [row.get("type") for row in payload.get("commands", [])]}
                if kind == "query" and response.status == 200:
                    result_payload = json.loads(result)
                    receipt["response_principal"] = result_payload.get("principal")
                    receipt["response_run_id"] = result_payload.get("run_id")
                    receipt["result_envelope"] = result_payload.get("result_envelope")
                with RECEIPT_LOCK, self.receipt_path.open("a") as stream:
                    stream.write(json.dumps(receipt, sort_keys=True) + "\n")
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in HOP_HEADERS:
                    self.send_header(key, value)
            self.send_header("Content-Length", str(len(result)))
            self.end_headers()
            self.wfile.write(result)
        finally:
            connection.close()


if __name__ == "__main__":
    Gateway.receipt_path = Path(os.environ["NAMESPACE_PROOF_DIR"]) / "gateway.jsonl"
    ThreadingHTTPServer(("0.0.0.0", 8083), Gateway).serve_forever()
