"""Indicator Engine — pure closed-candle indicator math (no MT5 / broker I/O)."""

from __future__ import annotations

from datetime import UTC, datetime
from decimal import ROUND_HALF_UP, Decimal
from typing import Any

DecimalLike = Decimal | float | int | str | None


def _d(value: DecimalLike) -> Decimal | None:
    if value is None:
        return None
    try:
        return Decimal(str(value))
    except Exception:
        return None


def _q(value: Decimal | None, places: int = 8) -> str | None:
    if value is None:
        return None
    quant = Decimal("1").scaleb(-places)
    return str(value.quantize(quant, rounding=ROUND_HALF_UP))


def closes(candles: list[dict[str, Any]]) -> list[Decimal]:
    out: list[Decimal] = []
    for candle in candles:
        value = _d(candle.get("close"))
        if value is not None:
            out.append(value)
    return out


def highs(candles: list[dict[str, Any]]) -> list[Decimal]:
    return [v for v in (_d(c.get("high")) for c in candles) if v is not None]


def lows(candles: list[dict[str, Any]]) -> list[Decimal]:
    return [v for v in (_d(c.get("low")) for c in candles) if v is not None]


def sma(values: list[Decimal], period: int) -> list[Decimal | None]:
    if period < 1:
        raise ValueError("period must be >= 1")
    result: list[Decimal | None] = [None] * len(values)
    if len(values) < period:
        return result
    window = sum(values[:period], Decimal(0))
    result[period - 1] = window / Decimal(period)
    for index in range(period, len(values)):
        window += values[index] - values[index - period]
        result[index] = window / Decimal(period)
    return result


def ema(values: list[Decimal], period: int) -> list[Decimal | None]:
    if period < 1:
        raise ValueError("period must be >= 1")
    result: list[Decimal | None] = [None] * len(values)
    if len(values) < period:
        return result
    seed = sum(values[:period], Decimal(0)) / Decimal(period)
    result[period - 1] = seed
    multiplier = Decimal(2) / Decimal(period + 1)
    prev = seed
    for index in range(period, len(values)):
        prev = (values[index] - prev) * multiplier + prev
        result[index] = prev
    return result


def rsi(values: list[Decimal], period: int = 14) -> list[Decimal | None]:
    if period < 1:
        raise ValueError("period must be >= 1")
    result: list[Decimal | None] = [None] * len(values)
    if len(values) <= period:
        return result
    gains = Decimal(0)
    losses = Decimal(0)
    for index in range(1, period + 1):
        delta = values[index] - values[index - 1]
        if delta >= 0:
            gains += delta
        else:
            losses -= delta
    avg_gain = gains / Decimal(period)
    avg_loss = losses / Decimal(period)
    if avg_loss == 0:
        result[period] = Decimal(100)
    else:
        rs = avg_gain / avg_loss
        result[period] = Decimal(100) - (Decimal(100) / (Decimal(1) + rs))
    for index in range(period + 1, len(values)):
        delta = values[index] - values[index - 1]
        gain = delta if delta > 0 else Decimal(0)
        loss = -delta if delta < 0 else Decimal(0)
        avg_gain = ((avg_gain * Decimal(period - 1)) + gain) / Decimal(period)
        avg_loss = ((avg_loss * Decimal(period - 1)) + loss) / Decimal(period)
        if avg_loss == 0:
            result[index] = Decimal(100)
        else:
            rs = avg_gain / avg_loss
            result[index] = Decimal(100) - (Decimal(100) / (Decimal(1) + rs))
    return result


def macd(
    values: list[Decimal],
    fast: int = 12,
    slow: int = 26,
    signal: int = 9,
) -> list[dict[str, Decimal | None]]:
    fast_ema = ema(values, fast)
    slow_ema = ema(values, slow)
    macd_line: list[Decimal | None] = [None] * len(values)
    for index, (fast_v, slow_v) in enumerate(zip(fast_ema, slow_ema, strict=True)):
        if fast_v is not None and slow_v is not None:
            macd_line[index] = fast_v - slow_v
    compact = [v for v in macd_line if v is not None]
    signal_compact = ema(compact, signal)
    signal_line: list[Decimal | None] = [None] * len(values)
    compact_index = 0
    for index, value in enumerate(macd_line):
        if value is None:
            continue
        signal_line[index] = signal_compact[compact_index]
        compact_index += 1
    out: list[dict[str, Decimal | None]] = []
    for index in range(len(values)):
        macd_v = macd_line[index]
        signal_v = signal_line[index]
        hist = macd_v - signal_v if macd_v is not None and signal_v is not None else None
        out.append({"macd": macd_v, "signal": signal_v, "histogram": hist})
    return out


def atr(candles: list[dict[str, Any]], period: int = 14) -> list[Decimal | None]:
    if period < 1:
        raise ValueError("period must be >= 1")
    result: list[Decimal | None] = [None] * len(candles)
    if len(candles) <= period:
        return result
    true_ranges: list[Decimal] = []
    for index, candle in enumerate(candles):
        high = _d(candle.get("high"))
        low = _d(candle.get("low"))
        close = _d(candle.get("close"))
        if high is None or low is None or close is None:
            true_ranges.append(Decimal(0))
            continue
        if index == 0:
            true_ranges.append(high - low)
            continue
        prev_close = _d(candles[index - 1].get("close")) or close
        tr = max(high - low, abs(high - prev_close), abs(low - prev_close))
        true_ranges.append(tr)
    seed = sum(true_ranges[1 : period + 1], Decimal(0)) / Decimal(period)
    result[period] = seed
    prev = seed
    for index in range(period + 1, len(candles)):
        prev = ((prev * Decimal(period - 1)) + true_ranges[index]) / Decimal(period)
        result[index] = prev
    return result


def bollinger(
    values: list[Decimal],
    period: int = 20,
    std_dev: Decimal = Decimal("2"),
) -> list[dict[str, Decimal | None]]:
    mids = sma(values, period)
    out: list[dict[str, Decimal | None]] = []
    for index, mid in enumerate(mids):
        if mid is None:
            out.append({"middle": None, "upper": None, "lower": None})
            continue
        window = values[index - period + 1 : index + 1]
        mean = mid
        variance = sum(((item - mean) ** 2 for item in window), Decimal(0)) / Decimal(period)
        deviation = variance.sqrt() * std_dev
        out.append({"middle": mid, "upper": mid + deviation, "lower": mid - deviation})
    return out


CATALOG: list[dict[str, Any]] = [
    {
        "name": "SMA",
        "label": "Simple Moving Average",
        "overlay": True,
        "params": {"period": 20, "source": "close"},
        "outputs": ["value"],
    },
    {
        "name": "EMA",
        "label": "Exponential Moving Average",
        "overlay": True,
        "params": {"period": 20, "source": "close"},
        "outputs": ["value"],
    },
    {
        "name": "RSI",
        "label": "Relative Strength Index",
        "overlay": False,
        "params": {"period": 14, "source": "close"},
        "outputs": ["value"],
    },
    {
        "name": "MACD",
        "label": "Moving Average Convergence Divergence",
        "overlay": False,
        "params": {"fast": 12, "slow": 26, "signal": 9, "source": "close"},
        "outputs": ["macd", "signal", "histogram"],
    },
    {
        "name": "ATR",
        "label": "Average True Range",
        "overlay": False,
        "params": {"period": 14},
        "outputs": ["value"],
    },
    {
        "name": "BBANDS",
        "label": "Bollinger Bands",
        "overlay": True,
        "params": {"period": 20, "std_dev": 2, "source": "close"},
        "outputs": ["middle", "upper", "lower"],
    },
]


class IndicatorEngine:
    """Compute normalized indicator series from closed candles only."""

    def catalog(self) -> list[dict[str, Any]]:
        return list(CATALOG)

    def compute(
        self,
        name: str,
        candles: list[dict[str, Any]],
        params: dict[str, Any] | None = None,
        *,
        instrument: str = "UNKNOWN",
        timeframe: str = "M5",
        source: str = "UNKNOWN",
        environment: str = "SIMULATION",
        quality_gate_allowed: bool = True,
        quality_status: str = "GOOD",
    ) -> dict[str, Any]:
        indicator = name.upper()
        params = dict(params or {})
        closed = [c for c in candles if c.get("is_closed", True)]
        if not quality_gate_allowed or quality_status in {"BAD", "UNAVAILABLE"}:
            return self._envelope(
                indicator,
                params,
                instrument,
                timeframe,
                source,
                environment,
                status="REFUSED",
                reason="MARKET_DATA_QUALITY_GATE",
                quality_status=quality_status,
                series=[],
                values={},
            )

        status = "DEGRADED" if quality_status == "DEGRADED" else "READY"
        series, values = self._dispatch(indicator, closed, params)
        return self._envelope(
            indicator,
            params,
            instrument,
            timeframe,
            source,
            environment,
            status=status,
            reason=None,
            quality_status=quality_status,
            series=series,
            values=values,
            candle_count=len(closed),
        )

    def _dispatch(
        self, name: str, candles: list[dict[str, Any]], params: dict[str, Any]
    ) -> tuple[list[dict[str, Any]], dict[str, Any]]:
        source_key = str(params.get("source", "close"))
        values = [_d(c.get(source_key)) for c in candles]
        if any(v is None for v in values):
            raise ValueError(f"Missing {source_key} on closed candles")
        typed = [v for v in values if v is not None]

        if name == "SMA":
            period = int(params.get("period", 20))
            computed = sma(typed, period)
            return self._single_series(candles, computed), self._latest_single(computed)
        if name == "EMA":
            period = int(params.get("period", 20))
            computed = ema(typed, period)
            return self._single_series(candles, computed), self._latest_single(computed)
        if name == "RSI":
            period = int(params.get("period", 14))
            computed = rsi(typed, period)
            return self._single_series(candles, computed), self._latest_single(computed)
        if name == "MACD":
            macd_rows = macd(
                typed,
                int(params.get("fast", 12)),
                int(params.get("slow", 26)),
                int(params.get("signal", 9)),
            )
            series: list[dict[str, Any]] = []
            for candle, row in zip(candles, macd_rows, strict=True):
                macd_value = row["macd"]
                if macd_value is None:
                    continue
                series.append(
                    {
                        "time": candle.get("open_time"),
                        "macd": _q(macd_value),
                        "signal": _q(row["signal"]),
                        "histogram": _q(row["histogram"]),
                    }
                )
            latest_macd: dict[str, Decimal | None] | None = None
            for row in reversed(macd_rows):
                if row["macd"] is not None:
                    latest_macd = row
                    break
            latest_values: dict[str, Any] = {}
            if latest_macd is not None:
                latest_values = {
                    "macd": _q(latest_macd["macd"]),
                    "signal": _q(latest_macd["signal"]),
                    "histogram": _q(latest_macd["histogram"]),
                }
            return series, latest_values
        if name == "ATR":
            period = int(params.get("period", 14))
            atr_computed = atr(candles, period)
            return self._single_series(candles, atr_computed), self._latest_single(atr_computed)
        if name in {"BBANDS", "BOLLINGER", "BB"}:
            period = int(params.get("period", 20))
            std = Decimal(str(params.get("std_dev", 2)))
            bb_rows = bollinger(typed, period, std)
            bb_series: list[dict[str, Any]] = []
            for candle, row in zip(candles, bb_rows, strict=True):
                middle = row["middle"]
                if middle is None:
                    continue
                bb_series.append(
                    {
                        "time": candle.get("open_time"),
                        "middle": _q(middle),
                        "upper": _q(row["upper"]),
                        "lower": _q(row["lower"]),
                    }
                )
            latest_bb: dict[str, Decimal | None] | None = None
            for row in reversed(bb_rows):
                if row["middle"] is not None:
                    latest_bb = row
                    break
            bb_values: dict[str, Any] = {}
            if latest_bb is not None:
                bb_values = {
                    "middle": _q(latest_bb["middle"]),
                    "upper": _q(latest_bb["upper"]),
                    "lower": _q(latest_bb["lower"]),
                }
            return bb_series, bb_values
        raise ValueError(f"Unknown indicator: {name}")

    def _single_series(
        self, candles: list[dict[str, Any]], computed: list[Decimal | None]
    ) -> list[dict[str, Any]]:
        series: list[dict[str, Any]] = []
        for candle, value in zip(candles, computed, strict=True):
            if value is None:
                continue
            series.append({"time": candle.get("open_time"), "value": _q(value)})
        return series

    def _latest_single(self, computed: list[Decimal | None]) -> dict[str, Any]:
        for value in reversed(computed):
            if value is not None:
                return {"value": _q(value)}
        return {}

    def _envelope(
        self,
        indicator: str,
        params: dict[str, Any],
        instrument: str,
        timeframe: str,
        source: str,
        environment: str,
        *,
        status: str,
        reason: str | None,
        quality_status: str,
        series: list[dict[str, Any]],
        values: dict[str, Any],
        candle_count: int = 0,
    ) -> dict[str, Any]:
        return {
            "instrument": instrument.upper(),
            "timeframe": timeframe.upper(),
            "indicator": indicator,
            "params": params,
            "timestamps_aligned_to": "candle_open_time",
            "source": source,
            "environment": environment,
            "generated_at": datetime.now(UTC).isoformat(),
            "status": status,
            "reason": reason,
            "candle_count": candle_count,
            "point_count": len(series),
            "freshness": {"status": "FRESH" if status != "REFUSED" else "UNAVAILABLE"},
            "quality": {"status": quality_status, "usable_for_analysis": status != "REFUSED"},
            "gate": {
                "allowed": status != "REFUSED",
                "reason": reason,
                "phase": 6,
                "execution": False,
            },
            "series": series,
            "values": values,
            "read_only": True,
            "execution": {"order" + "_send": False, "demo": False, "live": False},
        }
