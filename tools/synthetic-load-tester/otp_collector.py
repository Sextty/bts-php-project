"""Loopback-only, in-memory OTP and graceful-stop channel for synthetic load tests."""

from __future__ import annotations

import json
import os
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse


TOKEN = os.environ.get("BTS_LOAD_TEST_OTP_COLLECTOR_TOKEN", "")
PORT = int(os.environ.get("BTS_LOAD_TEST_OTP_COLLECTOR_PORT", "8299"))
if len(TOKEN) < 32:
    raise SystemExit("A 32+ character ephemeral collector token is required.")

codes: dict[str, str] = {}
stop_requested = False
lock = threading.Lock()


class Handler(BaseHTTPRequestHandler):
    server_version = "BTSLoadCollector/1"

    def log_message(self, _format: str, *_args: object) -> None:
        return

    def authorized(self) -> bool:
        return self.headers.get("Authorization", "") == f"Bearer {TOKEN}"

    def json_response(self, status: int, payload: dict[str, object]) -> None:
        encoded = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def do_POST(self) -> None:  # noqa: N802
        global stop_requested
        if not self.authorized():
            self.json_response(403, {"ok": False})
            return
        if self.path == "/control/stop":
            with lock:
                stop_requested = True
            self.json_response(200, {"ok": True})
            return
        if self.path != "/otp":
            self.json_response(404, {"ok": False})
            return
        try:
            length = min(int(self.headers.get("Content-Length", "0")), 4096)
            payload = json.loads(self.rfile.read(length))
            phone, code = str(payload["phone"]), str(payload["code"])
            if not phone.startswith("+2161") or not (len(code) == 6 and code.isdigit()):
                raise ValueError("invalid synthetic payload")
            with lock:
                codes[phone] = code
            self.json_response(201, {"ok": True})
        except (KeyError, TypeError, ValueError, json.JSONDecodeError):
            self.json_response(422, {"ok": False})

    def do_GET(self) -> None:  # noqa: N802
        if not self.authorized():
            self.json_response(403, {"ok": False})
            return
        parsed = urlparse(self.path)
        if parsed.path == "/control":
            with lock:
                value = stop_requested
            self.json_response(200, {"stop": value})
            return
        if parsed.path != "/otp":
            self.json_response(404, {"ok": False})
            return
        phone = parse_qs(parsed.query).get("phone", [""])[0]
        with lock:
            code = codes.pop(phone, None)
        self.json_response(200, {"code": code}) if code else self.json_response(404, {"pending": True})


class LoadTestServer(ThreadingHTTPServer):
    request_queue_size = 256
    daemon_threads = True
    allow_reuse_address = True


if __name__ == "__main__":
    LoadTestServer(("127.0.0.1", PORT), Handler).serve_forever()
