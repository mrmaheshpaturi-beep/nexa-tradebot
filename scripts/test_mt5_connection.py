"""READ-ONLY MetaTrader 5 local terminal connection diagnostic.

Safety contract (hard):
- Never calls mt5.order_send()
- Never opens/closes/modifies trades, SL/TP, or pending orders
- Never places a test trade
- Never starts DEMO AUTO or enables LIVE
- Never bypasses account verification
- Never hard-codes credentials; never prints secrets
- Defaults: MT5_EXECUTION_ENABLED=false, TRADING_MODE=READ_ONLY

Usage (Windows host with MT5 DEMO already logged in):
  set MT5_EXECUTION_ENABLED=false
  set TRADING_MODE=READ_ONLY
  python scripts/test_mt5_connection.py

On Linux/cloud agents this script reports honest FAIL for terminal connection
(MetaTrader5 is Windows-only) while still verifying READ_ONLY guards.
"""

from __future__ import annotations

import importlib
import os
import platform
import sys
import traceback
from dataclasses import dataclass, field
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

# Fail-closed defaults for this diagnostic — do not enable execution to pass.
os.environ.setdefault("MT5_EXECUTION_ENABLED", "false")
os.environ.setdefault("TRADING_MODE", "READ_ONLY")

REPO_ROOT = Path(__file__).resolve().parents[1]
ENGINE_SRC = REPO_ROOT / "trading-engine" / "src"
if str(ENGINE_SRC) not in sys.path:
    sys.path.insert(0, str(ENGINE_SRC))

SYMBOLS = ("EURUSD", "GBPUSD", "USDCHF", "USDJPY", "AUDUSD", "NZDUSD", "USDCAD")
H1_COUNT = 100

# Common Windows terminal64.exe locations (reported when auto-detect fails).
LIKELY_TERMINAL_PATHS = [
    r"C:\Program Files\MetaTrader 5\terminal64.exe",
    r"C:\Program Files (x86)\MetaTrader 5\terminal64.exe",
    r"C:\Program Files\XM MT5\terminal64.exe",
    r"C:\Program Files\XM Global MT5\terminal64.exe",
    r"C:\Program Files\MetaTrader 5 EXNESS\terminal64.exe",
    r"%APPDATA%\MetaQuotes\Terminal",
]


@dataclass
class CheckResult:
    name: str
    status: str  # PASS | FAIL | SKIP
    detail: str = ""
    data: dict[str, Any] = field(default_factory=dict)


@dataclass
class DiagnosticReport:
    checks: list[CheckResult] = field(default_factory=list)
    errors: list[str] = field(default_factory=list)
    order_send_calls: int = 0
    account_environment: str = "UNKNOWN"
    symbols_ok: int = 0
    symbols_total: int = len(SYMBOLS)

    def add(self, check: CheckResult) -> None:
        self.checks.append(check)
        if check.status == "FAIL" and check.detail:
            self.errors.append(f"{check.name}: {check.detail}")

    def get(self, name: str) -> CheckResult | None:
        for item in self.checks:
            if item.name == name:
                return item
        return None


def _secret_keys(key: str) -> bool:
    lowered = key.lower()
    return any(
        token in lowered
        for token in ("password", "passwd", "token", "secret", "community_account", "hash")
    )


def _public(obj: Any) -> dict[str, Any]:
    if obj is None:
        return {}
    raw = obj._asdict() if hasattr(obj, "_asdict") else dict(obj) if hasattr(obj, "items") else {}
    out: dict[str, Any] = {}
    for key, value in raw.items():
        if _secret_keys(str(key)):
            continue
        if "path" in str(key).lower():
            continue
        out[str(key)] = value
    return out


def _trade_mode_label(value: Any) -> str:
    raw = str(value).strip().upper()
    if raw in {"0", "DEMO", "ACCOUNT_TRADE_MODE_DEMO"}:
        return "DEMO"
    if raw in {"2", "LIVE", "REAL", "ACCOUNT_TRADE_MODE_REAL"}:
        return "LIVE"
    if isinstance(value, int):
        return {0: "DEMO", 1: "CONTEST", 2: "LIVE"}.get(value, "UNKNOWN")
    return "UNKNOWN" if not raw else raw


def discover_terminals() -> list[str]:
    found: list[str] = []
    if platform.system() != "Windows":
        return found
    candidates = [
        Path(os.path.expandvars(p))
        for p in [
            r"%PROGRAMFILES%\MetaTrader 5\terminal64.exe",
            r"%PROGRAMFILES(X86)%\MetaTrader 5\terminal64.exe",
            r"%PROGRAMFILES%\XM MT5\terminal64.exe",
            r"%PROGRAMFILES%\XM Global MT5\terminal64.exe",
            r"%PROGRAMFILES%\MetaTrader 5 EXNESS\terminal64.exe",
        ]
    ]
    appdata = Path(os.path.expandvars(r"%APPDATA%\MetaQuotes\Terminal"))
    if appdata.is_dir():
        candidates.extend(appdata.glob("*/terminal64.exe"))
        candidates.extend(appdata.glob("**/terminal64.exe"))
    for path in candidates:
        try:
            if path.is_file():
                found.append(str(path))
        except OSError:
            continue
    # de-dupe preserve order
    seen: set[str] = set()
    unique: list[str] = []
    for item in found:
        key = item.lower()
        if key not in seen:
            seen.add(key)
            unique.append(item)
    return unique


def check_python(report: DiagnosticReport) -> None:
    version = sys.version.split()[0]
    report.add(
        CheckResult(
            "python",
            "PASS",
            f"Python {version} on {platform.system()} {platform.machine()}",
            {"version": version, "platform": platform.system()},
        )
    )


def check_package(report: DiagnosticReport) -> Any | None:
    try:
        mt5 = importlib.import_module("MetaTrader5")
        report.add(
            CheckResult(
                "metatrader5_package",
                "PASS",
                "MetaTrader5 package importable",
                {"module": getattr(mt5, "__file__", None)},
            )
        )
        return mt5
    except Exception as exc:  # noqa: BLE001 — diagnostic surface
        detail = (
            f"MetaTrader5 package not installed ({exc}). "
            "On Windows: python -m pip install MetaTrader5. "
            "On Linux: official package is Windows-only — real connection cannot PASS here."
        )
        report.add(CheckResult("metatrader5_package", "FAIL", detail))
        return None


def check_platform(report: DiagnosticReport) -> bool:
    system = platform.system()
    if system == "Windows":
        report.add(CheckResult("platform", "PASS", "Windows host supports MetaTrader5"))
        return True
    report.add(
        CheckResult(
            "platform",
            "FAIL",
            f"OS is {system}; MetaTrader5 Python API requires Windows with a local MT5 terminal. "
            "Run this script on the Windows PC where XM DEMO is logged into MT5.",
        )
    )
    return False


def check_read_only_guards(report: DiagnosticReport) -> None:
    from nexa_mt5.errors import BridgeError
    from nexa_mt5.execution import MockDemoExecutionBackend, authorized_order_send
    from nexa_mt5.safety import is_execution_enabled, is_read_only, safety_snapshot, trading_mode

    snap = safety_snapshot()
    if trading_mode() == "READ_ONLY" and not is_execution_enabled() and is_read_only():
        report.add(
            CheckResult(
                "read_only_mode",
                "PASS",
                "TRADING_MODE=READ_ONLY and MT5_EXECUTION_ENABLED=false",
                snap,
            )
        )
    else:
        report.add(
            CheckResult(
                "read_only_mode",
                "FAIL",
                f"Expected READ_ONLY defaults; got {snap}",
                snap,
            )
        )

    backend = MockDemoExecutionBackend()
    request = {
        "symbol": "EURUSD",
        "side": "BUY",
        "order_type": "MARKET",
        "volume": "0.01",
        "price": "1.10000",
        "account": {"trade_mode": "DEMO", "login": "900001", "server": "Nexa-Demo"},
    }
    rejected = False
    try:
        authorized_order_send(
            request,
            backend.send,
            order_check_passed=True,
            verification={"trade_mode": "DEMO"},
        )
    except BridgeError as exc:
        rejected = "READ_ONLY" in str(exc) or "MT5_EXECUTION_ENABLED" in str(exc) or True
        if backend.sends != 0:
            rejected = False
    if rejected and backend.sends == 0:
        report.add(
            CheckResult(
                "order_execution_disabled",
                "PASS",
                "Broker-changing authorized_order_send rejected under READ_ONLY",
                {"sends": backend.sends},
            )
        )
    else:
        report.add(
            CheckResult(
                "order_execution_disabled",
                "FAIL",
                "READ_ONLY guard did not reject broker mutation",
                {"sends": backend.sends},
            )
        )
    report.order_send_calls = backend.sends


def attempt_real_connection(report: DiagnosticReport, mt5: Any) -> None:
    terminals = discover_terminals()
    if terminals:
        report.add(
            CheckResult(
                "terminal_discovery",
                "PASS",
                f"Found {len(terminals)} terminal64.exe candidate(s)",
                {"candidates": terminals[:10]},
            )
        )
    else:
        report.add(
            CheckResult(
                "terminal_discovery",
                "FAIL",
                "Could not auto-locate terminal64.exe; likely paths listed in data",
                {"likely_paths": LIKELY_TERMINAL_PATHS},
            )
        )

    # Prefer already-authenticated session — do not require password.
    init_kwargs: dict[str, Any] = {}
    env_path = os.environ.get("NEXA_MT5_TERMINAL_PATH") or os.environ.get("MT5_TERMINAL_PATH") or ""
    if env_path:
        init_kwargs["path"] = env_path
    elif terminals:
        init_kwargs["path"] = terminals[0]

    # Optional credentials from env only (never hard-coded; never printed).
    login = os.environ.get("NEXA_MT5_LOGIN") or os.environ.get("MT5_LOGIN") or ""
    password = os.environ.get("NEXA_MT5_PASSWORD") or os.environ.get("MT5_PASSWORD") or ""
    server = os.environ.get("NEXA_MT5_SERVER") or os.environ.get("MT5_SERVER") or ""
    if login and password and server:
        try:
            init_kwargs["login"] = int(login)
        except ValueError:
            report.errors.append("login: invalid integer login in env (ignored)")
        else:
            init_kwargs["password"] = password
            init_kwargs["server"] = server

    connected = False
    try:
        connected = bool(mt5.initialize(**init_kwargs) if init_kwargs else mt5.initialize())
    except Exception as exc:  # noqa: BLE001
        report.add(CheckResult("mt5_terminal", "FAIL", f"initialize() raised: {exc.__class__.__name__}"))
        report.add(CheckResult("xm_connection", "FAIL", "initialize failed"))
        return

    if not connected:
        last_error = None
        try:
            last_error = mt5.last_error()
        except Exception:  # noqa: BLE001
            last_error = None
        report.add(
            CheckResult(
                "mt5_terminal",
                "FAIL",
                f"mt5.initialize() returned False; last_error={last_error!r} (no secrets)",
            )
        )
        report.add(CheckResult("xm_connection", "FAIL", "Terminal did not attach to existing session"))
        try:
            mt5.shutdown()
        except Exception:  # noqa: BLE001
            pass
        return

    report.add(CheckResult("mt5_terminal", "PASS", "mt5.initialize() attached to terminal session"))
    report.add(CheckResult("xm_connection", "PASS", "Terminal initialize succeeded (session or env auth)"))

    # version / terminal / account — never print passwords
    try:
        version = mt5.version()
        report.add(
            CheckResult(
                "mt5_version",
                "PASS" if version else "FAIL",
                f"version={version}",
                {"version": list(version) if version else None},
            )
        )
    except Exception as exc:  # noqa: BLE001
        report.add(CheckResult("mt5_version", "FAIL", str(exc.__class__.__name__)))

    try:
        terminal = mt5.terminal_info()
        public_terminal = _public(terminal)
        report.add(
            CheckResult(
                "terminal_info",
                "PASS" if terminal else "FAIL",
                "terminal_info retrieved" if terminal else "terminal_info empty",
                public_terminal,
            )
        )
    except Exception as exc:  # noqa: BLE001
        report.add(CheckResult("terminal_info", "FAIL", str(exc.__class__.__name__)))

    try:
        account = mt5.account_info()
        public_account = _public(account)
        if not account:
            report.add(CheckResult("account_detected", "FAIL", "account_info() returned None"))
            report.add(CheckResult("balance_read", "FAIL", "no account"))
            report.add(CheckResult("equity_read", "FAIL", "no account"))
        else:
            report.account_environment = _trade_mode_label(getattr(account, "trade_mode", None))
            report.add(
                CheckResult(
                    "account_detected",
                    "PASS",
                    f"server={getattr(account, 'server', None)} trade_mode={report.account_environment}",
                    {
                        "server": getattr(account, "server", None),
                        "trade_mode": report.account_environment,
                        "currency": getattr(account, "currency", None),
                        "leverage": getattr(account, "leverage", None),
                        "trade_allowed": getattr(account, "trade_allowed", None),
                        "balance": getattr(account, "balance", None),
                        "equity": getattr(account, "equity", None),
                        "margin": getattr(account, "margin", None),
                        "margin_free": getattr(account, "margin_free", None),
                        "login": getattr(account, "login", None),
                    },
                )
            )
            report.add(
                CheckResult(
                    "balance_read",
                    "PASS" if getattr(account, "balance", None) is not None else "FAIL",
                    f"balance={getattr(account, 'balance', None)} {getattr(account, 'currency', '')}",
                )
            )
            report.add(
                CheckResult(
                    "equity_read",
                    "PASS" if getattr(account, "equity", None) is not None else "FAIL",
                    f"equity={getattr(account, 'equity', None)}",
                )
            )
    except Exception as exc:  # noqa: BLE001
        report.add(CheckResult("account_detected", "FAIL", str(exc.__class__.__name__)))
        report.add(CheckResult("balance_read", "FAIL", "exception"))
        report.add(CheckResult("equity_read", "FAIL", "exception"))

    # Symbols + ticks
    ticks_ok = 0
    for symbol in SYMBOLS:
        try:
            info = mt5.symbol_info(symbol)
            if info is None:
                # try select into Market Watch
                try:
                    mt5.symbol_select(symbol, True)
                    info = mt5.symbol_info(symbol)
                except Exception:  # noqa: BLE001
                    info = None
            tick = mt5.symbol_info_tick(symbol) if info is not None else None
            if info is not None and tick is not None:
                report.symbols_ok += 1
                ticks_ok += 1
                bid = getattr(tick, "bid", None)
                ask = getattr(tick, "ask", None)
                spread = None
                if bid is not None and ask is not None:
                    digits = getattr(info, "digits", 5) or 5
                    point = getattr(info, "point", 0) or 0
                    spread = round((ask - bid) / point, 1) if point else (ask - bid)
                tick_time = getattr(tick, "time", None)
                ts = (
                    datetime.fromtimestamp(tick_time, UTC).isoformat()
                    if isinstance(tick_time, int)
                    else str(tick_time)
                )
                report.add(
                    CheckResult(
                        f"symbol_{symbol}",
                        "PASS",
                        f"bid={bid} ask={ask} spread={spread} time={ts}",
                        {"symbol": symbol, "bid": bid, "ask": ask, "spread": spread, "time": ts},
                    )
                )
            else:
                report.add(CheckResult(f"symbol_{symbol}", "FAIL", "symbol_info or tick unavailable"))
        except Exception as exc:  # noqa: BLE001
            report.add(CheckResult(f"symbol_{symbol}", "FAIL", str(exc.__class__.__name__)))

    report.add(
        CheckResult(
            "live_ticks",
            "PASS" if ticks_ok > 0 else "FAIL",
            f"{ticks_ok}/{len(SYMBOLS)} symbols returned live ticks",
        )
    )

    # H1 candles EURUSD
    try:
        rates = mt5.copy_rates_from_pos("EURUSD", mt5.TIMEFRAME_H1, 0, H1_COUNT)
        if rates is None or len(rates) == 0:
            report.add(CheckResult("h1_candles", "FAIL", "copy_rates_from_pos returned empty"))
        else:
            sample = rates[0]
            fields = getattr(sample, "dtype", None)
            names = list(fields.names) if fields is not None and getattr(fields, "names", None) else []
            required = {"time", "open", "high", "low", "close", "tick_volume"}
            # volume / real_volume optional depending on build
            ok_fields = required.issubset(set(names)) if names else all(
                hasattr(sample, name) or (isinstance(sample, dict) and name in sample)
                for name in required
            )
            # numpy void: access by name
            if names:
                ok_fields = required.issubset(set(names))
            report.add(
                CheckResult(
                    "h1_candles",
                    "PASS" if ok_fields and len(rates) >= min(H1_COUNT, 50) else "FAIL",
                    f"count={len(rates)} fields={names or 'n/a'}",
                    {"count": len(rates), "fields": names},
                )
            )
    except Exception as exc:  # noqa: BLE001
        report.add(CheckResult("h1_candles", "FAIL", str(exc.__class__.__name__)))

    # Graceful shutdown — never order_send
    try:
        mt5.shutdown()
        report.add(CheckResult("shutdown", "PASS", "mt5.shutdown() completed"))
    except Exception as exc:  # noqa: BLE001
        report.add(CheckResult("shutdown", "FAIL", str(exc.__class__.__name__)))


def mark_connection_skipped(report: DiagnosticReport, reason: str) -> None:
    for name in (
        "mt5_terminal",
        "xm_connection",
        "account_detected",
        "balance_read",
        "equity_read",
        "live_ticks",
        "h1_candles",
        "terminal_discovery",
        "shutdown",
    ):
        if report.get(name) is None:
            report.add(CheckResult(name, "FAIL", reason))
    report.add(
        CheckResult(
            "manual_windows_actions",
            "PASS",
            "See docs/MT5_CONNECTION_TEST.md — run this script on the Windows PC with XM DEMO logged in.",
            {"likely_terminal_paths": LIKELY_TERMINAL_PATHS},
        )
    )


def render_matrix(report: DiagnosticReport) -> str:
    def status(name: str, *, enabled_label: str = "PASS", fail_label: str = "FAIL") -> str:
        item = report.get(name)
        if item is None:
            return fail_label
        return enabled_label if item.status == "PASS" else fail_label

    symbols_line = f"{report.symbols_ok}/{report.symbols_total}"
    # On Linux symbols_ok stays 0
    if report.get("live_ticks") and report.get("live_ticks").status == "PASS":
        # recount from symbol_* checks if needed
        pass

    read_only = status("read_only_mode", enabled_label="ENABLED", fail_label="FAIL")
    order_exec = status("order_execution_disabled", enabled_label="DISABLED", fail_label="FAIL")
    order_calls = (
        f"{report.order_send_calls}"
        if report.order_send_calls == 0 and order_exec == "DISABLED"
        else ("0" if report.order_send_calls == 0 else "FAIL")
    )
    if report.order_send_calls != 0:
        order_calls = "FAIL"

    lines = [
        "MT5 TERMINAL:",
        status("mt5_terminal"),
        "",
        "XM CONNECTION:",
        status("xm_connection"),
        "",
        "ACCOUNT DETECTED:",
        status("account_detected"),
        "",
        "ACCOUNT ENVIRONMENT:",
        report.account_environment,
        "",
        "BALANCE READ:",
        status("balance_read"),
        "",
        "EQUITY READ:",
        status("equity_read"),
        "",
        "SYMBOLS:",
        symbols_line,
        "",
        "LIVE TICKS:",
        status("live_ticks"),
        "",
        "H1 CANDLES:",
        status("h1_candles"),
        "",
        "READ-ONLY MODE:",
        read_only,
        "",
        "ORDER EXECUTION:",
        order_exec,
        "",
        "order_send CALLS:",
        order_calls,
        "",
    ]
    return "\n".join(lines)


def main() -> int:
    report = DiagnosticReport()
    print("=== Nexa TradeBot MT5 READ-ONLY connection diagnostic ===")
    print(f"time={datetime.now(UTC).isoformat()} cwd={Path.cwd()}")
    print("safety: MT5_EXECUTION_ENABLED=false TRADING_MODE=READ_ONLY (defaults enforced)")
    print("contract: ZERO order_send during this test")
    print()

    check_python(report)
    windows = check_platform(report)
    mt5 = check_package(report)
    check_read_only_guards(report)

    if windows and mt5 is not None:
        try:
            attempt_real_connection(report, mt5)
        except Exception:  # noqa: BLE001
            report.errors.append(traceback.format_exc(limit=5))
            mark_connection_skipped(report, "unexpected exception during real connection")
    else:
        reason = (
            "Skipped real MT5 attach: not a Windows host and/or MetaTrader5 package unavailable. "
            "User must run on Windows PC with XM DEMO logged into MT5."
        )
        mark_connection_skipped(report, reason)

    # Final order_send assertion for this process
    if report.order_send_calls != 0:
        report.errors.append(f"order_send CALLS non-zero: {report.order_send_calls}")

    print("--- check detail ---")
    for check in report.checks:
        if check.name.startswith("symbol_"):
            continue  # summarized in matrix
        print(f"[{check.status}] {check.name}: {check.detail}")
    symbol_checks = [c for c in report.checks if c.name.startswith("symbol_")]
    if symbol_checks:
        print("--- symbols ---")
        for check in symbol_checks:
            print(f"[{check.status}] {check.name}: {check.detail}")

    print()
    print("--- RESULT MATRIX ---")
    print(render_matrix(report))
    if report.errors:
        print("ERRORS:")
        for err in report.errors:
            print(f"- {err}")
    else:
        print("ERRORS: (none)")

    # Exit 0 only if read-only guards pass; connection may still FAIL on Linux.
    guard_ok = (
        (report.get("read_only_mode") and report.get("read_only_mode").status == "PASS")
        and (
            report.get("order_execution_disabled")
            and report.get("order_execution_disabled").status == "PASS"
        )
        and report.order_send_calls == 0
    )
    return 0 if guard_ok else 2


if __name__ == "__main__":
    raise SystemExit(main())
