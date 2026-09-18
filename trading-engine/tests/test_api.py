from datetime import UTC, datetime, timedelta

from fastapi.testclient import TestClient

from nexa_mt5.api import create_app
from nexa_mt5.config import Settings
from nexa_mt5.connectors import MockMT5Connector

TOKEN = "local-test-token"


def client() -> TestClient:
    return TestClient(
        create_app(
            Settings(
                service_token=TOKEN,
                backoff_initial_seconds=0,
                stale_after_seconds=1,
            ),
            MockMT5Connector(),
        )
    )


def auth() -> dict[str, str]:
    return {"Authorization": f"Bearer {TOKEN}"}


def test_authentication_rejects_missing_and_wrong_tokens() -> None:
    with client() as api:
        assert api.get("/v1/health").status_code == 401
        response = api.get("/v1/health", headers={"Authorization": "Bearer wrong"})
        assert response.status_code == 401
        assert response.json()["error"]["code"] == "AUTHENTICATION_REQUIRED"


def test_health_terminal_account_symbols_and_heartbeat() -> None:
    with client() as api:
        health = api.get("/v1/health", headers=auth())
        assert health.status_code == 200
        assert health.json()["data"]["read_only"] is True
        terminal = api.get("/v1/terminal", headers=auth()).json()["data"]["terminal"]
        assert terminal["trade_allowed"] is False
        account = api.get("/v1/account", headers=auth()).json()
        assert account["data"]["trade_mode"] == "DEMO"
        assert account["meta"]["environment"] == "DEMO"
        assert len(api.get("/v1/symbols", headers=auth()).json()["data"]) == 6
        assert api.get("/v1/symbols/EURUSD", headers=auth()).status_code == 200
        assert api.get("/v1/heartbeat", headers=auth()).status_code == 200


def test_quote_and_candle_validation() -> None:
    with client() as api:
        quote = api.get("/v1/quotes/EURUSD", headers=auth())
        assert quote.json()["data"]["bid"] == "1.10000"
        candles = api.get("/v1/candles/EURUSD?timeframe=M5&count=3", headers=auth())
        assert len(candles.json()["data"]) == 3
        assert api.get("/v1/candles/EURUSD?timeframe=BAD", headers=auth()).status_code == 422
        assert api.get("/v1/quotes/%25bad", headers=auth()).status_code == 422
        assert api.get("/v1/quotes/UNKNOWN", headers=auth()).status_code == 404


def test_read_only_positions_orders_and_bounded_history() -> None:
    with client() as api:
        assert api.get("/v1/positions", headers=auth()).json()["data"][0]["ticket"] == 70001
        assert api.get("/v1/orders", headers=auth()).json()["data"][0]["ticket"] == 71001
        start = (datetime.now(UTC) - timedelta(days=2)).strftime('%Y-%m-%dT%H:%M:%SZ')
        end = datetime.now(UTC).strftime('%Y-%m-%dT%H:%M:%SZ')
        orders = api.get(
            f"/v1/history/orders?date_from={start}&date_to={end}&limit=999",
            headers=auth(),
        )
        assert orders.status_code == 200
        assert len(orders.json()["data"]) <= 500
        assert api.get(
            "/v1/history/deals?date_from=2020-01-01T00:00:00Z"
            "&date_to=2021-01-01T00:00:00Z",
            headers=auth(),
        ).status_code == 422


def test_responses_never_expose_credentials_or_paths() -> None:
    settings = Settings(
        service_token="visible-test-token",
        password="visible-test-password",
        terminal_path="C:/private/terminal.exe",
        login=12345,
    )
    with TestClient(create_app(settings, MockMT5Connector())) as api:
        payloads = [
            api.get(path, headers={"Authorization": "Bearer visible-test-token"}).text
            for path in ("/v1/health", "/v1/terminal", "/v1/account")
        ]
    combined = "\n".join(payloads)
    assert "visible-test-token" not in combined
    assert "visible-test-password" not in combined
    assert "C:/private" not in combined
    assert "traceback" not in combined.lower()
