"""Regression tests for the desktop connection workflow (including the former raw 404)."""

from __future__ import annotations

import importlib.util
import io
import json
import sys
import unittest
from pathlib import Path
from urllib.error import HTTPError, URLError


GUI_PATH = Path(__file__).resolve().parents[1] / "gui.py"
SPEC = importlib.util.spec_from_file_location("bts_load_tester_gui", GUI_PATH)
assert SPEC and SPEC.loader
GUI = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = GUI
SPEC.loader.exec_module(GUI)


class Response(io.BytesIO):
    def __init__(self, payload: dict[str, object], status: int = 200) -> None:
        super().__init__(json.dumps(payload).encode("utf-8"))
        self.status = status

    def __enter__(self) -> "Response":
        return self

    def __exit__(self, *_args: object) -> None:
        self.close()


def route_opener(routes: dict[str, tuple[int, dict[str, object]]]):
    def open_url(url: str, timeout: float = 0) -> Response:
        del timeout
        status, payload = routes[url]
        if status >= 400:
            raise HTTPError(url, status, "Not Found", {}, io.BytesIO(json.dumps(payload).encode("utf-8")))
        return Response(payload, status)

    return open_url


class ConnectionWorkflowTest(unittest.TestCase):
    base = "http://127.0.0.1:8200"
    live = {"success": True, "data": {"status": "ok"}}
    health = {"success": True, "data": {"status": "ok", "checks": {"database": {"ok": True}}}}

    def test_backend_down_has_clean_message(self) -> None:
        def unavailable(_url: str, timeout: float = 0):
            del timeout
            raise URLError("connection refused")

        report = GUI.inspect_connection(self.base, opener=unavailable)
        self.assertEqual("backend_unavailable", report.state)
        self.assertEqual("Backend inaccessible", report.title)
        self.assertNotIn("HTTP Error", report.message)

    def test_backend_up_without_isolated_environment_treats_404_as_expected_state(self) -> None:
        opener = route_opener({
            f"{self.base}/api/health/live": (200, self.live),
            f"{self.base}/api/health": (200, self.health),
            f"{self.base}/api/load-test/status": (404, {}),
        })
        report = GUI.inspect_connection(self.base, opener=opener)
        self.assertEqual("environment_not_ready", report.state)
        self.assertIn("aucune base de charge isolée", report.message)
        self.assertNotIn("HTTP Error 404", report.message)

    def test_active_environment_is_ready_and_can_enable_start(self) -> None:
        ready = {
            "success": True,
            "data": {
                "marker": "SYNTHETIC_LOAD_TEST_ONLY",
                "database": "bts_load_20260825010101_1234",
                "synthetic_accounts": 5,
                "health": {"checks": {
                    "database": {"ok": True},
                    "queue_worker": {"ok": True},
                    "reverb": {"ok": True},
                }},
            },
        }
        opener = route_opener({
            f"{self.base}/api/health/live": (200, self.live),
            f"{self.base}/api/health": (200, self.health),
            f"{self.base}/api/load-test/status": (200, ready),
        })
        report = GUI.inspect_connection(self.base, opener=opener)
        self.assertEqual("ready", report.state)
        self.assertEqual((True, "Environnement synthétique vérifié."), report.ready_for("full"))

    def test_wrong_backend_url_fails_cleanly(self) -> None:
        report = GUI.inspect_connection("https://production.example.com")
        self.assertEqual("invalid_url", report.state)
        self.assertIn("127.0.0.1", report.message)

    def test_stale_or_missing_liveness_route_is_reported_as_incompatible(self) -> None:
        opener = route_opener({f"{self.base}/api/health/live": (404, {})})
        report = GUI.inspect_connection(self.base, opener=opener)
        self.assertEqual("incompatible", report.state)
        self.assertIn("liveness", report.message)
        self.assertNotIn("HTTP Error", report.message)


class RuntimePortTest(unittest.TestCase):
    def test_uses_historical_ports_when_they_are_free(self) -> None:
        ports = GUI.select_runtime_ports("http://127.0.0.1:8200", lambda _port: True)

        self.assertEqual(GUI.RuntimePorts(collector=8299, reverb=6201, worker_base=8210), ports)

    def test_moves_occupied_auxiliary_ports_without_changing_backend(self) -> None:
        occupied = {6201, 8210, 8211, 8212, 8213, 8214, 8299}
        ports = GUI.select_runtime_ports("http://127.0.0.1:8200", lambda port: port not in occupied)

        self.assertEqual(8310, ports.worker_base)
        self.assertEqual(8300, ports.collector)
        self.assertEqual(6202, ports.reverb)
        selected = {ports.collector, ports.reverb, *range(ports.worker_base, ports.worker_base + 5)}
        self.assertNotIn(8200, selected)


if __name__ == "__main__":
    unittest.main()
