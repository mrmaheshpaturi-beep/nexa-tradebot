import logging
import time
from collections.abc import Callable
from datetime import UTC, datetime
from typing import Any, TypeVar
from uuid import uuid4

from .config import Settings
from .connectors import MockMT5Connector, MT5Connector, RealMT5Connector
from .errors import BridgeError, ErrorCode
from .models import ConnectionState, SourceMetadata

T = TypeVar("T")
logger = logging.getLogger("nexa_mt5")


class MT5ReadService:
    def __init__(self, settings: Settings, connector: MT5Connector | None = None) -> None:
        self.settings = settings
        self.connector = connector or (
            RealMT5Connector(settings) if settings.mode == "real" else MockMT5Connector()
        )
        self.state = ConnectionState.DISCONNECTED
        self.last_success_at: datetime | None = None
        self.last_error_code: ErrorCode | None = None
        self.attempts = 0

    def connect(self) -> None:
        if self.state is ConnectionState.CONNECTED and not self.is_stale:
            return
        delay = self.settings.backoff_initial_seconds
        for attempt in range(1, self.settings.connect_attempts + 1):
            self.attempts = attempt
            self.state = ConnectionState.CONNECTING
            try:
                if self.connector.initialize():
                    self.state = ConnectionState.CONNECTED
                    self.last_success_at = datetime.now(UTC)
                    self.last_error_code = None
                    logger.info(
                        "terminal_connected",
                        extra={"context": {"mode": self.settings.mode}},
                    )
                    return
                raise BridgeError(ErrorCode.CONNECTION_FAILED, "Unable to connect to the terminal.")
            except BridgeError as error:
                self.last_error_code = error.code
                if error.code is ErrorCode.PLATFORM_UNSUPPORTED:
                    self.state = ConnectionState.ERROR
                    raise
            except Exception:
                self.last_error_code = ErrorCode.CONNECTION_FAILED
            if attempt < self.settings.connect_attempts:
                self.state = ConnectionState.BACKOFF
                time.sleep(min(delay, self.settings.backoff_max_seconds))
                delay = min(max(delay * 2, 0.001), self.settings.backoff_max_seconds)
        self.state = ConnectionState.ERROR
        logger.error("terminal_connection_failed", extra={"context": {"attempts": self.attempts}})
        raise BridgeError(ErrorCode.CONNECTION_FAILED, "Unable to connect to the terminal.")

    def shutdown(self) -> None:
        self.state = ConnectionState.SHUTTING_DOWN
        try:
            self.connector.shutdown()
        finally:
            self.state = ConnectionState.DISCONNECTED

    @property
    def is_stale(self) -> bool:
        if self.last_success_at is None:
            return False
        age = (datetime.now(UTC) - self.last_success_at).total_seconds()
        if age > self.settings.stale_after_seconds:
            self.state = ConnectionState.STALE
            return True
        return False

    def read(self, operation: Callable[[], T]) -> T:
        self.connect()
        try:
            result = operation()
            self.last_success_at = datetime.now(UTC)
            self.state = ConnectionState.CONNECTED
            return result
        except BridgeError:
            self.state = ConnectionState.ERROR
            raise
        except Exception:
            self.state = ConnectionState.ERROR
            self.last_error_code = ErrorCode.PROVIDER_ERROR
            logger.error("terminal_read_failed")
            raise BridgeError(ErrorCode.PROVIDER_ERROR, "The terminal read failed.") from None

    def metadata(self, correlation_id: str | None = None) -> SourceMetadata:
        stale = self.is_stale
        timestamp = self.last_success_at or datetime.now(UTC)
        return SourceMetadata(
            source="MT5" if self.settings.mode == "real" else "MOCK_MT5",
            mode=self.settings.mode.upper(),
            source_timestamp=timestamp,
            freshness="STALE" if stale else "FRESH",
            correlation_id=correlation_id or str(uuid4()),
            adapter_version="0.2.0",
        )

    def health(self) -> dict[str, Any]:
        return {
            "status": self.state.value,
            "configured": bool(self.settings.service_token)
            and (self.settings.mode == "mock" or self.settings.login is not None),
            "mode": self.settings.mode.upper(),
            "read_only": True,
            "stale": self.is_stale,
            "last_success_at": self.last_success_at,
            "attempts": self.attempts,
            "last_error_code": self.last_error_code.value if self.last_error_code else None,
        }
