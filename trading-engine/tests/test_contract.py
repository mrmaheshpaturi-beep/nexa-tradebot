import inspect
from datetime import UTC, datetime
from pathlib import Path

import pytest

from nexa_mt5.config import Settings
from nexa_mt5.connectors import MT5Connector, MockMT5Connector, RealMT5Connector
from nexa_mt5.errors import BridgeError
from nexa_mt5.service import MT5ReadService


def test_connector_contract_parity() -> None:
    contract = {
        name
        for name, value in inspect.getmembers(MT5Connector, inspect.isfunction)
        if not name.startswith("_")
    }
    assert contract == {
        name
        for name, value in inspect.getmembers(MockMT5Connector, inspect.isfunction)
        if not name.startswith("_") and name in contract
    }
    assert contract == {
        name
        for name, value in inspect.getmembers(RealMT5Connector, inspect.isfunction)
        if not name.startswith("_") and name in contract
    }


def test_mock_contract_is_read_only_and_normalized() -> None:
    connector = MockMT5Connector()
    assert connector.initialize()
    assert connector.account()["trade_mode"] == "DEMO"
    assert connector.symbol_info("EURUSD") is not None
    assert connector.tick("EURUSD")["bid"] == "1.10000"  # type: ignore[index]
    assert len(connector.rates("EURUSD", "M1", 2)) == 2
    assert connector.positions()[0]["ticket"] == 70001
    assert connector.orders()[0]["ticket"] == 71001
    now = datetime.now(UTC)
    assert isinstance(connector.history_orders(now, now, 10), list)
    assert isinstance(connector.history_deals(now, now, 10), list)
    connector.shutdown()


def test_real_connector_rejects_non_windows() -> None:
    with pytest.raises(BridgeError, match="Windows"):
        RealMT5Connector(Settings(service_token="test")).initialize()


def test_bounded_backoff_and_safe_disconnect() -> None:
    class FailingConnector(MockMT5Connector):
        def initialize(self) -> bool:
            return False

    service = MT5ReadService(
        Settings(
            service_token="test",
            connect_attempts=2,
            backoff_initial_seconds=0,
            backoff_max_seconds=0,
        ),
        FailingConnector(),
    )
    with pytest.raises(BridgeError, match="Unable to connect"):
        service.connect()
    assert service.attempts == 2
    assert service.health()["status"] == "ERROR"
    service.shutdown()
    assert service.health()["status"] == "DISCONNECTED"


def test_source_contains_no_trade_write_api_reference() -> None:
    source = "\n".join(
        path.read_text(encoding="utf-8")
        for path in (Path(__file__).parents[1] / "src").rglob("*.py")
    ).lower()
    forbidden = ["order" + "_send", "position" + "_close", "order" + "_cancel"]
    assert all(term not in source for term in forbidden)
