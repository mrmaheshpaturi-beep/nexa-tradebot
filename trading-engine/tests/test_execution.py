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
