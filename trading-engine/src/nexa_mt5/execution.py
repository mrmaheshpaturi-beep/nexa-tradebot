"""DEMO execution module.

AUTHORIZED order_send LOCATION (sole application-owned call site):
  nexa_mt5.execution.authorized_order_send

order_check MUST succeed before authorized_order_send is invoked.
LIVE / UNKNOWN trade modes hard-fail. CI uses Mock write path only.
"""

from __future__ import annotations

import hmac
import time
from collections.abc import Callable
from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any

from .errors import BridgeError, ErrorCode

# In-memory replay cache for nonce+idempotency (process-local).
_SEEN_NONCES: dict[str, float] = {}
_REPLAY_TTL_SECONDS = 300


class ErrorCodeExt:
    LIVE_HARD_FAIL = "LIVE_HARD_FAIL"
    UNKNOWN_ACCOUNT = "UNKNOWN_ACCOUNT_MODE"
    REPLAY_DETECTED = "REPLAY_DETECTED"
    ORDER_CHECK_FAILED = "ORDER_CHECK_FAILED"
    DEMO_REQUIRED = "DEMO_REQUIRED"


def _normalize_trade_mode(value: Any) -> str:
    raw = str(value).strip().upper()
    if raw in {"0", "DEMO", "ACCOUNT_TRADE_MODE_DEMO"}:
        return "DEMO"
    if raw in {"2", "LIVE", "REAL", "ACCOUNT_TRADE_MODE_REAL"}:
        return "LIVE"
    if raw in {"1", "CONTEST", "ACCOUNT_TRADE_MODE_CONTEST"}:
        return "CONTEST"
    return "UNKNOWN"


def independent_demo_verification(account: dict[str, Any]) -> dict[str, Any]:
    mode = _normalize_trade_mode(account.get("trade_mode"))
    if mode == "LIVE":
        raise BridgeError(ErrorCode.INVALID_REQUEST, "LIVE trade mode hard-fail at bridge DEMO verification.", 403)
    if mode != "DEMO":
        raise BridgeError(ErrorCode.INVALID_REQUEST, "UNKNOWN/unsupported trade mode hard-fail at bridge.", 403)
    login = str(account.get("account_id") or account.get("login") or "")
    server = str(account.get("server") or "")
    if not login or not server:
        raise BridgeError(ErrorCode.INVALID_REQUEST, "DEMO verification requires login and server.", 422)
    return {
        "trade_mode": "DEMO",
        "login": login,
        "server": server,
        "verified_at": datetime.now(UTC).isoformat(),
        "layer": "BRIDGE_INDEPENDENT",
    }


def assert_replay_safe(nonce: str, idempotency_key: str, timestamp: str, max_skew_seconds: int = 60) -> None:
    if not nonce or not idempotency_key or not timestamp:
        raise BridgeError(ErrorCode.INVALID_REQUEST, "nonce, idempotency_key, and timestamp are required.", 422)
    try:
        ts = int(timestamp)
    except ValueError as exc:
        raise BridgeError(ErrorCode.INVALID_REQUEST, "Invalid timestamp.", 422) from exc
    now = int(time.time())
    if abs(now - ts) > max_skew_seconds:
        raise BridgeError(ErrorCode.INVALID_REQUEST, "Request timestamp outside replay window.", 422)
    key = f"{nonce}|{idempotency_key}"
    # purge
    expired = [k for k, seen in _SEEN_NONCES.items() if now - seen > _REPLAY_TTL_SECONDS]
    for item in expired:
        _SEEN_NONCES.pop(item, None)
    if key in _SEEN_NONCES:
        raise BridgeError(ErrorCode.INVALID_REQUEST, "Replay detected for nonce/idempotency key.", 409)
    _SEEN_NONCES[key] = float(now)


@dataclass
class OrderCheckResult:
    ok: bool
    retcode: str
    comment: str
    payload: dict[str, Any]


def order_check(request: dict[str, Any], checker: Callable[[dict[str, Any]], dict[str, Any]]) -> OrderCheckResult:
    raw = checker(request)
    retcode = str(raw.get("retcode", "1"))
    ok = retcode in {"0", "10009"}
    return OrderCheckResult(ok=ok, retcode=retcode, comment=str(raw.get("comment", "")), payload=raw)


def authorized_order_send(
    request: dict[str, Any],
    sender: Callable[[dict[str, Any]], dict[str, Any]],
    *,
    order_check_passed: bool,
    verification: dict[str, Any],
) -> dict[str, Any]:
    """SOLE authorized order_send entrypoint for Nexa TradeBot.

    Path: trading-engine/src/nexa_mt5/execution.py::authorized_order_send
    """
    if not order_check_passed:
        raise BridgeError(ErrorCode.INVALID_REQUEST, "order_send forbidden before successful order_check.", 409)
    if verification.get("trade_mode") != "DEMO":
        raise BridgeError(ErrorCode.INVALID_REQUEST, "order_send requires verified DEMO trade mode.", 403)
    if (request.get("account") or {}).get("trade_mode") != "DEMO":
        raise BridgeError(ErrorCode.INVALID_REQUEST, "order_send request account.trade_mode must be DEMO.", 403)
    return sender(request)


class MockDemoExecutionBackend:
    """CI / non-Windows fake. Never imports MetaTrader5."""

    def __init__(self) -> None:
        self.sends = 0

    def account(self) -> dict[str, Any]:
        return {
            "account_id": 900001,
            "server": "Nexa-Demo",
            "trade_mode": "DEMO",
            "currency": "USD",
            "source": "MOCK_DEMO_EXECUTION",
        }

    def check(self, request: dict[str, Any]) -> dict[str, Any]:
        if (request.get("account") or {}).get("trade_mode") != "DEMO":
            return {"retcode": "10017", "comment": "TRADE_DISABLED"}
        return {"retcode": "0", "comment": "MOCK_ORDER_CHECK_OK"}

    def send(self, request: dict[str, Any]) -> dict[str, Any]:
        self.sends += 1
        return {
            "retcode": "10009",
            "order": 800000 + self.sends,
            "deal": 900000 + self.sends,
            "volume": request.get("volume"),
            "price": request.get("price"),
            "comment": "MOCK_ORDER_SEND",
        }


class RealDemoExecutionBackend:
    """Windows-only real MT5 path. Sole MetaTrader5.order_send call lives here via authorized_order_send."""

    def __init__(self, mt5: Any) -> None:
        self._mt5 = mt5

    def account(self) -> dict[str, Any]:
        info = self._mt5.account_info()
        if info is None:
            raise BridgeError(ErrorCode.DISCONNECTED, "No MT5 account info.")
        trade_mode = getattr(info, "trade_mode", None)
        # MetaTrader5 ACCOUNT_TRADE_MODE_* ints
        mode_map = {0: "DEMO", 1: "CONTEST", 2: "LIVE"}
        return {
            "account_id": getattr(info, "login", None),
            "server": getattr(info, "server", None),
            "trade_mode": mode_map.get(int(trade_mode) if trade_mode is not None else -1, "UNKNOWN"),
            "currency": getattr(info, "currency", None),
            "source": "MT5_REAL",
        }

    def check(self, request: dict[str, Any]) -> dict[str, Any]:
        mt5 = self._mt5
        result = mt5.order_check(self._build_request(request))
        if result is None:
            return {"retcode": "1", "comment": "ORDER_CHECK_NONE"}
        return {"retcode": str(result.retcode), "comment": str(getattr(result, "comment", ""))}

    def send(self, request: dict[str, Any]) -> dict[str, Any]:
        mt5 = self._mt5
        # *** SOLE MetaTrader5.order_send CALL SITE ***
        result = mt5.order_send(self._build_request(request))
        if result is None:
            return {"retcode": "10012", "comment": "ORDER_SEND_TIMEOUT_UNKNOWN"}
        return {
            "retcode": str(result.retcode),
            "order": getattr(result, "order", None),
            "deal": getattr(result, "deal", None),
            "volume": str(getattr(result, "volume", request.get("volume"))),
            "price": str(getattr(result, "price", request.get("price"))),
            "comment": str(getattr(result, "comment", "")),
        }

    def _build_request(self, request: dict[str, Any]) -> dict[str, Any]:
        mt5 = self._mt5
        action = str(request.get("action") or "PLACE_ORDER").upper()
        side = str(request.get("side", "BUY")).upper()
        if action == "MODIFY_POSITION_PROTECTION":
            return {
                "action": mt5.TRADE_ACTION_SLTP,
                "symbol": request["symbol"],
                "position": int(request.get("position_id") or request.get("broker_position_id")),
                "sl": float(request["stop_loss"]) if request.get("stop_loss") else 0.0,
                "tp": float(request["take_profit"]) if request.get("take_profit") else 0.0,
                "magic": int(request.get("magic", 10010)),
                "comment": str(request.get("comment", "NEXA-MGMT"))[:31],
            }
        if action in {"CLOSE_POSITION", "PARTIAL_CLOSE"}:
            close_type = mt5.ORDER_TYPE_SELL if side == "BUY" else mt5.ORDER_TYPE_BUY
            return {
                "action": mt5.TRADE_ACTION_DEAL,
                "symbol": request["symbol"],
                "volume": float(request.get("close_volume") or request.get("volume") or 0),
                "type": close_type,
                "position": int(request.get("position_id") or request.get("broker_position_id")),
                "price": float(request.get("price") or 0),
                "deviation": int(request.get("deviation", 20)),
                "magic": int(request.get("magic", 10010)),
                "comment": str(request.get("comment", "NEXA-CLOSE"))[:31],
                "type_filling": mt5.ORDER_FILLING_IOC,
            }
        if action == "CANCEL_PENDING":
            return {
                "action": mt5.TRADE_ACTION_REMOVE,
                "order": int(request.get("order_id")),
                "magic": int(request.get("magic", 10010)),
                "comment": str(request.get("comment", "NEXA-CANCEL"))[:31],
            }
        order_type = str(request.get("order_type", "MARKET")).upper()
        type_map = {
            ("BUY", "MARKET"): mt5.ORDER_TYPE_BUY,
            ("SELL", "MARKET"): mt5.ORDER_TYPE_SELL,
            ("BUY", "BUY_LIMIT"): mt5.ORDER_TYPE_BUY_LIMIT,
            ("SELL", "SELL_LIMIT"): mt5.ORDER_TYPE_SELL_LIMIT,
            ("BUY", "BUY_STOP"): mt5.ORDER_TYPE_BUY_STOP,
            ("SELL", "SELL_STOP"): mt5.ORDER_TYPE_SELL_STOP,
        }
        mt5_type = type_map.get((side, order_type))
        if mt5_type is None:
            raise BridgeError(ErrorCode.INVALID_REQUEST, "Unsupported DEMO order type/side.", 422)
        return {
            "action": mt5.TRADE_ACTION_DEAL if order_type == "MARKET" else mt5.TRADE_ACTION_PENDING,
            "symbol": request["symbol"],
            "volume": float(request["volume"]),
            "type": mt5_type,
            "price": float(request["price"]),
            "sl": float(request["stop_loss"]) if request.get("stop_loss") else 0.0,
            "tp": float(request["take_profit"]) if request.get("take_profit") else 0.0,
            "deviation": int(request.get("deviation", 20)),
            "magic": int(request.get("magic", 10010)),
            "comment": str(request.get("comment", "NEXA-DEMO"))[:31],
            "type_filling": mt5.ORDER_FILLING_IOC,
        }


def execute_demo_check_and_send(
    backend: MockDemoExecutionBackend | RealDemoExecutionBackend,
    request: dict[str, Any],
    *,
    nonce: str,
    idempotency_key: str,
    timestamp: str,
    correlation_id: str,
) -> dict[str, Any]:
    assert_replay_safe(nonce, idempotency_key, timestamp)
    account = backend.account()
    # Merge request account server if provided for verification completeness.
    if request.get("account", {}).get("server") and not account.get("server"):
        account["server"] = request["account"]["server"]
    if request.get("account", {}).get("login") and not account.get("account_id"):
        account["account_id"] = request["account"]["login"]
    if not account.get("server") and request.get("account", {}).get("server"):
        account["server"] = request["account"]["server"]
    verification = independent_demo_verification(account)

    check = order_check(request, backend.check)
    if not check.ok:
        return {
            "check": {"retcode": check.retcode, "comment": check.comment},
            "send": None,
            "outcome": "REJECTED",
            "retcode": check.retcode,
            "correlation_id": correlation_id,
            "verification": verification,
        }

    try:
        send = authorized_order_send(
            request,
            backend.send,
            order_check_passed=True,
            verification=verification,
        )
    except BridgeError:
        raise
    except Exception as exc:  # noqa: BLE001 — map to UNKNOWN without blind retry
        return {
            "check": {"retcode": check.retcode, "comment": check.comment},
            "send": None,
            "outcome": "TIMEOUT_UNKNOWN",
            "retcode": "10012",
            "correlation_id": correlation_id,
            "verification": verification,
            "error": "PROVIDER_TIMEOUT",
            "detail": str(exc.__class__.__name__),
        }

    retcode = str(send.get("retcode", ""))
    if retcode == "10012":
        outcome = "TIMEOUT_UNKNOWN"
    elif retcode == "10010":
        outcome = "PARTIALLY_FILLED"
    elif retcode == "10009":
        outcome = "FILLED"
    else:
        outcome = "REJECTED"

    return {
        "check": {"retcode": check.retcode, "comment": check.comment},
        "send": send,
        "outcome": outcome,
        "retcode": retcode,
        "correlation_id": correlation_id,
        "verification": verification,
        "position_id": str(send.get("order") or ""),
        "hedging": True,
        "netting": False,
        "authorized_order_send": "nexa_mt5.execution.authorized_order_send",
    }


def constant_time_token_eq(presented: str, expected: str) -> bool:
    return bool(expected) and hmac.compare_digest(presented.encode("utf-8"), expected.encode("utf-8"))


ALLOWED_MANAGEMENT_ACTIONS = {
    "MODIFY_POSITION_PROTECTION",
    "CLOSE_POSITION",
    "PARTIAL_CLOSE",
    "CANCEL_PENDING",
    "PLACE_ORDER",
}


def execute_demo_management_action(
    backend: MockDemoExecutionBackend | RealDemoExecutionBackend,
    request: dict[str, Any],
    *,
    nonce: str,
    idempotency_key: str,
    timestamp: str,
    correlation_id: str,
) -> dict[str, Any]:
    """DEMO management actions — still sole authorized_order_send; no generic arbitrary payload API."""
    action = str(request.get("action") or "").upper()
    if action not in ALLOWED_MANAGEMENT_ACTIONS:
        raise BridgeError(ErrorCode.INVALID_REQUEST, f"Unsupported management action: {action}", 422)
    # Independent DEMO verification at lowest broker layer before ANY broker-changing action.
    result = execute_demo_check_and_send(
        backend,
        request,
        nonce=nonce,
        idempotency_key=idempotency_key,
        timestamp=timestamp,
        correlation_id=correlation_id,
    )
    result["action"] = action
    return result
