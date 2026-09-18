from fastapi.testclient import TestClient

from nexa_mt5.api import create_app
from nexa_mt5.config import Settings
from nexa_mt5.connectors import MockMT5Connector
from nexa_mt5.market_data import MarketDataEngine
from nexa_mt5.service import MT5ReadService

TOKEN = "local-test-token"


def client() -> TestClient:
    return TestClient(
        create_app(
            Settings(
                service_token=TOKEN,
                backoff_initial_seconds=0,
                stale_after_seconds=15,
            ),
            MockMT5Connector(),
        )
    )


def auth() -> dict[str, str]:
    return {"Authorization": f"Bearer {TOKEN}"}


def test_market_snapshot_includes_quality_and_freshness() -> None:
    with client() as api:
        response = api.get("/v1/market/snapshot?candle_count=5", headers=auth())
        assert response.status_code == 200
        payload = response.json()
        data = payload["data"]
        assert data["read_only"] is True
        assert data["environment"] == "DEMO"
        assert len(data["quotes"]) >= 2
        quote = data["quotes"][0]
        assert quote["quality"]["usable"] is True
        assert quote["freshness"]["status"] == "FRESH"
        assert "spread" in quote
        assert data["summary"]["extension_hooks"]["phase_6_indicator_engine"] == "PENDING"
        assert len(data["candles"]["bars"]) == 5
        assert payload["meta"]["freshness"] in {"FRESH", "STALE"}


def test_market_quote_marks_stale_data() -> None:
    settings = Settings(service_token=TOKEN, stale_after_seconds=1, backoff_initial_seconds=0)
    connector = MockMT5Connector()
    service = MT5ReadService(settings, connector)
    engine = MarketDataEngine(service, settings)
    service.connect()
    # Force an old source timestamp through normalization.
    stale = engine.normalize_quote({
        "symbol": "EURUSD",
        "bid": "1.10000",
        "ask": "1.10020",
        "time": "2020-01-01T00:00:00+00:00",
    })
    assert stale["freshness"]["is_stale"] is True
    assert "STALE" in stale["quality"]["issues"]
    assert stale["quality"]["score"] < 100


def test_market_rejects_inverted_spread() -> None:
    settings = Settings(service_token=TOKEN, backoff_initial_seconds=0)
    engine = MarketDataEngine(MT5ReadService(settings, MockMT5Connector()), settings)
    bad = engine.normalize_quote({
        "symbol": "EURUSD",
        "bid": "1.20000",
        "ask": "1.10000",
        "time": "2026-09-18T12:00:00+00:00",
    })
    assert bad["quality"]["usable"] is False
    assert "INVERTED_SPREAD" in bad["quality"]["issues"]


def test_market_candle_quality_and_endpoints() -> None:
    with client() as api:
        candles = api.get("/v1/market/candles/EURUSD?timeframe=M5&count=3", headers=auth())
        assert candles.status_code == 200
        bars = candles.json()["data"]
        assert len(bars) == 3
        assert bars[0]["quality"]["usable"] is True
        symbols = api.get("/v1/market/symbols", headers=auth()).json()["data"]
        assert any(item["symbol"] == "EURUSD" for item in symbols)
        quotes = api.get("/v1/market/quotes?symbols=EURUSD,XAUUSD", headers=auth())
        assert len(quotes.json()["data"]) == 2
