import hmac
from contextlib import asynccontextmanager
from datetime import UTC, datetime, timedelta
from collections.abc import AsyncGenerator
from typing import Any
from uuid import uuid4

from fastapi import Depends, FastAPI, Query, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from .config import Settings, get_settings
from .connectors import MT5Connector
from .errors import BridgeError, ErrorCode
from .logging import configure_logging
from .models import Envelope, ErrorBody, ErrorEnvelope
from .service import MT5ReadService

bearer = HTTPBearer(auto_error=False)


def create_app(settings: Settings | None = None, connector: MT5Connector | None = None) -> FastAPI:
    resolved = settings or get_settings()
    service = MT5ReadService(resolved, connector)
    configure_logging()

    @asynccontextmanager
    async def lifespan(_: FastAPI) -> AsyncGenerator[None, None]:
        yield
        service.shutdown()

    application = FastAPI(
        title="Nexa MT5 Read-Only Bridge",
        version="0.1.0",
        docs_url=None,
        redoc_url=None,
        openapi_url=None,
        lifespan=lifespan,
    )
    application.state.mt5_service = service

    def correlation(request: Request) -> str:
        supplied = request.headers.get("X-Correlation-ID", "")
        return supplied[:64] if supplied and supplied.replace("-", "").isalnum() else str(uuid4())

    def authenticate(
        credentials: HTTPAuthorizationCredentials | None = Depends(bearer),
    ) -> None:
        expected = resolved.service_token
        presented = (
            credentials.credentials
            if credentials and credentials.scheme.lower() == "bearer"
            else ""
        )
        valid = bool(expected) and hmac.compare_digest(
            presented.encode("utf-8"), expected.encode("utf-8")
        )
        if not valid:
            raise BridgeError(
                ErrorCode.AUTHENTICATION_REQUIRED,
                "Valid service authentication is required.",
                401,
            )

    def envelope(request: Request, data: Any) -> Envelope:
        return Envelope(data=data, meta=service.metadata(correlation(request)))

    @application.exception_handler(BridgeError)
    async def bridge_error(request: Request, error: BridgeError) -> JSONResponse:
        body = ErrorEnvelope(error=ErrorBody(
            code=error.code.value,
            message=error.safe_message,
            correlation_id=correlation(request),
        ))
        return JSONResponse(status_code=error.status_code, content=body.model_dump(mode="json"))

    @application.exception_handler(RequestValidationError)
    async def validation_error(request: Request, _: RequestValidationError) -> JSONResponse:
        body = ErrorEnvelope(error=ErrorBody(
            code=ErrorCode.INVALID_REQUEST.value,
            message="The read request is invalid.",
            correlation_id=correlation(request),
        ))
        return JSONResponse(status_code=422, content=body.model_dump(mode="json"))

    @application.get("/v1/health", response_model=Envelope, dependencies=[Depends(authenticate)])
    def health(request: Request) -> Envelope:
        return envelope(request, service.health())

    @application.get("/v1/terminal", response_model=Envelope, dependencies=[Depends(authenticate)])
    def terminal(request: Request) -> Envelope:
        return envelope(request, {
            "terminal": service.read(service.connector.terminal),
            "version": service.read(service.connector.version),
        })

    @application.get("/v1/account", response_model=Envelope, dependencies=[Depends(authenticate)])
    def account(request: Request) -> Envelope:
        return envelope(request, service.read(service.connector.account))

    @application.get("/v1/symbols", response_model=Envelope, dependencies=[Depends(authenticate)])
    def symbols(request: Request) -> Envelope:
        return envelope(request, service.read(service.connector.symbols))

    @application.get(
        "/v1/symbols/{symbol}", response_model=Envelope, dependencies=[Depends(authenticate)]
    )
    def symbol_info(request: Request, symbol: str) -> Envelope:
        normalized = validate_symbol(symbol)
        result = service.read(lambda: service.connector.symbol_info(normalized))
        if result is None:
            raise BridgeError(ErrorCode.NOT_FOUND, "The symbol was not found.", 404)
        return envelope(request, result)

    @application.get(
        "/v1/quotes/{symbol}", response_model=Envelope, dependencies=[Depends(authenticate)]
    )
    def quote(request: Request, symbol: str) -> Envelope:
        normalized = validate_symbol(symbol)
        result = service.read(lambda: service.connector.tick(normalized))
        if result is None:
            raise BridgeError(ErrorCode.NOT_FOUND, "The quote was not found.", 404)
        return envelope(request, result)

    @application.get(
        "/v1/candles/{symbol}", response_model=Envelope, dependencies=[Depends(authenticate)]
    )
    def candles(
        request: Request,
        symbol: str,
        timeframe: str = Query("M1", pattern="^(M1|M5|M15|M30|H1|H4|D1)$"),
        count: int = Query(100, ge=1, le=1000),
    ) -> Envelope:
        normalized = validate_symbol(symbol)
        data = service.read(lambda: service.connector.rates(normalized, timeframe, count))
        return envelope(request, data)

    @application.get("/v1/positions", response_model=Envelope, dependencies=[Depends(authenticate)])
    def positions(request: Request) -> Envelope:
        return envelope(request, service.read(service.connector.positions))

    @application.get("/v1/orders", response_model=Envelope, dependencies=[Depends(authenticate)])
    def orders(request: Request) -> Envelope:
        return envelope(request, service.read(service.connector.orders))

    def history_range(
        date_from: datetime | None, date_to: datetime | None, limit: int
    ) -> tuple[datetime, datetime, int]:
        end = date_to or datetime.now(UTC)
        start = date_from or end - timedelta(days=7)
        if (
            start.tzinfo is None
            or end.tzinfo is None
            or start >= end
            or end - start > timedelta(days=90)
        ):
            raise BridgeError(
                ErrorCode.INVALID_REQUEST,
                "History requires an ordered timezone-aware range of at most 90 days.",
                422,
            )
        return start, end, min(limit, resolved.history_max_records)

    @application.get(
        "/v1/history/orders", response_model=Envelope, dependencies=[Depends(authenticate)]
    )
    def history_orders(
        request: Request,
        date_from: datetime | None = None,
        date_to: datetime | None = None,
        limit: int = Query(100, ge=1, le=5000),
    ) -> Envelope:
        start, end, bounded = history_range(date_from, date_to, limit)
        return envelope(
            request,
            service.read(lambda: service.connector.history_orders(start, end, bounded)),
        )

    @application.get(
        "/v1/history/deals", response_model=Envelope, dependencies=[Depends(authenticate)]
    )
    def history_deals(
        request: Request,
        date_from: datetime | None = None,
        date_to: datetime | None = None,
        limit: int = Query(100, ge=1, le=5000),
    ) -> Envelope:
        start, end, bounded = history_range(date_from, date_to, limit)
        return envelope(
            request,
            service.read(lambda: service.connector.history_deals(start, end, bounded)),
        )

    @application.get("/v1/heartbeat", response_model=Envelope, dependencies=[Depends(authenticate)])
    def heartbeat(request: Request) -> Envelope:
        return envelope(request, service.health())

    return application


def validate_symbol(symbol: str) -> str:
    normalized = symbol.strip().upper()
    if not 1 <= len(normalized) <= 20 or not normalized.replace(".", "").isalnum():
        raise BridgeError(ErrorCode.INVALID_REQUEST, "The symbol is invalid.", 422)
    return normalized


app = create_app()
