"""Tests for Indicator Engine pure math and quality gate."""

from __future__ import annotations

from decimal import Decimal

from nexa_mt5.indicators import IndicatorEngine, bollinger, ema, macd, rsi, sma


def _candles(closes: list[str], *, closed: bool = True) -> list[dict]:
    rows = []
    for index, close in enumerate(closes):
        price = Decimal(close)
        rows.append(
            {
                "open_time": f"2026-09-18T10:{index:02d}:00+00:00",
                "open": str(price),
                "high": str(price + Decimal("0.00010")),
                "low": str(price - Decimal("0.00010")),
                "close": str(price),
                "is_closed": closed,
            }
        )
    return rows


def test_sma_ema_basic():
    values = [Decimal(str(v)) for v in range(1, 11)]
    assert sma(values, 3)[2] == Decimal(2)
    assert ema(values, 3)[2] is not None


def test_rsi_macd_bollinger_produce_points():
    values = [Decimal(str(100 + (i % 5) - 2)) for i in range(40)]
    assert any(v is not None for v in rsi(values, 14))
    assert any(row["macd"] is not None for row in macd(values))
    assert any(row["middle"] is not None for row in bollinger(values, 20))


def test_indicator_engine_refuses_bad_quality():
    engine = IndicatorEngine()
    result = engine.compute(
        "SMA",
        _candles([str(1 + i * 0.01) for i in range(30)]),
        {"period": 5},
        instrument="EURUSD",
        quality_gate_allowed=False,
        quality_status="BAD",
    )
    assert result["status"] == "REFUSED"
    assert result["series"] == []
    assert result["execution"]["order_send"] is False


def test_indicator_engine_sma_series_aligned():
    engine = IndicatorEngine()
    candles = _candles([str(1 + i * 0.01) for i in range(25)])
    result = engine.compute(
        "SMA",
        candles,
        {"period": 5},
        instrument="EURUSD",
        timeframe="M5",
        source="MOCK",
        environment="SIMULATION",
    )
    assert result["status"] == "READY"
    assert result["timestamps_aligned_to"] == "candle_open_time"
    assert result["point_count"] == 21
    assert result["series"][0]["time"] == candles[4]["open_time"]
    assert result["values"]["value"] is not None


def test_catalog_lists_core_indicators():
    names = {item["name"] for item in IndicatorEngine().catalog()}
    assert names == {"SMA", "EMA", "RSI", "MACD", "ATR", "BBANDS"}
