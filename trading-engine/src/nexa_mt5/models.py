from datetime import UTC, datetime
from enum import StrEnum
from typing import Any

from pydantic import BaseModel, ConfigDict, Field


class ConnectionState(StrEnum):
    DISCONNECTED = "DISCONNECTED"
    CONNECTING = "CONNECTING"
    CONNECTED = "CONNECTED"
    STALE = "STALE"
    BACKOFF = "BACKOFF"
    ERROR = "ERROR"
    SHUTTING_DOWN = "SHUTTING_DOWN"


class SourceMetadata(BaseModel):
    model_config = ConfigDict(extra="forbid")

    source: str
    environment: str = "DEMO"
    mode: str
    source_timestamp: datetime
    received_at: datetime = Field(default_factory=lambda: datetime.now(UTC))
    freshness: str
    correlation_id: str
    adapter_version: str


class Envelope(BaseModel):
    model_config = ConfigDict(extra="forbid")

    data: Any
    meta: SourceMetadata


class ErrorBody(BaseModel):
    code: str
    message: str
    correlation_id: str


class ErrorEnvelope(BaseModel):
    error: ErrorBody


class HistoryQuery(BaseModel):
    model_config = ConfigDict(extra="forbid")

    date_from: datetime
    date_to: datetime
    limit: int
