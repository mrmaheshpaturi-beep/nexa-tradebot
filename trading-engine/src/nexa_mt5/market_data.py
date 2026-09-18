"""Market Data Engine — normalize, validate, score, and snapshot read-only feeds."""

from __future__ import annotations

from datetime import UTC, datetime
from decimal import Decimal, InvalidOperation
from typing import Any
from uuid import uuid4

from .config import Settings
from .errors import BridgeError, ErrorCode
from .service import MT5ReadService

QUALITY_EXCELLENT = "EXCELLENT"
QUALITY_GOOD = "GOOD"
QUALITY_DEGRADED = "DEGRADED"
QUALITY_POOR = "POOR"
QUALITY_INVALID = "INVALID"

FRESHNESS_FRESH = "FRESH"
FRESHNESS_AGING = "AGING"
FRESHNESS_STALE = "STALE"
FRESHNESS_UNKNOWN = "UNKNOWN"
FRESHNESS_UNAVAILABLE = "UNAVAILABLE"


def _as_decimal(value: Any) -> Decimal | None:
    if value is None:
        return None
    try:
        return Decimal(str(value))
    except (InvalidOperation, ValueError, TypeError):
        return None


def _parse_time(value: Any) -> datetime | None:
    if value is None:
        return None
    if isinstance(value, datetime):
        return value if value.tzinfo else value.replace(tzinfo=UTC)
    text = str(value).strip()
    if not text:
        return None
    try:
        parsed = datetime.fromisoformat(text.replace("Z", "+00:00"))
        return parsed if parsed.tzinfo else parsed.replace(tzinfo=UTC)
    except ValueError:
        return None




def _opt_str(raw: dict[str, Any], key: str) -> str | None:
    value = raw.get(key)
    return str(value) if value is not None else None

def _quality_label(score: int) -> str:
    if score >= 90:
        return QUALITY_EXCELLENT
    if score >= 75:
        return QUALITY_GOOD
    if score >= 50:
        return QUALITY_DEGRADED
    if score >= 25:
        return QUALITY_POOR
    return QUALITY_INVALID


class MarketDataEngine:
    """Normalizes bridge ticks/rates/symbols with freshness and quality metadata."""

    DEFAULT_SYMBOLS = ("EURUSD", "GBPUSD", "USDJPY", "XAUUSD", "NAS100", "BTCUSD")

    def __init__(self, service: MT5ReadService, settings: Settings) -> None:
        self.service = service
        self.settings = settings

    def freshness(
        self, source_time: datetime | None, received_at: datetime | None = None
    ) -> dict[str, Any]:
        now = received_at or datetime.now(UTC)
        if source_time is None:
            return {
                "status": FRESHNESS_UNKNOWN,
                "age_seconds": None,
                "stale_after_seconds": self.settings.stale_after_seconds,
                "is_stale": False,
            }
        age = max(0.0, (now - source_time).total_seconds())
        stale = age > self.settings.stale_after_seconds
        aging = (not stale) and age > max(1.0, self.settings.stale_after_seconds / 3)
        status = FRESHNESS_STALE if stale else (FRESHNESS_AGING if aging else FRESHNESS_FRESH)
        return {
            "status": status,
            "age_seconds": round(age, 3),
            "stale_after_seconds": self.settings.stale_after_seconds,
            "is_stale": stale,
        }

    def validate_quote(self, raw: dict[str, Any]) -> tuple[list[str], int]:
        issues: list[str] = []
        score = 100
        bid = _as_decimal(raw.get("bid"))
        ask = _as_decimal(raw.get("ask"))
        if bid is None or ask is None:
            issues.append("MISSING_PRICE")
            score -= 50
        elif bid <= 0 or ask <= 0:
            issues.append("NON_POSITIVE_PRICE")
            score -= 40
        elif ask < bid:
            issues.append("INVERTED_SPREAD")
            score -= 45
        elif ask == bid:
            issues.append("ZERO_SPREAD")
            score -= 10
        if not str(raw.get("symbol") or "").strip():
            issues.append("MISSING_SYMBOL")
            score -= 30
        if _parse_time(raw.get("time") or raw.get("timestamp")) is None:
            issues.append("MISSING_TIMESTAMP")
            score -= 15
        return issues, max(0, score)

    def normalize_quote(
        self, raw: dict[str, Any], *, correlation_id: str | None = None
    ) -> dict[str, Any]:
        received_at = datetime.now(UTC)
        symbol = str(raw.get("symbol") or "").strip().upper()
        bid = _as_decimal(raw.get("bid"))
        ask = _as_decimal(raw.get("ask"))
        source_time = _parse_time(raw.get("time") or raw.get("timestamp")) or received_at
        issues, score = self.validate_quote({**raw, "symbol": symbol})
        freshness = self.freshness(source_time, received_at)
        if freshness["is_stale"]:
            issues.append("STALE")
            score = max(0, score - 25)
        spread = None
        if bid is not None and ask is not None:
            spread = format(ask - bid, "f")
        mode = self.settings.mode.upper()
        source = "MT5" if self.settings.mode == "real" else "MOCK_MT5"
        return {
            "symbol": symbol,
            "bid": format(bid, "f") if bid is not None else None,
            "ask": format(ask, "f") if ask is not None else None,
            "spread": spread,
            "last": str(raw.get("last")) if raw.get("last") is not None else (
                format(bid, "f") if bid is not None else None
            ),
            "volume": str(raw.get("volume")) if raw.get("volume") is not None else None,
            "timestamp": source_time.isoformat(),
            "received_at": received_at.isoformat(),
            "source": source,
            "environment": "DEMO",
            "mode": mode,
            "freshness": freshness,
            "quality": {
                "score": score,
                "status": _quality_label(score),
                "issues": issues,
                "usable": (
                    score >= 50
                    and "INVERTED_SPREAD" not in issues
                    and "MISSING_PRICE" not in issues
                ),
            },
            "correlation_id": correlation_id or str(uuid4()),
        }

    def validate_candle(self, raw: dict[str, Any]) -> tuple[list[str], int]:
        issues: list[str] = []
        score = 100
        open_ = _as_decimal(raw.get("open"))
        high = _as_decimal(raw.get("high"))
        low = _as_decimal(raw.get("low"))
        close = _as_decimal(raw.get("close"))
        if None in (open_, high, low, close):
            issues.append("MISSING_OHLC")
            score -= 50
        else:
            assert open_ is not None and high is not None and low is not None and close is not None
            if high < max(open_, close) or low > min(open_, close) or high < low:
                issues.append("OHLC_INCONSISTENT")
                score -= 40
            if min(open_, high, low, close) <= 0:
                issues.append("NON_POSITIVE_OHLC")
                score -= 30
        if _parse_time(raw.get("time") or raw.get("open_time")) is None:
            issues.append("MISSING_TIMESTAMP")
            score -= 15
        return issues, max(0, score)

    def normalize_candle(
        self, raw: dict[str, Any], *, symbol: str, timeframe: str, correlation_id: str | None = None
    ) -> dict[str, Any]:
        received_at = datetime.now(UTC)
        open_time = _parse_time(raw.get("time") or raw.get("open_time")) or received_at
        issues, score = self.validate_candle(raw)
        freshness = self.freshness(open_time, received_at)
        # Historical candles are expected to be older than the live stale threshold;
        # mark bar-age separately without forcing STALE on history.
        historical = True
        source = "MT5" if self.settings.mode == "real" else "MOCK_MT5"
        # Last bar is treated as forming; earlier bars closed.
        return {
            "symbol": symbol.upper(),
            "timeframe": timeframe,
            "open_time": open_time.isoformat(),
            "close_time": (_parse_time(raw.get("close_time")) or open_time).isoformat(),
            "open": str(raw.get("open")) if raw.get("open") is not None else None,
            "high": str(raw.get("high")) if raw.get("high") is not None else None,
            "low": str(raw.get("low")) if raw.get("low") is not None else None,
            "close": str(raw.get("close")) if raw.get("close") is not None else None,
            "tick_volume": int(raw.get("tick_volume") or raw.get("volume") or 0),
            "source": source,
            "environment": "DEMO",
            "historical": historical,
            "is_closed": bool(raw.get("is_closed", True)),
            "freshness": {
                "status": FRESHNESS_FRESH,
                "age_seconds": freshness["age_seconds"],
                "stale_after_seconds": self.settings.stale_after_seconds,
                "is_stale": False,
            },
            "quality": {
                "score": score,
                "status": _quality_label(score),
                "issues": issues,
                "usable": score >= 50 and "OHLC_INCONSISTENT" not in issues,
            },
            "correlation_id": correlation_id or str(uuid4()),
        }

    def normalize_symbol(self, raw: dict[str, Any]) -> dict[str, Any]:
        symbol = str(raw.get("symbol") or "").strip().upper()
        issues: list[str] = []
        score = 100
        if not symbol:
            issues.append("MISSING_SYMBOL")
            score -= 50
        digits = raw.get("digits")
        if digits is None:
            issues.append("MISSING_DIGITS")
            score -= 10
        return {
            "symbol": symbol,
            "description": raw.get("description") or symbol,
            "digits": digits,
            "point": _opt_str(raw, "point"),
            "trade_tick_size": _opt_str(raw, "trade_tick_size"),
            "trade_tick_value": _opt_str(raw, "trade_tick_value"),
            "volume_min": _opt_str(raw, "volume_min"),
            "volume_max": _opt_str(raw, "volume_max"),
            "volume_step": _opt_str(raw, "volume_step"),
            "source": "MT5" if self.settings.mode == "real" else "MOCK_MT5",
            "environment": "DEMO",
            "quality": {
                "score": max(0, score),
                "status": _quality_label(max(0, score)),
                "issues": issues,
                "usable": score >= 50,
            },
        }

    def quote(self, symbol: str, *, correlation_id: str | None = None) -> dict[str, Any]:
        raw = self.service.read(lambda: self.service.connector.tick(symbol))
        if raw is None:
            raise BridgeError(ErrorCode.NOT_FOUND, "The quote was not found.", 404)
        return self.normalize_quote(raw, correlation_id=correlation_id)

    def quotes(
        self, symbols: list[str] | None = None, *, correlation_id: str | None = None
    ) -> list[dict[str, Any]]:
        if symbols:
            requested = [s.strip().upper() for s in symbols if s.strip()]
        else:
            listed = self.service.read(self.service.connector.symbols)
            requested = [
                str(item.get("symbol", "")).upper()
                for item in listed
                if item.get("symbol")
            ]
            if not requested:
                requested = list(self.DEFAULT_SYMBOLS)
        results: list[dict[str, Any]] = []
        for symbol in requested:
            try:
                results.append(self.quote(symbol, correlation_id=correlation_id))
            except BridgeError as error:
                if error.code is ErrorCode.NOT_FOUND:
                    continue
                raise
        return results

    def candles(
        self,
        symbol: str,
        timeframe: str,
        count: int,
        *,
        correlation_id: str | None = None,
    ) -> list[dict[str, Any]]:
        raw_bars = self.service.read(
            lambda: self.service.connector.rates(symbol, timeframe, count)
        )
        normalized = [
            self.normalize_candle(
                bar, symbol=symbol, timeframe=timeframe, correlation_id=correlation_id
            )
            for bar in raw_bars
        ]
        if normalized:
            for bar in normalized[:-1]:
                bar["is_closed"] = True
            normalized[-1]["is_closed"] = False
        return normalized

    def symbols(self) -> list[dict[str, Any]]:
        raw = self.service.read(self.service.connector.symbols)
        return [self.normalize_symbol(item) for item in raw]

    def snapshot(
        self,
        *,
        symbols: list[str] | None = None,
        candle_symbol: str = "EURUSD",
        timeframe: str = "M5",
        candle_count: int = 60,
        correlation_id: str | None = None,
    ) -> dict[str, Any]:
        cid = correlation_id or str(uuid4())
        quote_list = self.quotes(symbols, correlation_id=cid)
        symbol_list = self.symbols()
        try:
            candle_list = self.candles(
                candle_symbol.upper(), timeframe, candle_count, correlation_id=cid
            )
        except BridgeError:
            candle_list = []
        usable_quotes = sum(1 for q in quote_list if q["quality"]["usable"])
        stale_quotes = sum(1 for q in quote_list if q["freshness"]["is_stale"])
        overall_score = (
            int(sum(q["quality"]["score"] for q in quote_list) / len(quote_list))
            if quote_list
            else 0
        )
        health = self.service.health()
        return {
            "generated_at": datetime.now(UTC).isoformat(),
            "read_only": True,
            "environment": "DEMO",
            "source": "MT5" if self.settings.mode == "real" else "MOCK_MT5",
            "bridge": {
                "status": health.get("status"),
                "mode": health.get("mode"),
                "stale": health.get("stale"),
                "read_only": True,
            },
            "symbols": symbol_list,
            "quotes": quote_list,
            "candles": {
                "symbol": candle_symbol.upper(),
                "timeframe": timeframe,
                "bars": candle_list,
            },
            "summary": {
                "symbol_count": len(symbol_list),
                "quote_count": len(quote_list),
                "usable_quote_count": usable_quotes,
                "stale_quote_count": stale_quotes,
                "candle_count": len(candle_list),
                "overall_quality_score": overall_score,
                "overall_quality_status": _quality_label(overall_score),
                "extension_hooks": {
                    "phase_6_indicator_engine": "PENDING",
                    "phase_7_strategies": "PENDING",
                },
            },
            "correlation_id": cid,
        }
