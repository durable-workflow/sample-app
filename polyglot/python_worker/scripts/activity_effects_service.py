"""Synthetic downstream ledger with transactional application idempotency.

The Workflow Server cannot fence an arbitrary external service. This fixture
owns the idempotency contract independently and persists it in ordinary SQLite.
It accepts only synthetic effects and runs on the isolated qualification network.
"""

import hashlib
import json
import os
import re
import sqlite3
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path


class EffectLedger:
    def __init__(self, path):
        self.path = path
        with self.connect() as connection:
            connection.executescript("""
                CREATE TABLE IF NOT EXISTS effects (
                    effect_id INTEGER PRIMARY KEY,
                    operation_key TEXT NOT NULL UNIQUE,
                    input_sha256 TEXT NOT NULL,
                    input_json TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS deliveries (
                    delivery_id INTEGER PRIMARY KEY,
                    operation_key TEXT NOT NULL,
                    attempt_id TEXT NOT NULL,
                    outcome TEXT NOT NULL
                );
            """)

    def connect(self):
        connection = sqlite3.connect(self.path, timeout=5)
        connection.row_factory = sqlite3.Row
        return connection

    def apply(self, request):
        if (not isinstance(request, dict) or set(request) != {"operation_key", "attempt_id", "input"}
                or not isinstance(request["operation_key"], str)
                or re.fullmatch(r"[a-zA-Z0-9_.:-]{1,240}", request["operation_key"]) is None
                or not isinstance(request["attempt_id"], str) or not 1 <= len(request["attempt_id"]) <= 128):
            raise ValueError("invalid synthetic effect request")
        canonical = json.dumps(request["input"], sort_keys=True, separators=(",", ":"),
                               ensure_ascii=False, allow_nan=False)
        digest = hashlib.sha256(canonical.encode()).hexdigest()
        with self.connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            effect = connection.execute("SELECT * FROM effects WHERE operation_key = ?",
                                        (request["operation_key"],)).fetchone()
            if effect is not None and effect["input_sha256"] != digest:
                connection.execute("INSERT INTO deliveries (operation_key, attempt_id, outcome) VALUES (?, ?, 'conflict')",
                                   (request["operation_key"], request["attempt_id"]))
                return 409, {"reason": "idempotency_input_conflict", "effect_id": effect["effect_id"]}
            duplicate = effect is not None
            if effect is None:
                cursor = connection.execute("INSERT INTO effects (operation_key, input_sha256, input_json) VALUES (?, ?, ?)",
                                            (request["operation_key"], digest, canonical))
                effect_id = cursor.lastrowid
            else:
                effect_id = effect["effect_id"]
            connection.execute("INSERT INTO deliveries (operation_key, attempt_id, outcome) VALUES (?, ?, ?)",
                               (request["operation_key"], request["attempt_id"], "reused" if duplicate else "committed"))
            return 200, {"operation_key": request["operation_key"], "effect_id": effect_id,
                         "input_sha256": digest, "reused": duplicate}

    def observe(self, key):
        with self.connect() as connection:
            effects = [dict(row) for row in connection.execute("SELECT * FROM effects WHERE operation_key = ?", (key,))]
            deliveries = [dict(row) for row in connection.execute(
                "SELECT attempt_id, outcome FROM deliveries WHERE operation_key = ? ORDER BY delivery_id", (key,))]
        for effect in effects:
            effect["input"] = json.loads(effect.pop("input_json"))
        return {"operation_key": key, "effects": effects, "deliveries": deliveries}


def handler(ledger):
    class Handler(BaseHTTPRequestHandler):
        def reply(self, status, body):
            data = json.dumps(body, allow_nan=False).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        def do_GET(self):
            if self.path == "/health":
                self.reply(200, {"ready": True})
            elif self.path.startswith("/effects/"):
                self.reply(200, ledger.observe(self.path.removeprefix("/effects/")))
            else:
                self.reply(404, {"reason": "not_found"})

        def do_POST(self):
            if self.path != "/effects":
                self.reply(404, {"reason": "not_found"})
                return
            try:
                length = int(self.headers.get("Content-Length", "0"))
                if not 1 <= length <= 16384:
                    raise ValueError("invalid request length")
                request = json.loads(self.rfile.read(length), parse_constant=lambda value: (_ for _ in ()).throw(ValueError(value)))
                status, body = ledger.apply(request)
            except (ValueError, TypeError):
                self.reply(422, {"reason": "invalid_effect_request"})
                return
            self.reply(status, body)

        def log_message(self, format, *args):
            pass

    return Handler


if __name__ == "__main__":
    path = Path(os.environ["ACTIVITY_EFFECTS_DB"])
    path.parent.mkdir(parents=True, exist_ok=True)
    ThreadingHTTPServer(("0.0.0.0", 8080), handler(EffectLedger(path))).serve_forever()
