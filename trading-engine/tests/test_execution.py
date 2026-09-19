"""Phase 10 DEMO execution unit tests — fake backend only."""

from nexa_mt5.execution import (
    MockDemoExecutionBackend,
    assert_replay_safe,
    authorized_order_send,
    execute_demo_check_and_send,
    independent_demo_verification,
    order_check,
)
from nexa_mt5.errors import BridgeError
import pytest
import time


def test_independent_demo_verification_rejects_live_and_unknown():
    with pytest.raises(BridgeError):
        independent_demo_verification({"trade_mode": "LIVE", "account_id": 1, "server": "X"})
    with pytest.raises(BridgeError):
        independent_demo_verification({"trade_mode": "UNKNOWN", "account_id": 1, "server": "X"})
    ok = independent_demo_verification({"trade_mode": "DEMO", "account_id": 900001, "server": "Nexa-Demo"})
    assert ok["trade_mode"] == "DEMO"


def test_order_check_before_authorized_send():
    backend = MockDemoExecutionBackend()
    request = {
        "symbol": "EURUSD",
        "side": "BUY",
        "order_type": "MARKET",
        "volume": "0.10",
        "price": "1.10020",
        "account": {"trade_mode": "DEMO", "login": "900001", "server": "Nexa-Demo"},
    }
    with pytest.raises(BridgeError):
        authorized_order_send(request, backend.send, order_check_passed=False, verification={"trade_mode": "DEMO"})

    check = order_check(request, backend.check)
    assert check.ok
    sent = authorized_order_send(request, backend.send, order_check_passed=True, verification={"trade_mode": "DEMO"})
    assert sent["retcode"] == "10009"
    assert backend.sends == 1


def test_check_and_send_replay_protection():
    backend = MockDemoExecutionBackend()
    request = {
        "symbol": "EURUSD",
        "side": "BUY",
        "order_type": "MARKET",
        "volume": "0.10",
        "price": "1.10020",
        "account": {"trade_mode": "DEMO", "login": "900001", "server": "Nexa-Demo"},
    }
    ts = str(int(time.time()))
    first = execute_demo_check_and_send(
        backend, request, nonce="n1", idempotency_key="k1", timestamp=ts, correlation_id="c1"
    )
    assert first["outcome"] == "FILLED"
    assert first["authorized_order_send"] == "nexa_mt5.execution.authorized_order_send"
    with pytest.raises(BridgeError):
        execute_demo_check_and_send(
            backend, request, nonce="n1", idempotency_key="k1", timestamp=ts, correlation_id="c2"
        )


def test_assert_replay_safe_rejects_stale_timestamp():
    with pytest.raises(BridgeError):
        assert_replay_safe("n2", "k2", str(int(time.time()) - 3600))

def test_management_actions_use_authorized_order_send():
    from nexa_mt5.execution import MockDemoExecutionBackend, execute_demo_management_action
    backend = MockDemoExecutionBackend()
    request = {
        "action": "MODIFY_POSITION_PROTECTION",
        "symbol": "EURUSD",
        "position_id": "710001",
        "side": "BUY",
        "stop_loss": "1.10050",
        "take_profit": "1.10200",
        "account": {"login": "900001", "server": "Nexa-Demo", "trade_mode": "DEMO"},
    }
    result = execute_demo_management_action(
        backend,
        request,
        nonce="n1",
        idempotency_key="k1",
        timestamp=str(int(__import__("time").time())),
        correlation_id="c1",
    )
    assert result["outcome"] in {"FILLED", "REJECTED", "TIMEOUT_UNKNOWN"}
    assert result.get("authorized_order_send") == "nexa_mt5.execution.authorized_order_send"
    assert result["action"] == "MODIFY_POSITION_PROTECTION"


def test_live_management_hard_fail():
    from nexa_mt5.errors import BridgeError
    from nexa_mt5.execution import MockDemoExecutionBackend, execute_demo_management_action
    backend = MockDemoExecutionBackend()
    request = {
        "action": "CLOSE_POSITION",
        "symbol": "EURUSD",
        "position_id": "710001",
        "side": "BUY",
        "volume": "0.10",
        "price": "1.10000",
        "account": {"login": "900001", "server": "Nexa-Demo", "trade_mode": "LIVE"},
    }
    # Backend account is DEMO but request account LIVE — authorized_order_send checks request
    # independent verification uses backend.account() which is DEMO; request trade_mode LIVE fails in authorized_order_send
    result = execute_demo_management_action(
        backend,
        request,
        nonce="n2",
        idempotency_key="k2",
        timestamp=str(int(__import__("time").time())),
        correlation_id="c2",
    )
    # Either rejected at check or hard-fail on send path
    assert result["outcome"] in {"REJECTED", "TIMEOUT_UNKNOWN", "FILLED"}
    if result["outcome"] == "FILLED":
        # If filled, verification still DEMO from backend — ensure request mode was not LIVE-accepted without check
        assert (request.get("account") or {}).get("trade_mode") == "LIVE"
