"""Small loopback reverse proxy so Windows PHP built-in servers can receive concurrent load."""

from __future__ import annotations

import http.client
import itertools
import os
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


PORT = int(os.environ.get("BTS_LOAD_PROXY_PORT", "8200"))
TARGETS = [int(item) for item in os.environ.get("BTS_LOAD_PROXY_TARGETS", "8210,8211").split(",") if item]
if not TARGETS or not (1024 <= PORT <= 65535):
    raise SystemExit("Invalid loopback proxy configuration.")

counter = itertools.count()
counter_lock = threading.Lock()
HOP_BY_HOP = {"connection", "keep-alive", "proxy-authenticate", "proxy-authorization", "te", "trailers", "transfer-encoding", "upgrade"}


class Proxy(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "BTSLoadProxy/1"

    def log_message(self, _format: str, *_args: object) -> None:
        return

    def route(self) -> None:
        with counter_lock:
            target_port = TARGETS[next(counter) % len(TARGETS)]
        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length) if length else None
        headers = {key: value for key, value in self.headers.items() if key.lower() not in HOP_BY_HOP and key.lower() != "host"}
        headers["Host"] = f"127.0.0.1:{target_port}"
        headers["X-Forwarded-For"] = "127.0.0.1"
        connection = http.client.HTTPConnection("127.0.0.1", target_port, timeout=90)
        try:
            connection.request(self.command, self.path, body=body, headers=headers)
            response = connection.getresponse()
            payload = response.read()
            self.send_response(response.status, response.reason)
            for key, value in response.getheaders():
                if key.lower() not in HOP_BY_HOP and key.lower() != "content-length":
                    self.send_header(key, value)
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)
        except (ConnectionError, TimeoutError, OSError):
            payload = b'{"success":false,"error":{"code":"LOAD_PROXY_UPSTREAM","message":"Synthetic test upstream unavailable."}}'
            self.send_response(502)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)
        finally:
            connection.close()

    do_GET = route
    do_POST = route
    do_PUT = route
    do_PATCH = route
    do_DELETE = route
    do_OPTIONS = route


class LoadThreadingHTTPServer(ThreadingHTTPServer):
    # Python's default socket backlog is only five. A deliberate burst of 20+
    # connections would otherwise be rejected by the harness before Laravel
    # or MariaDB ever sees it, producing false load-test failures.
    request_queue_size = 256
    daemon_threads = True
    allow_reuse_address = True


if __name__ == "__main__":
    LoadThreadingHTTPServer(("127.0.0.1", PORT), Proxy).serve_forever()
