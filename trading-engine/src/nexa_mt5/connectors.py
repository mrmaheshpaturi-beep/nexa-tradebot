import importlib
import platform
from datetime import UTC, datetime, timedelta
from decimal import Decimal
from typing import Any, Protocol, runtime_checkable

from .config import Settings
from .errors import BridgeError, ErrorCode


@runtime_checkable
class MT5Connector(Protocol):
    def initialize(self) -> bool: ...
    def shutdown(self) -> None: ...
    def terminal(self) -> dict[str, Any]: ...
    def version(self) -> dict[str, Any]: ...
    def account(self) -> dict[str, Any]: ...
    def symbols(self) -> list[dict[str, Any]]: ...
    def symbol_info(self, symbol: str) -> dict[str, Any] | None: ...
    def tick(self, symbol: str) -> dict[str, Any] | None: ...
    def rates(self, symbol: str, timeframe: str, count: int) -> list[dict[str, Any]]: ...
    def positions(self) -> list[dict[str, Any]]: ...
    def orders(self) -> list[dict[str, Any]]: ...
    def history_orders(
        self, date_from: datetime, date_to: datetime, limit: int
    ) -> list[dict[str, Any]]: ...
    def history_deals(
        self, date_from: datetime, date_to: datetime, limit: int
    ) -> list[dict[str, Any]]: ...


def _decimal(value: float) -> str:
    return format(Decimal(str(value)), "f")


def _public_record(record: Any) -> dict[str, Any]:
    raw = record._asdict() if hasattr(record, "_asdict") else dict(record)
    safe: dict[str, Any] = {}
    for key, value in raw.items():
        lowered = str(key).lower()
        if "path" in lowered or lowered in {"password", "community_account"}:
            continue
        public_key = "account_id" if lowered == "login" else str(key)
        if isinstance(value, float):
            safe[public_key] = _decimal(value)
        elif lowered in {"time", "time_setup", "time_done"} and isinstance(value, int):
            safe[public_key] = datetime.fromtimestamp(value, UTC).isoformat()
        else:
            safe[public_key] = value
    return safe


class MockMT5Connector:
    def __init__(self) -> None:
        self.connected = False
        self.now = datetime.now(UTC)

    def initialize(self) -> bool:
        self.connected = True
        return True

    def shutdown(self) -> None:
        self.connected = False

    def _require(self) -> None:
        if not self.connected:
            raise BridgeError(ErrorCode.DISCONNECTED, "The read-only terminal is disconnected.")

    def terminal(self) -> dict[str, Any]:
        self._require()
        return {"name": "Nexa Mock Terminal", "connected": True, "trade_allowed": False}

    def version(self) -> dict[str, Any]:
        self._require()
        return {"version": "mock-1", "build": 1, "date": self.now.date().isoformat()}

    def account(self) -> dict[str, Any]:
        self._require()
        return {
            "account_id": 900001,
            "name": "Nexa Demo",
            "server": "Nexa-Demo",
            "currency": "USD",
            "leverage": 100,
            "balance": "10000.00",
            "equity": "10024.50",
            "credit": "0.00",
            "margin": "120.00",
            "free_margin": "9904.50",
            "margin_level": "8353.75",
            "trade_mode": "DEMO",
        }

    MOCK_SPECS: dict[str, dict[str, Any]] = {
        "EURUSD": {
            "symbol": "EURUSD",
            "description": "Euro / US Dollar",
            "digits": 5,
            "point": "0.00001",
            "trade_tick_size": "0.00001",
            "trade_tick_value": "1.0",
            "volume_min": "0.01",
            "volume_max": "100.0",
            "volume_step": "0.01",
        },
        "GBPUSD": {
            "symbol": "GBPUSD",
            "description": "British Pound / US Dollar",
            "digits": 5,
            "point": "0.00001",
            "trade_tick_size": "0.00001",
            "trade_tick_value": "1.0",
            "volume_min": "0.01",
            "volume_max": "100.0",
            "volume_step": "0.01",
        },
        "USDJPY": {
            "symbol": "USDJPY",
            "description": "US Dollar / Japanese Yen",
            "digits": 3,
            "point": "0.001",
            "trade_tick_size": "0.001",
            "trade_tick_value": "1.0",
            "volume_min": "0.01",
            "volume_max": "100.0",
            "volume_step": "0.01",
        },
        "XAUUSD": {
            "symbol": "XAUUSD",
            "description": "Gold / US Dollar",
            "digits": 2,
            "point": "0.01",
            "trade_tick_size": "0.01",
            "trade_tick_value": "1.0",
            "volume_min": "0.01",
            "volume_max": "50.0",
            "volume_step": "0.01",
        },
        "NAS100": {
            "symbol": "NAS100",
            "description": "Nasdaq 100 CFD",
            "digits": 2,
            "point": "0.01",
            "trade_tick_size": "0.01",
            "trade_tick_value": "1.0",
            "volume_min": "0.01",
            "volume_max": "50.0",
            "volume_step": "0.01",
        },
        "BTCUSD": {
            "symbol": "BTCUSD",
            "description": "Bitcoin / US Dollar",
            "digits": 2,
            "point": "0.01",
            "trade_tick_size": "0.01",
            "trade_tick_value": "1.0",
            "volume_min": "0.01",
            "volume_max": "10.0",
            "volume_step": "0.01",
        },
    }

    MOCK_QUOTES: dict[str, tuple[str, str]] = {
        "EURUSD": ("1.10000", "1.10020"),
        "GBPUSD": ("1.27500", "1.27530"),
        "USDJPY": ("145.100", "145.120"),
        "XAUUSD": ("2350.10", "2350.30"),
        "NAS100": ("19000.00", "19001.00"),
        "BTCUSD": ("60000.00", "60010.00"),
    }

    def symbols(self) -> list[dict[str, Any]]:
        self._require()
        return [self.symbol_info(symbol) for symbol in self.MOCK_SPECS]  # type: ignore[misc]

    def symbol_info(self, symbol: str) -> dict[str, Any] | None:
        self._require()
        spec = self.MOCK_SPECS.get(symbol.upper())
        return dict(spec) if spec else None

    def tick(self, symbol: str) -> dict[str, Any] | None:
        self._require()
        quote = self.MOCK_QUOTES.get(symbol.upper())
        if quote is None:
            return None
        return {
            "symbol": symbol.upper(),
            "bid": quote[0],
            "ask": quote[1],
            "last": quote[0],
            "volume": "10",
            "time": self.now.isoformat(),
        }

    def rates(self, symbol: str, timeframe: str, count: int) -> list[dict[str, Any]]:
        self._require()
        tick = self.tick(symbol)
        if tick is None:
            return []
        base = Decimal(str(tick["bid"]))
        return [
            {
                "symbol": symbol.upper(),
                "timeframe": timeframe,
                "time": (self.now - timedelta(minutes=count - index)).isoformat(),
                "open": str(base + Decimal(index) / Decimal("100000")),
                "high": str(base + Decimal(index + 2) / Decimal("100000")),
                "low": str(base + Decimal(index - 2) / Decimal("100000")),
                "close": str(base + Decimal(index + 1) / Decimal("100000")),
                "tick_volume": 100 + index,
            }
            for index in range(count)
        ]

    def positions(self) -> list[dict[str, Any]]:
        self._require()
        return [{
            "ticket": 70001,
            "symbol": "EURUSD",
            "side": "BUY",
            "volume": "0.10",
            "price_open": "1.09800",
            "price_current": "1.10000",
            "profit": "20.00",
            "time": (self.now - timedelta(hours=2)).isoformat(),
        }]

    def orders(self) -> list[dict[str, Any]]:
        self._require()
        return [{
            "ticket": 71001,
            "symbol": "XAUUSD",
            "type": "BUY_LIMIT",
            "volume_initial": "0.10",
            "volume_current": "0.10",
            "price_open": "2300.00",
            "time_setup": (self.now - timedelta(hours=1)).isoformat(),
        }]

    def history_orders(
        self, date_from: datetime, date_to: datetime, limit: int
    ) -> list[dict[str, Any]]:
        self._require()
        item = {
            "ticket": 72001,
            "symbol": "EURUSD",
            "type": "MARKET",
            "state": "FILLED",
            "volume_initial": "0.10",
            "time_done": (self.now - timedelta(days=1)).isoformat(),
        }
        return [item] if date_from <= self.now <= date_to + timedelta(days=2) else []

    def history_deals(
        self, date_from: datetime, date_to: datetime, limit: int
    ) -> list[dict[str, Any]]:
        self._require()
        item = {
            "ticket": 73001,
            "order": 72001,
            "symbol": "EURUSD",
            "entry": "OUT",
            "volume": "0.10",
            "price": "1.10000",
            "profit": "20.00",
            "time": (self.now - timedelta(days=1)).isoformat(),
        }
        return [item] if date_from <= self.now <= date_to + timedelta(days=2) else []


class RealMT5Connector:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self._mt5: Any = None

    def initialize(self) -> bool:
        if platform.system() != "Windows":
            raise BridgeError(
                ErrorCode.PLATFORM_UNSUPPORTED,
                "The real read-only connector requires a controlled Windows host.",
            )
        self._mt5 = importlib.import_module("MetaTrader5")
        kwargs: dict[str, Any] = {}
        if self.settings.terminal_path:
            kwargs["path"] = self.settings.terminal_path
        if self.settings.login is not None:
            kwargs["login"] = self.settings.login
        if self.settings.password:
            kwargs["password"] = self.settings.password
        if self.settings.server:
            kwargs["server"] = self.settings.server
        return bool(self._mt5.initialize(**kwargs))

    def shutdown(self) -> None:
        if self._mt5 is not None:
            self._mt5.shutdown()

    def _require(self) -> Any:
        if self._mt5 is None:
            raise BridgeError(ErrorCode.DISCONNECTED, "The read-only terminal is disconnected.")
        return self._mt5

    def _ensure_symbol_selected(self, symbol: str) -> bool:
        """MT5 returns no tick until the symbol is in Market Watch."""
        mt5 = self._require()
        info = mt5.symbol_info(symbol)
        if info is None:
            return False
        if bool(getattr(info, "visible", False)):
            return True
        return bool(mt5.symbol_select(symbol, True))

    def terminal(self) -> dict[str, Any]:
        info = self._require().terminal_info()
        return _public_record(info) if info else {}

    def version(self) -> dict[str, Any]:
        version = self._require().version()
        return {"version": version[0], "build": version[1], "date": str(version[2])}

    def account(self) -> dict[str, Any]:
        info = self._require().account_info()
        return _public_record(info) if info else {}

    def symbols(self) -> list[dict[str, Any]]:
        return [_public_record(item) for item in (self._require().symbols_get() or ())]

    def symbol_info(self, symbol: str) -> dict[str, Any] | None:
        if not self._ensure_symbol_selected(symbol):
            return None
        info = self._require().symbol_info(symbol)
        return _public_record(info) if info else None

    def tick(self, symbol: str) -> dict[str, Any] | None:
        if not self._ensure_symbol_selected(symbol):
            return None
        info = self._require().symbol_info_tick(symbol)
        return _public_record(info) if info else None

    def rates(self, symbol: str, timeframe: str, count: int) -> list[dict[str, Any]]:
        if not self._ensure_symbol_selected(symbol):
            return []
        mt5 = self._require()
        timeframes = {
            "M1": mt5.TIMEFRAME_M1,
            "M5": mt5.TIMEFRAME_M5,
            "M15": mt5.TIMEFRAME_M15,
            "M30": mt5.TIMEFRAME_M30,
            "H1": mt5.TIMEFRAME_H1,
            "H4": mt5.TIMEFRAME_H4,
            "D1": mt5.TIMEFRAME_D1,
        }
        return [_public_record(item) for item in (mt5.copy_rates_from_pos(
            symbol, timeframes[timeframe], 0, count
        ) or ())]

    def positions(self) -> list[dict[str, Any]]:
        return [_public_record(item) for item in (self._require().positions_get() or ())]

    def orders(self) -> list[dict[str, Any]]:
        return [_public_record(item) for item in (self._require().orders_get() or ())]

    def history_orders(
        self, date_from: datetime, date_to: datetime, limit: int
    ) -> list[dict[str, Any]]:
        records = self._require().history_orders_get(date_from, date_to) or ()
        return [_public_record(item) for item in records[:limit]]

    def history_deals(
        self, date_from: datetime, date_to: datetime, limit: int
    ) -> list[dict[str, Any]]:
        records = self._require().history_deals_get(date_from, date_to) or ()
        return [_public_record(item) for item in records[:limit]]
