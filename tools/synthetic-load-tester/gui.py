"""BTS Bank synthetic load tester desktop UI.

The GUI orchestrates the existing PowerShell/k6 runner. It never creates or drops a
database itself: Phase 5's signed marker and strict database-name guards remain the
only authority for environment activation and cleanup.
"""

from __future__ import annotations

import json
import os
import queue
import re
import secrets
import socket
import statistics
import subprocess
import tempfile
import threading
import time
import tkinter as tk
from dataclasses import dataclass, field
from pathlib import Path
from tkinter import messagebox, ttk
from typing import Callable
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit, urlunsplit
from urllib.request import Request, urlopen


ROOT = Path(__file__).resolve().parent
MARKER = "SYNTHETIC_LOAD_TEST_ONLY"
PRESETS = {"Smoke": (5, 5), "5": (5, 5), "10": (10, 10), "20": (20, 20), "30": (30, 30), "50": (50, 50), "100": (100, 20), "500": (500, 50), "Custom": (20, 10)}
SECRET_PATTERN = re.compile(r"(?i)(password|otp|token|secret|authorization)([\"']?\s*[:=]\s*[\"']?)([^\s,;\"']+)")
COLORS = {
    "bg": "#F4F6F8", "surface": "#FFFFFF", "navy": "#0C1825", "slate": "#3D5166",
    "muted": "#6B7888", "border": "#E0E4E9", "red": "#C0272D", "red_dark": "#9E1F25",
    "red_soft": "#FDF2F2", "green": "#138A5B", "amber": "#A86600", "console": "#0A1420",
}


@dataclass(frozen=True)
class ConnectionReport:
    state: str
    title: str
    message: str
    technical: str = ""
    services: dict[str, tuple[bool | None, str]] = field(default_factory=dict)
    payload: dict[str, object] = field(default_factory=dict)

    def ready_for(self, scenario: str) -> tuple[bool, str]:
        if self.state != "ready":
            return False, self.message
        required = ["backend", "mariadb", "database", "worker", "accounts"]
        if scenario in {"chat", "full"}:
            required.append("reverb")
        missing = [name for name in required if self.services.get(name, (False, ""))[0] is not True]
        if missing:
            return False, "Services requis indisponibles : " + ", ".join(missing)
        return True, "Environnement synthétique vérifié."


@dataclass(frozen=True)
class RuntimePorts:
    collector: int
    reverb: int
    worker_base: int


def is_loopback_port_available(port: int) -> bool:
    """Return whether a TCP port can be reserved on the IPv4 loopback adapter."""
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
            probe.bind(("127.0.0.1", port))
        return True
    except OSError:
        return False


def select_runtime_ports(
    backend_url: str,
    available: Callable[[int], bool] = is_loopback_port_available,
) -> RuntimePorts:
    """Choose a non-overlapping local port set without stopping existing services."""
    backend_port = urlsplit(normalize_backend_url(backend_url)).port
    assert backend_port is not None
    reserved = {backend_port}

    def choose(candidates: list[int]) -> int:
        for candidate in candidates:
            if candidate not in reserved and available(candidate):
                reserved.add(candidate)
                return candidate
        raise RuntimeError("Aucun port local libre n’est disponible pour préparer le test.")

    # Five contiguous API workers are required by the reverse proxy. Prefer the
    # historical block, then move by blocks of ten to keep diagnostics readable.
    worker_base = 0
    for candidate in [8210, *range(8310, 65000, 10)]:
        block = set(range(candidate, candidate + 5))
        if not block.intersection(reserved) and all(available(port) for port in block):
            worker_base = candidate
            reserved.update(block)
            break
    if not worker_base:
        raise RuntimeError("Aucun bloc de cinq ports locaux libres n’est disponible pour les workers API.")

    collector = choose([8299, *range(8300, 65000)])
    reverb = choose([6201, *range(6202, 65000)])
    return RuntimePorts(collector=collector, reverb=reverb, worker_base=worker_base)


def normalize_backend_url(value: str) -> str:
    """Normalize only loopback targets accepted by the Phase 5 runner."""
    parsed = urlsplit(value.strip().rstrip("/"))
    if parsed.scheme != "http" or parsed.hostname not in {"127.0.0.1", "localhost"}:
        raise ValueError("L’URL doit utiliser HTTP et cibler 127.0.0.1 ou localhost.")
    if parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise ValueError("L’URL ne doit contenir ni identifiants, ni paramètres, ni fragment.")
    try:
        port = parsed.port
    except ValueError as exc:
        raise ValueError("Le port du backend est invalide.") from exc
    if port is None or not 1024 <= port <= 65535:
        raise ValueError("Indiquez un port local entre 1024 et 65535.")
    path = parsed.path.rstrip("/")
    if path == "/api":
        path = ""
    if path:
        raise ValueError("Utilisez l’URL racine du backend, sans chemin supplémentaire.")
    return urlunsplit(("http", f"{parsed.hostname}:{port}", "", "", ""))


def _read_json(url: str, opener: Callable[..., object], timeout: float) -> tuple[int, dict[str, object], str]:
    try:
        with opener(url, timeout=timeout) as response:  # type: ignore[attr-defined]
            payload = json.load(response)
            return int(getattr(response, "status", 200)), payload if isinstance(payload, dict) else {}, ""
    except HTTPError as exc:
        detail = f"HTTP {exc.code} sur {url}"
        try:
            payload = json.loads(exc.read().decode("utf-8"))
        except (json.JSONDecodeError, UnicodeDecodeError):
            payload = {}
        return exc.code, payload if isinstance(payload, dict) else {}, detail


def inspect_connection(backend_url: str, opener: Callable[..., object] = urlopen, timeout: float = 3) -> ConnectionReport:
    """Probe liveness, readiness, then the signed load environment marker."""
    try:
        base = normalize_backend_url(backend_url)
    except ValueError as exc:
        return ConnectionReport("invalid_url", "URL backend invalide", str(exc), str(exc))

    try:
        live_status, live_payload, live_detail = _read_json(f"{base}/api/health/live", opener, timeout)
    except (URLError, OSError, TimeoutError) as exc:
        return ConnectionReport("backend_unavailable", "Backend inaccessible", f"Impossible de joindre l’API BTS Bank sur {base}. Si l’environnement n’est pas encore préparé, cliquez sur « Préparer l’environnement ».", repr(exc), {"backend": (False, "Inaccessible")})
    live_data = live_payload.get("data", {})
    if live_status != 200 or not isinstance(live_data, dict) or live_data.get("status") != "ok":
        return ConnectionReport("incompatible", "Backend incompatible", "Le backend répond, mais la route BTS de liveness est absente ou incompatible.", live_detail or f"Réponse liveness: HTTP {live_status}", {"backend": (True, "Connecté")})

    readiness: dict[str, object] = {}
    readiness_status = 0
    readiness_detail = ""
    try:
        readiness_status, readiness_payload, readiness_detail = _read_json(f"{base}/api/health", opener, timeout)
        candidate = readiness_payload.get("data", {})
        readiness = candidate if isinstance(candidate, dict) else {}
    except (URLError, OSError, TimeoutError) as exc:
        readiness_detail = repr(exc)

    try:
        load_status, load_payload, load_detail = _read_json(f"{base}/api/load-test/status", opener, timeout)
    except (URLError, OSError, TimeoutError) as exc:
        return ConnectionReport("backend_unavailable", "Backend inaccessible", "La connexion au backend a été interrompue pendant la vérification.", repr(exc), {"backend": (False, "Connexion perdue")})

    checks = readiness.get("checks", {}) if isinstance(readiness.get("checks"), dict) else {}
    database_check = checks.get("database", {}) if isinstance(checks.get("database"), dict) else {}
    db_ok = bool(database_check.get("ok")) if database_check else None
    services: dict[str, tuple[bool | None, str]] = {
        "backend": (True, "Connecté"),
        "mariadb": (db_ok, "Connectée" if db_ok else "Non vérifiée" if db_ok is None else "Indisponible"),
    }
    if load_status == 404:
        return ConnectionReport("environment_not_ready", "Environnement synthétique non préparé", "L’API BTS Bank est accessible, mais aucune base de charge isolée n’est active. Cette URL est déjà occupée par un backend non isolé; choisissez un port loopback libre (8200 recommandé), puis cliquez sur « Préparer l’environnement ».", load_detail or "Le marqueur synthétique protégé a répondu 404, comme prévu hors environnement de charge.", services)
    if load_status != 200:
        return ConnectionReport("incompatible", "Route de charge incompatible", "Le backend est accessible, mais son endpoint de charge est absent ou incompatible.", load_detail or f"Réponse load-test: HTTP {load_status}; readiness HTTP {readiness_status}: {readiness_detail}", services)

    data = load_payload.get("data", {})
    if not isinstance(data, dict) or data.get("marker") != MARKER or not str(data.get("database", "")).startswith("bts_load_"):
        return ConnectionReport("unsafe", "Environnement refusé", "Le backend ne présente pas le marqueur synthétique attendu. Aucun test ne peut démarrer.", "Marqueur ou nom de base invalide.", services)
    health = data.get("health", {}) if isinstance(data.get("health"), dict) else {}
    load_checks = health.get("checks", {}) if isinstance(health.get("checks"), dict) else {}

    def check(name: str, success: str) -> tuple[bool, str]:
        item = load_checks.get(name, {}) if isinstance(load_checks.get(name), dict) else {}
        ok = bool(item.get("ok"))
        return ok, success if ok else str(item.get("detail") or "Indisponible")

    accounts = int(data.get("synthetic_accounts", 0) or 0)
    services = {
        "backend": (True, "Connecté"), "mariadb": check("database", "Connectée"),
        "database": (True, str(data.get("database"))), "worker": check("queue_worker", "Actif"),
        "reverb": check("reverb", "Actif"), "safety": (True, "SYNTHETIC ONLY"),
        "accounts": (accounts > 0, f"{accounts} prêts" if accounts > 0 else "Aucun compte"),
    }
    report = ConnectionReport("ready", "Environnement synthétique prêt", f"Base {data.get('database')} vérifiée et isolée.", services=services, payload=data)
    ready, reason = report.ready_for("login")
    return report if ready else ConnectionReport("degraded", "Environnement incomplet", reason, services=services, payload=data)


class LoadTester(tk.Tk):
    def __init__(self) -> None:
        super().__init__()
        self.title("BTS Bank — Synthetic Load Tester")
        # 1080 logical pixels fits a 1366px Windows display at 125% scaling;
        # both the configuration rail and the dashboard remain independently usable.
        self.geometry("1080x560")
        self.minsize(960, 540)
        self.configure(bg=COLORS["bg"])
        self.protocol("WM_DELETE_WINDOW", self._on_close)
        self.process: subprocess.Popen[str] | None = None
        self.messages: queue.Queue[tuple[str, object]] = queue.Queue()
        self.control_token = ""
        self.control_url = "http://127.0.0.1:8299"
        self.runtime_ports: RuntimePorts | None = None
        self.signal_path: Path | None = None
        self.output_directory: Path | None = None
        self.started_at = 0.0
        self.durations: list[float] = []
        self.total_requests = self.successes = self.failures = self.completed = 0
        self.phase = "idle"
        self.campaign_completed = False
        self.cleanup_requested = False
        self.last_connection: ConnectionReport | None = None
        self.environment_badges: dict[str, tk.Label] = {}
        self.configuration_widgets: list[ttk.Widget] = []
        self._variables()
        self._styles()
        self._layout()
        self._refresh_actions()
        self.after(100, self._drain_messages)

    def _variables(self) -> None:
        self.preset = tk.StringVar(value="Smoke")
        self.backend_url = tk.StringVar(value="http://127.0.0.1:8200")
        self.profile = tk.StringVar(value="none")
        self.branch_mode = tk.StringVar(value="distributed")
        self.seed = tk.StringVar(value="20260825")
        self.accounts = tk.IntVar(value=5)
        self.concurrency = tk.IntVar(value=5)
        self.apps = tk.IntVar(value=1)
        self.scenario = tk.StringVar(value="login")
        self.pattern = tk.StringVar(value="burst")
        self.ramp = tk.IntVar(value=0)
        self.duration = tk.StringVar(value="2m")
        self.think = tk.IntVar(value=0)
        self.max_error = tk.DoubleVar(value=0)
        self.p95_limit = tk.IntVar(value=0)
        self.p99_limit = tk.IntVar(value=0)
        self.keep_db = tk.BooleanVar(value=False)
        self.status = tk.StringVar(value="Environnement non préparé")
        self.disabled_reason = tk.StringVar(value="Préparez un environnement synthétique isolé pour activer le test.")
        self.metric_vars = {name: tk.StringVar(value="0") for name in ("running", "progress", "success", "failure", "rps", "average", "p95", "p99", "elapsed", "queue")}

    def _styles(self) -> None:
        style = ttk.Style(self)
        style.theme_use("clam")
        style.configure("TFrame", background=COLORS["bg"])
        style.configure("TLabel", background=COLORS["surface"], foreground=COLORS["slate"], font=("Segoe UI", 9))
        style.configure("TEntry", padding=7, fieldbackground=COLORS["surface"], bordercolor=COLORS["border"])
        style.configure("TCombobox", padding=6, fieldbackground=COLORS["surface"])
        style.configure("TSpinbox", padding=6, fieldbackground=COLORS["surface"])
        style.configure("TCheckbutton", background=COLORS["surface"], foreground=COLORS["slate"])
        style.configure("Secondary.TButton", padding=(10, 8), font=("Segoe UI", 9, "bold"), background="#FFFFFF", foreground=COLORS["navy"])
        style.map("Secondary.TButton", background=[("active", "#EEF1F4"), ("disabled", "#F3F4F6")])
        style.configure("Accent.TButton", padding=(12, 9), font=("Segoe UI", 9, "bold"), foreground="white", background=COLORS["red"])
        style.map("Accent.TButton", background=[("active", COLORS["red_dark"]), ("disabled", "#D7A7AA")])
        style.configure("Danger.TButton", padding=(10, 8), font=("Segoe UI", 9, "bold"), foreground=COLORS["red"], background=COLORS["red_soft"])
        style.configure("Treeview", rowheight=30, background="white", fieldbackground="white", foreground=COLORS["navy"], borderwidth=0)
        style.configure("Treeview.Heading", padding=8, background="#F5F7F9", foreground=COLORS["slate"], font=("Segoe UI", 9, "bold"))
        style.map("Treeview", background=[("selected", "#F8DDE0")], foreground=[("selected", COLORS["navy"])])

    def _layout(self) -> None:
        self._header()
        body = tk.Frame(self, bg=COLORS["bg"])
        body.pack(fill="both", expand=True, padx=18, pady=14)
        body.grid_columnconfigure(0, minsize=350)
        body.grid_columnconfigure(1, weight=1)
        body.grid_rowconfigure(0, weight=1)
        self._configuration_panel(body)
        self._dashboard_panel(body)
        tk.Label(self, textvariable=self.status, anchor="w", padx=20, pady=8, bg=COLORS["navy"], fg="white", font=("Segoe UI", 9, "bold")).pack(fill="x", side="bottom")

    def _header(self) -> None:
        header = tk.Frame(self, bg=COLORS["navy"], height=72)
        header.pack(fill="x")
        header.pack_propagate(False)
        brand = tk.Frame(header, bg=COLORS["navy"])
        brand.pack(side="left", padx=24, pady=9)
        tk.Label(brand, text="BTS", bg=COLORS["red"], fg="white", font=("Segoe UI", 13, "bold"), padx=10, pady=8).pack(side="left")
        text = tk.Frame(brand, bg=COLORS["navy"])
        text.pack(side="left", padx=12)
        tk.Label(text, text="Synthetic Load Tester", bg=COLORS["navy"], fg="white", font=("Segoe UI", 18, "bold")).pack(anchor="w")
        tk.Label(text, text="Tests de charge, concurrence et performance", bg=COLORS["navy"], fg="#B9C5D1", font=("Segoe UI", 9)).pack(anchor="w")
        badges = tk.Frame(header, bg=COLORS["navy"])
        badges.pack(side="right", padx=24)
        self._header_badge(badges, "LOCAL", "#24364A").pack(side="left", padx=5)
        self._header_badge(badges, "SYNTHETIC TESTING ONLY", COLORS["red"]).pack(side="left", padx=5)

    @staticmethod
    def _header_badge(parent: tk.Widget, text: str, bg: str) -> tk.Label:
        return tk.Label(parent, text=text, bg=bg, fg="white", padx=12, pady=6, font=("Segoe UI", 8, "bold"))

    def _configuration_panel(self, parent: tk.Widget) -> None:
        shell = tk.Frame(parent, bg="white", highlightbackground=COLORS["border"], highlightthickness=1)
        shell.grid(row=0, column=0, sticky="nsew", padx=(0, 12))
        canvas = tk.Canvas(shell, bg="white", highlightthickness=0, width=350)
        scrollbar = ttk.Scrollbar(shell, orient="vertical", command=canvas.yview)
        inner = tk.Frame(canvas, bg="white")
        window = canvas.create_window((0, 0), window=inner, anchor="nw")
        inner.bind("<Configure>", lambda _event: canvas.configure(scrollregion=canvas.bbox("all")))
        canvas.bind("<Configure>", lambda event: canvas.itemconfigure(window, width=event.width))
        canvas.configure(yscrollcommand=scrollbar.set)
        canvas.pack(side="left", fill="both", expand=True)
        scrollbar.pack(side="right", fill="y")
        title = tk.Frame(inner, bg="white")
        title.pack(fill="x", padx=16, pady=(16, 4))
        tk.Label(title, text="Configuration", bg="white", fg=COLORS["navy"], font=("Segoe UI", 14, "bold")).pack(anchor="w")
        tk.Label(title, text="Paramètres figés après préparation", bg="white", fg=COLORS["muted"], font=("Segoe UI", 8)).pack(anchor="w")

        target = self._card(inner, "Cible", "API locale isolée; jamais la base BTS normale")
        self._field(target, "URL backend", ttk.Entry(target, textvariable=self.backend_url))
        row = tk.Frame(target, bg="white")
        row.pack(fill="x", pady=(4, 5))
        self.test_button = ttk.Button(row, text="Tester", style="Secondary.TButton", command=self.test_connection)
        self.test_button.pack(side="left", expand=True, fill="x", padx=(0, 4))
        self.prepare_button = ttk.Button(row, text="Préparer l’environnement", style="Accent.TButton", command=self.prepare_environment)
        self.prepare_button.pack(side="left", expand=True, fill="x", padx=(4, 0))

        data = self._card(inner, "Données & preset", "Comptes réservés et reproductibles")
        preset = ttk.Combobox(data, textvariable=self.preset, values=list(PRESETS), state="readonly")
        preset.bind("<<ComboboxSelected>>", self.apply_preset)
        self._field(data, "Preset", preset)
        self._field(data, "Profil synthétique", ttk.Combobox(data, textvariable=self.profile, values=("none", "small", "medium"), state="readonly"))
        self._field(data, "Distribution agences", ttk.Combobox(data, textvariable=self.branch_mode, values=("distributed", "single"), state="readonly"))
        self._field(data, "Seed", ttk.Entry(data, textvariable=self.seed))
        self._field(data, "Comptes", ttk.Spinbox(data, from_=1, to=500, textvariable=self.accounts))

        load = self._card(inner, "Charge", "Le preset configure; il ne démarre jamais le test")
        self._field(load, "Scénario", ttk.Combobox(load, textvariable=self.scenario, values=("login", "application", "staff_review", "admin_review", "appointment", "chat", "full", "read"), state="readonly"))
        self._field(load, "Pattern", ttk.Combobox(load, textvariable=self.pattern, values=("burst", "ramp", "sustained", "spike", "soak"), state="readonly"))
        self._field(load, "Concurrence", ttk.Spinbox(load, from_=1, to=100, textvariable=self.concurrency))
        self._field(load, "Applications / compte", ttk.Spinbox(load, from_=1, to=3, textvariable=self.apps))
        self._field(load, "Ramp-up (secondes)", ttk.Spinbox(load, from_=0, to=3600, textvariable=self.ramp))
        self._field(load, "Durée maximale", ttk.Entry(load, textvariable=self.duration))
        self._field(load, "Think time (ms)", ttk.Spinbox(load, from_=0, to=60000, textvariable=self.think))
        self._field(load, "Taux d’erreur max", ttk.Spinbox(load, from_=0, to=1, increment=.01, textvariable=self.max_error))
        self._field(load, "Seuil p95 (ms)", ttk.Spinbox(load, from_=0, to=600000, textvariable=self.p95_limit))
        self._field(load, "Seuil p99 (ms)", ttk.Spinbox(load, from_=0, to=600000, textvariable=self.p99_limit))
        keep = ttk.Checkbutton(load, text="Conserver la base isolée pour diagnostic", variable=self.keep_db)
        keep.pack(anchor="w", pady=(4, 2))
        self.configuration_widgets.append(keep)

        actions = self._card(inner, "Exécution", "Le démarrage reste verrouillé jusqu’à READY")
        self.start_button = ttk.Button(actions, text="Démarrer le test", style="Accent.TButton", command=self.start)
        self.start_button.pack(fill="x")
        tk.Label(actions, textvariable=self.disabled_reason, wraplength=300, justify="left", bg="white", fg=COLORS["muted"], font=("Segoe UI", 8)).pack(fill="x", pady=(6, 8))
        row = tk.Frame(actions, bg="white")
        row.pack(fill="x")
        self.stop_button = ttk.Button(row, text="Stop sûr", style="Danger.TButton", command=self.stop)
        self.stop_button.pack(side="left", expand=True, fill="x", padx=(0, 3))
        self.cleanup_button = ttk.Button(row, text="Nettoyer", style="Secondary.TButton", command=self.cleanup_environment)
        self.cleanup_button.pack(side="left", expand=True, fill="x", padx=3)
        ttk.Button(row, text="Export", style="Secondary.TButton", command=self.open_export).pack(side="left", expand=True, fill="x", padx=(3, 0))
        ttk.Button(actions, text="Réinitialiser les résultats", style="Secondary.TButton", command=self.reset).pack(fill="x", pady=(7, 0))

    def _dashboard_panel(self, parent: tk.Widget) -> None:
        dashboard = tk.Frame(parent, bg=COLORS["bg"])
        dashboard.grid(row=0, column=1, sticky="nsew")
        dashboard.grid_columnconfigure(0, weight=1)
        dashboard.grid_rowconfigure(2, weight=1)
        environment = tk.Frame(dashboard, bg="white", highlightbackground=COLORS["border"], highlightthickness=1)
        environment.grid(row=0, column=0, sticky="ew")
        self._card_heading(environment, "Environnement de test", "État réel retourné par Laravel et les services isolés")
        statuses = tk.Frame(environment, bg="white")
        statuses.pack(fill="x", padx=16, pady=(0, 14))
        labels = (("backend", "Backend"), ("mariadb", "MariaDB"), ("database", "Base isolée"), ("worker", "Worker"), ("reverb", "Reverb"), ("safety", "Sécurité"), ("accounts", "Comptes"))
        for index, (key, label) in enumerate(labels):
            cell = tk.Frame(statuses, bg="#F8FAFB", padx=7, pady=6, highlightbackground="#EDF0F3", highlightthickness=1)
            cell.grid(row=0, column=index, sticky="nsew", padx=2, pady=2)
            tk.Label(cell, text=label, bg="#F8FAFB", fg=COLORS["muted"], font=("Segoe UI", 8)).pack(anchor="w")
            badge = tk.Label(cell, text="● Non vérifié", bg="#F8FAFB", fg=COLORS["muted"], font=("Segoe UI", 9, "bold"))
            badge.pack(anchor="w", pady=(3, 0))
            self.environment_badges[key] = badge
        for column in range(7):
            statuses.grid_columnconfigure(column, weight=1)

        metrics = tk.Frame(dashboard, bg="white", highlightbackground=COLORS["border"], highlightthickness=1)
        metrics.grid(row=1, column=0, sticky="ew", pady=(12, 0))
        self._card_heading(metrics, "Tableau de bord live", "Mesures de la campagne k6 et de la file asynchrone")
        metric_grid = tk.Frame(metrics, bg="white")
        metric_grid.pack(fill="x", padx=14, pady=(0, 12))
        titles = (("running", "Actifs"), ("progress", "Progression"), ("success", "Succès"), ("failure", "Échecs"), ("rps", "RPS"), ("average", "Latence moy."), ("p95", "P95"), ("p99", "P99"), ("elapsed", "Temps écoulé"), ("queue", "Queue/échecs"))
        for index, (key, title) in enumerate(titles):
            cell = tk.Frame(metric_grid, bg="white", padx=3, pady=2)
            cell.grid(row=0, column=index, sticky="nsew")
            tk.Label(cell, textvariable=self.metric_vars[key], bg="white", fg=COLORS["red"], font=("Segoe UI", 13, "bold")).pack()
            tk.Label(cell, text=title, bg="white", fg=COLORS["muted"], font=("Segoe UI", 7)).pack()
            metric_grid.grid_columnconfigure(index, weight=1)

        lower = ttk.Notebook(dashboard)
        lower.grid(row=2, column=0, sticky="nsew", pady=(12, 0))
        results = tk.Frame(lower, bg="white", highlightbackground=COLORS["border"], highlightthickness=1)
        logs = tk.Frame(lower, bg="white", highlightbackground=COLORS["border"], highlightthickness=1)
        lower.add(results, text="  Résultats  ")
        lower.add(logs, text="  Journal live  ")
        self._card_heading(results, "Résultats", "Une ligne par client synthétique")
        table_shell = tk.Frame(results, bg="white")
        table_shell.pack(fill="both", expand=True, padx=14, pady=(0, 12))
        table_shell.grid_columnconfigure(0, weight=1)
        table_shell.grid_rowconfigure(0, weight=1)
        columns = ("user", "scenario", "application", "status", "branch", "code", "duration", "error")
        self.table = ttk.Treeview(table_shell, columns=columns, show="headings", height=7)
        headings = ("Client synthétique", "Scénario", "Demande", "Statut", "Agence", "HTTP", "Durée", "Erreur")
        widths = (180, 90, 130, 85, 130, 55, 75, 220)
        for column, heading, width in zip(columns, headings, widths):
            self.table.heading(column, text=heading)
            self.table.column(column, width=width, minwidth=50)
        table_scroll = ttk.Scrollbar(table_shell, orient="vertical", command=self.table.yview)
        horizontal_scroll = ttk.Scrollbar(table_shell, orient="horizontal", command=self.table.xview)
        self.table.configure(yscrollcommand=table_scroll.set, xscrollcommand=horizontal_scroll.set)
        self.table.grid(row=0, column=0, sticky="nsew")
        table_scroll.grid(row=0, column=1, sticky="ns")
        horizontal_scroll.grid(row=1, column=0, sticky="ew")
        self._card_heading(logs, "Journal live", "Détails techniques conservés ici; secrets automatiquement masqués")
        self.logs = tk.Text(logs, height=7, bg=COLORS["console"], fg="#DCE7F5", insertbackground="white", font=("Cascadia Mono", 9), relief="flat", padx=10, pady=8)
        for level, color in (("INFO", "#AFC7DF"), ("SUCCESS", "#65D6A0"), ("WARNING", "#F1C36A"), ("ERROR", "#FF8189")):
            self.logs.tag_configure(level, foreground=color)
        self.logs.pack(fill="both", expand=True, padx=14, pady=(0, 12))

    @staticmethod
    def _card(parent: tk.Widget, title: str, subtitle: str) -> tk.Frame:
        outer = tk.Frame(parent, bg="white", highlightbackground=COLORS["border"], highlightthickness=1)
        outer.pack(fill="x", padx=14, pady=7)
        content = tk.Frame(outer, bg="white")
        content.pack(fill="x", padx=12, pady=11)
        tk.Label(content, text=title, bg="white", fg=COLORS["navy"], font=("Segoe UI", 10, "bold")).pack(anchor="w")
        tk.Label(content, text=subtitle, bg="white", fg=COLORS["muted"], font=("Segoe UI", 8), wraplength=300, justify="left").pack(anchor="w", pady=(1, 8))
        return content

    @staticmethod
    def _card_heading(parent: tk.Widget, title: str, subtitle: str) -> None:
        heading = tk.Frame(parent, bg="white")
        heading.pack(fill="x", padx=16, pady=(8, 6))
        tk.Label(heading, text=title, bg="white", fg=COLORS["navy"], font=("Segoe UI", 11, "bold")).pack(anchor="w")
        tk.Label(heading, text=subtitle, bg="white", fg=COLORS["muted"], font=("Segoe UI", 8)).pack(anchor="w")

    def _field(self, parent: tk.Widget, label: str, widget: ttk.Widget) -> None:
        tk.Label(parent, text=label, bg="white", fg=COLORS["slate"], font=("Segoe UI", 8, "bold")).pack(anchor="w")
        widget.pack(fill="x", pady=(2, 7))
        self.configuration_widgets.append(widget)

    def apply_preset(self, _event: object = None) -> None:
        accounts, concurrency = PRESETS[self.preset.get()]
        self.accounts.set(accounts)
        self.concurrency.set(concurrency)
        if self.preset.get() == "Smoke":
            self.scenario.set("login")
            self.duration.set("2m")

    def test_connection(self) -> None:
        self.test_button.configure(state="disabled")
        self.status.set("Vérification de la connexion…")
        backend_url = self.backend_url.get()
        threading.Thread(target=lambda: self.messages.put(("connection", inspect_connection(backend_url))), daemon=True).start()

    def _apply_connection_report(self, report: ConnectionReport, show_dialog: bool = True) -> None:
        self.last_connection = report
        self._update_environment_badges(report.services)
        self.status.set(f"{report.title} — {report.message}")
        level = "SUCCESS" if report.state == "ready" else "WARNING" if report.state in {"environment_not_ready", "degraded"} else "ERROR"
        self.log_event(level, report.message)
        if report.technical:
            self.log_event("INFO", f"Diagnostic: {report.technical}")
        if show_dialog:
            (messagebox.showinfo if report.state == "ready" else messagebox.showwarning)(report.title, report.message)
        self.test_button.configure(state="normal")
        self._refresh_actions()

    def prepare_environment(self) -> None:
        if self.process and self.process.poll() is None:
            return
        error = self._configuration_error()
        if error:
            messagebox.showerror("Configuration invalide", error)
            return
        if self.accounts.get() >= 100 or self.concurrency.get() >= 50 or self.profile.get() == "medium":
            if not messagebox.askyesno("Campagne lourde", "Cette campagne peut utiliser beaucoup de CPU, RAM et disque. Préparer l’environnement ?"):
                return
        try:
            backend_url = normalize_backend_url(self.backend_url.get())
            self.runtime_ports = select_runtime_ports(backend_url)
        except (ValueError, RuntimeError) as exc:
            messagebox.showerror("Ports indisponibles", str(exc))
            return
        self.reset_metrics(clear_logs=True)
        self.control_token = secrets.token_urlsafe(36)
        self.signal_path = Path(tempfile.gettempdir()) / f"bts-load-gui-{secrets.token_hex(16)}.signal"
        self.cleanup_requested = False
        self.campaign_completed = False
        env = os.environ.copy()
        env["BTS_LOAD_GUI_CONTROL_TOKEN"] = self.control_token
        command = self._runner_command(backend_url, self.runtime_ports) + ["-GuiLifecycle", "-GuiSignalPath", str(self.signal_path), "-GuiParentPid", str(os.getpid())]
        if self.keep_db.get():
            command.append("-KeepDatabase")
        try:
            self.process = subprocess.Popen(command, cwd=ROOT, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, encoding="utf-8", errors="replace", bufsize=1)
        except OSError as exc:
            self.process = None
            self.status.set("Impossible de lancer la préparation")
            self.log_event("ERROR", "PowerShell ou le runner est indisponible.")
            self.log_event("INFO", f"Diagnostic: {exc!r}")
            messagebox.showerror("Préparation impossible", "Impossible de démarrer le préparateur local. Vérifiez PowerShell et les dépendances du projet.")
            return
        self.phase = "preparing"
        self.status.set("Préparation de l’environnement synthétique isolé…")
        self.log_event("INFO", "Préparation demandée; vérification de MariaDB, k6, PHP et Python.")
        self.log_event("INFO", f"Ports réservés: backend {urlsplit(backend_url).port}, collecteur {self.runtime_ports.collector}, Reverb {self.runtime_ports.reverb}, workers {self.runtime_ports.worker_base}–{self.runtime_ports.worker_base + 4}.")
        self._refresh_actions()
        threading.Thread(target=self._read_process, daemon=True).start()

    def _runner_command(self, backend_url: str, ports: RuntimePorts) -> list[str]:
        return ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", str(ROOT / "run.ps1"), "-SyntheticProfile", self.profile.get(), "-BranchMode", self.branch_mode.get(), "-Scenario", self.scenario.get(), "-Pattern", self.pattern.get(), "-Accounts", str(self.accounts.get()), "-Concurrency", str(self.concurrency.get()), "-ApplicationsPerAccount", str(self.apps.get()), "-RampUpSeconds", str(self.ramp.get()), "-Duration", self.duration.get(), "-ThinkTimeMs", str(self.think.get()), "-Seed", self.seed.get(), "-MaxErrorRate", str(self.max_error.get()), "-P95Ms", str(self.p95_limit.get()), "-P99Ms", str(self.p99_limit.get()), "-BackendUrl", backend_url, "-OtpCollectorPort", str(ports.collector), "-ReverbPort", str(ports.reverb), "-ApiWorkerPortBase", str(ports.worker_base)]

    def _configuration_error(self) -> str:
        try:
            normalize_backend_url(self.backend_url.get())
            if self.concurrency.get() > self.accounts.get():
                return "La concurrence ne peut pas dépasser le nombre de comptes."
            if not re.fullmatch(r"[A-Za-z0-9._-]{1,64}", self.seed.get()):
                return "Le seed doit contenir 1 à 64 lettres, chiffres, points, tirets ou underscores."
            if not re.fullmatch(r"[0-9]+[smh]", self.duration.get()):
                return "La durée doit utiliser le format 30s, 2m ou 1h."
        except (ValueError, tk.TclError) as exc:
            return str(exc)
        return ""

    def start(self) -> None:
        if self.phase != "ready" or not self.signal_path or not self.process or self.process.poll() is not None:
            messagebox.showwarning("Test verrouillé", self.disabled_reason.get())
            return
        if not self.last_connection:
            messagebox.showwarning("Test verrouillé", "L’environnement doit être vérifié avant le démarrage.")
            return
        ready, reason = self.last_connection.ready_for(self.scenario.get())
        if not ready:
            messagebox.showwarning("Services requis indisponibles", reason)
            return
        self.signal_path.with_suffix(self.signal_path.suffix + ".start").write_text("start", encoding="ascii")
        self.phase = "running"
        self.started_at = time.monotonic()
        self.status.set("Campagne en cours — SYNTHETIC TESTING ONLY")
        self.log_event("INFO", "Démarrage explicite envoyé au runner k6.")
        self._refresh_actions()

    def stop(self) -> None:
        if self.phase != "running" or not self.process or self.process.poll() is not None:
            return
        try:
            request = Request(f"{self.control_url}/control/stop", method="POST", headers={"Authorization": f"Bearer {self.control_token}"})
            with urlopen(request, timeout=2):
                pass
            self.status.set("Arrêt sûr demandé; les transactions en cours peuvent terminer")
            self.log_event("WARNING", "Stop sûr demandé: aucun nouveau workflow ne sera créé.")
            self.stop_button.configure(state="disabled")
        except OSError as exc:
            self.log_event("ERROR", "Le canal d’arrêt sûr n’est pas encore disponible.")
            self.log_event("INFO", f"Diagnostic: {exc!r}")
            messagebox.showwarning("Arrêt indisponible", "Le canal d’arrêt sûr n’est pas disponible. Consultez le journal live.")

    def cleanup_environment(self) -> None:
        if not self.signal_path or not self.process or self.process.poll() is not None:
            self.status.set("Aucun environnement géré par cette application à nettoyer")
            return
        if self.phase == "running":
            messagebox.showwarning("Campagne active", "Utilisez d’abord « Stop sûr » afin de laisser les transactions en cours terminer.")
            return
        suffix = ".cleanup" if self.phase == "completed" else ".cancel"
        self.signal_path.with_suffix(self.signal_path.suffix + suffix).write_text("cleanup", encoding="ascii")
        self.cleanup_requested = True
        self.phase = "cleaning"
        self.status.set("Nettoyage contrôlé de l’environnement isolé…")
        self.log_event("INFO", "Nettoyage demandé au runner qui possède le marqueur signé.")
        self._refresh_actions()

    def _read_process(self) -> None:
        assert self.process and self.process.stdout
        for line in self.process.stdout:
            self.messages.put(("line", line.rstrip()))
        code = self.process.wait()
        self.messages.put(("done", code))

    def _drain_messages(self) -> None:
        try:
            while True:
                kind, payload = self.messages.get_nowait()
                if kind == "line":
                    self._handle_line(str(payload))
                elif kind == "connection":
                    self._apply_connection_report(payload)  # type: ignore[arg-type]
                else:
                    self._finished(int(payload))
        except queue.Empty:
            pass
        if self.phase == "running":
            elapsed = time.monotonic() - self.started_at
            self.metric_vars["elapsed"].set(f"{elapsed:.0f}s")
            self.metric_vars["running"].set(str(max(0, min(self.concurrency.get(), self.accounts.get() - self.completed))))
        self.after(100, self._drain_messages)

    def _handle_line(self, line: str) -> None:
        if "BTS_STAGE " in line:
            try:
                stage = json.loads(line.split("BTS_STAGE ", 1)[1])
                self.log_event(str(stage.get("level", "INFO")), str(stage.get("message", "")))
            except json.JSONDecodeError:
                self.log_event("INFO", line)
            return
        if "BTS_ENV " in line:
            try:
                payload = json.loads(line.split("BTS_ENV ", 1)[1])
                count = int(payload.get("synthetic_accounts", 0))
                self.control_url = str(payload.get("control_url") or "http://127.0.0.1:8299").rstrip("/")
                services = {"backend": (payload.get("backend") == "connected", "Connecté"), "mariadb": (payload.get("mariadb") == "ready", "Connectée"), "database": (str(payload.get("database", "")).startswith("bts_load_"), str(payload.get("database", ""))), "worker": (payload.get("queue_worker") == "active", "Actif"), "reverb": (payload.get("reverb") == "active", "Actif"), "safety": (payload.get("marker") == MARKER, "SYNTHETIC ONLY"), "accounts": (count > 0, f"{count} prêts")}
                self.last_connection = ConnectionReport("ready", "Environnement synthétique prêt", f"Base {payload.get('database')} vérifiée et isolée.", services=services, payload=payload)
                self._update_environment_badges(services)
                self.phase = "ready"
                self.status.set("READY — environnement synthétique isolé vérifié")
                self._refresh_actions()
            except (json.JSONDecodeError, TypeError, ValueError):
                self.log_event("ERROR", "Le runner a retourné un état d’environnement illisible.")
            return
        if "BTS_CAMPAIGN_DONE " in line:
            try:
                payload = json.loads(line.split("BTS_CAMPAIGN_DONE ", 1)[1])
                self.output_directory = Path(str(payload.get("output", "")))
            except json.JSONDecodeError:
                pass
            self.campaign_completed = True
            self.phase = "completed"
            self.status.set("Campagne réussie — export disponible; nettoyage requis")
            self.metric_vars["running"].set("0")
            self._refresh_actions()
            return
        if "BTS_OUTPUT " in line:
            self.output_directory = Path(line.split("BTS_OUTPUT ", 1)[1].strip())
        if "BTS_RESULT " in line:
            try:
                self._result(json.loads(line.split("BTS_RESULT ", 1)[1]))
            except json.JSONDecodeError:
                pass
        if "BTS_METRIC " in line:
            try:
                metric = json.loads(line.split("BTS_METRIC ", 1)[1])
                self.metric_vars["queue"].set(f"{int(metric.get('queue_pending', 0))}/{int(metric.get('failed_jobs', 0))}")
            except (json.JSONDecodeError, TypeError, ValueError):
                pass
        safe = SECRET_PATTERN.sub(r"\1\2[REDACTED]", line)
        if "BTS_RESULT " not in safe and "BTS_METRIC " not in safe:
            level = "ERROR" if "error" in safe.lower() or "exception" in safe.lower() else "WARNING" if "warning" in safe.lower() else "INFO"
            self.log_event(level, safe)

    def _result(self, data: dict[str, object]) -> None:
        self.completed += 1
        ok = data.get("status") == "success"
        self.successes += int(ok)
        self.failures += int(not ok)
        duration = float(data.get("duration_ms", 0))
        self.durations.append(duration)
        self.total_requests += int(data.get("requests", 0))
        elapsed = max(time.monotonic() - self.started_at, 0.001)
        values = sorted(self.durations)

        def percentile(fraction: float) -> float:
            return values[min(len(values) - 1, int((len(values) - 1) * fraction))] if values else 0

        self.metric_vars["progress"].set(f"{self.completed}/{self.accounts.get()}")
        self.metric_vars["success"].set(str(self.successes))
        self.metric_vars["failure"].set(str(self.failures))
        self.metric_vars["rps"].set(f"{self.total_requests / elapsed:.1f}")
        self.metric_vars["average"].set(f"{statistics.fmean(self.durations):.0f} ms")
        self.metric_vars["p95"].set(f"{percentile(.95):.0f} ms")
        self.metric_vars["p99"].set(f"{percentile(.99):.0f} ms")
        applications = data.get("applications") or []
        application = applications[0] if isinstance(applications, list) and applications else {}
        self.table.insert("", "end", values=(str(data.get("synthetic_user", "")), str(data.get("scenario", "")), str(application.get("applicationNumber", "")), str(data.get("status", "")), str(application.get("branchName", "")), str(data.get("response_code", "")), f"{duration:.0f} ms", str(data.get("error") or "")))

    def _finished(self, code: int) -> None:
        preserved = self.keep_db.get()
        self.process = None
        self.metric_vars["running"].set("0")
        self._update_environment_badges({})
        if self.cleanup_requested:
            self.status.set("Environnement fermé; base isolée conservée sur demande" if preserved else "Environnement nettoyé; base BTS normale intacte")
            self.log_event("WARNING" if preserved else "SUCCESS", "Base isolée conservée explicitement." if preserved else "Nettoyage terminé; aucun environnement bts_load actif ne subsiste.")
        elif code == 0 and self.campaign_completed:
            self.status.set("Campagne terminée et environnement fermé")
        else:
            self.status.set(f"Préparation ou campagne interrompue (code {code})")
            self.log_event("ERROR", "Le runner s’est arrêté. Consultez les diagnostics ci-dessus.")
        self.phase = "idle"
        self.signal_path = None
        self.runtime_ports = None
        self.last_connection = None
        self.cleanup_requested = False
        self._refresh_actions()

    def _update_environment_badges(self, services: dict[str, tuple[bool | None, str]]) -> None:
        for key, badge in self.environment_badges.items():
            ok, detail = services.get(key, (None, "Non vérifié"))
            if key == "database" and len(detail) > 18:
                detail = f"{detail[:10]}…{detail[-6:]}"
            color = COLORS["green"] if ok is True else COLORS["red"] if ok is False else COLORS["muted"]
            badge.configure(text=f"● {detail}", fg=color)

    def _refresh_actions(self) -> None:
        managed = self.process is not None and self.process.poll() is None
        ready = False
        reason = "Préparez un environnement synthétique isolé pour activer le test."
        if self.phase == "preparing":
            reason = "Préparation en cours: migrations, comptes et services sont vérifiés."
        elif self.phase == "ready" and self.last_connection:
            ready, reason = self.last_connection.ready_for(self.scenario.get())
        elif self.phase == "running":
            reason = "Campagne en cours. Utilisez Stop sûr si nécessaire."
        elif self.phase == "completed":
            reason = "Campagne terminée. Exportez puis nettoyez l’environnement."
        elif self.phase == "cleaning":
            reason = "Nettoyage contrôlé en cours."
        self.disabled_reason.set(reason)
        self.start_button.configure(state="normal" if ready and managed and self.phase == "ready" else "disabled")
        self.stop_button.configure(state="normal" if managed and self.phase == "running" else "disabled")
        self.cleanup_button.configure(state="normal" if managed and self.phase in {"preparing", "ready", "completed"} else "disabled")
        self.prepare_button.configure(state="normal" if not managed and self.phase == "idle" else "disabled")
        for widget in self.configuration_widgets:
            widget.state(["disabled"] if managed else ["!disabled"])

    def log_event(self, level: str, message: str) -> None:
        level = level.upper() if level.upper() in {"INFO", "SUCCESS", "WARNING", "ERROR"} else "INFO"
        safe = SECRET_PATTERN.sub(r"\1\2[REDACTED]", message)
        self.logs.insert("end", f"{time.strftime('%H:%M:%S')} {level:<7} {safe}\n", level)
        self.logs.see("end")

    def reset_metrics(self, clear_logs: bool = False) -> None:
        self.completed = self.successes = self.failures = self.total_requests = 0
        self.durations.clear()
        self.output_directory = None
        for variable in self.metric_vars.values():
            variable.set("0")
        for row in self.table.get_children():
            self.table.delete(row)
        if clear_logs:
            self.logs.delete("1.0", "end")

    def reset(self) -> None:
        if self.process and self.process.poll() is None:
            messagebox.showwarning("Réinitialisation", "Nettoyez d’abord l’environnement actif.")
            return
        self.preset.set("Smoke")
        self.apply_preset()
        self.profile.set("none")
        self.branch_mode.set("distributed")
        self.apps.set(1)
        self.pattern.set("burst")
        self.ramp.set(0)
        self.think.set(0)
        self.max_error.set(0)
        self.p95_limit.set(0)
        self.p99_limit.set(0)
        self.reset_metrics(clear_logs=True)
        self.status.set("Environnement non préparé")

    def open_export(self) -> None:
        path = self.output_directory or (ROOT / "results")
        path.mkdir(parents=True, exist_ok=True)
        os.startfile(path)  # type: ignore[attr-defined]

    def _on_close(self) -> None:
        if self.process and self.process.poll() is None:
            if not messagebox.askyesno("Fermer", "Un environnement isolé est actif. Le fermer et lancer son nettoyage sûr ?"):
                return
            if self.phase == "running":
                self.stop()
            elif self.signal_path:
                suffix = ".cleanup" if self.phase == "completed" else ".cancel"
                self.signal_path.with_suffix(self.signal_path.suffix + suffix).write_text("cleanup", encoding="ascii")
        self.destroy()


if __name__ == "__main__":
    LoadTester().mainloop()
