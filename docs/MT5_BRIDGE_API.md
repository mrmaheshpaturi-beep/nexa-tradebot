# MT5 Bridge API Reference

Read-only APIs only. No execution, order placement, modification, cancellation, or broker write surface exists in Phase 4.

## Transport conventions

### Success envelope (Python bridge and Laravel proxies)

```json
{
  "data": { },
  "meta": {
    "source": "MT5",
    "environment": "DEMO",
    "mode": "READ_ONLY",
    "source_timestamp": "2026-09-18T12:00:00+00:00",
    "received_at": "2026-09-18T12:00:00+00:00",
    "freshness": "FRESH",
    "correlation_id": "uuid",
    "adapter_version": "0.1.0"
  }
}
```

Decimal values are lossless strings in bridge payloads. Laravel persists them using decimal columns.

### Error envelope

```json
{
  "error": {
    "code": "BRIDGE_UNAVAILABLE",
    "message": "Safe operator-facing message.",
    "correlation_id": "uuid"
  }
}
```

Laravel bridge client codes include: `BRIDGE_NOT_CONFIGURED`, `BRIDGE_CIRCUIT_OPEN`, `BRIDGE_UNAVAILABLE`, `BRIDGE_INVALID_RESPONSE`, `BRIDGE_REQUEST_REJECTED`, `MT5_RECORD_NOT_FOUND`.

Python bridge codes include: `AUTHENTICATION_REQUIRED`, `INVALID_REQUEST`, `NOT_FOUND`, `DISCONNECTED`, `CONNECTION_FAILED`, `PLATFORM_UNSUPPORTED`.

## Laravel API (`/api/v1/mt5/*`)

All routes require `auth:sanctum`, active user, and the listed permission. React uses these routes exclusively.

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/mt5/status` | `mt5.read` | Bridge configuration and connection summary |
| GET | `/mt5/bridge/health` | `mt5.read` | Proxy bridge health |
| GET | `/mt5/bridge/terminal` | `mt5.read` | Proxy terminal metadata |
| GET | `/mt5/bridge/account` | `mt5.read` | Proxy account snapshot |
| GET | `/mt5/bridge/symbols` | `mt5.read` | Proxy symbol list |
| GET | `/mt5/bridge/symbols/{symbol}` | `mt5.read` | Proxy symbol specification |
| GET | `/mt5/bridge/quotes/{symbol}` | `mt5.read` | Proxy latest quote |
| GET | `/mt5/bridge/candles/{symbol}` | `mt5.read` | Proxy candles (`timeframe`, `count` query) |
| GET | `/mt5/bridge/positions` | `mt5.read` | Proxy open positions |
| GET | `/mt5/bridge/orders` | `mt5.read` | Proxy open orders |
| GET | `/mt5/bridge/history/orders` | `mt5.read` | Proxy bounded order history |
| GET | `/mt5/bridge/history/deals` | `mt5.read` | Proxy bounded deal history |
| GET | `/mt5/connections` | `mt5.read` | List user bridge connections |
| POST | `/mt5/connections` | `mt5.connections.manage` | Create connection metadata |
| POST | `/mt5/connections/{id}/test` | `mt5.connections.manage` | Test bridge connectivity |
| POST | `/mt5/connections/{id}/sync` | `mt5.sync` | Persist external read models |
| GET | `/mt5/mappings/{id}/positions` | `mt5.read` | Persisted external positions |
| GET | `/mt5/mappings/{id}/orders` | `mt5.read` | Persisted external orders |
| GET | `/mt5/mappings/{id}/deals` | `mt5.read` | Persisted external deals |
| POST | `/mt5/mappings/{id}/reconcile` | `mt5.reconcile` | Report-only reconciliation |
| GET | `/mt5/reconciliation-runs` | `mt5.read` | List reconciliation runs |
| GET | `/mt5/reconciliation-runs/{id}` | `mt5.read` | Reconciliation run detail |

Route definitions: `backend/routes/api.php`. Controller: `Mt5BridgeController`.

### History query bounds (proxied)

- `date_from`, `date_to`: timezone-aware ISO datetimes; `date_to` after `date_from`; range ≤ 90 days.
- `limit`: 1–5000 on bridge; Laravel config may cap lower via `TRADING_BRIDGE_HISTORY_MAX_RECORDS`.

## Python bridge API (`/v1/*`)

Authenticated with `Authorization: Bearer <SERVICE_TOKEN>`. **GET only.** OpenAPI is disabled in production configuration.

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/health` | Adapter health, `read_only`, connection state |
| GET | `/v1/terminal` | Terminal metadata and build version |
| GET | `/v1/account` | Account identity and balance snapshot |
| GET | `/v1/symbols` | Symbol specification list |
| GET | `/v1/symbols/{symbol}` | Single symbol specification |
| GET | `/v1/quotes/{symbol}` | Latest bid/ask quote |
| GET | `/v1/candles/{symbol}` | Historical candles (`timeframe`, `count`) |
| GET | `/v1/positions` | Open positions |
| GET | `/v1/orders` | Open/pending orders |
| GET | `/v1/history/orders` | Bounded order history |
| GET | `/v1/history/deals` | Bounded deal history |
| GET | `/v1/heartbeat` | Health alias for heartbeat polling |
| GET | `/v1/market/quotes` | Engine-normalized quotes (+ optional `symbols`) |
| GET | `/v1/market/quotes/{symbol}` | Engine-normalized single quote |
| GET | `/v1/market/candles/{symbol}` | Engine-normalized candles |
| GET | `/v1/market/symbols` | Engine-normalized symbols |
| GET | `/v1/market/snapshot` | Aggregated market snapshot |

## Laravel market engine API (`/api/v1/market/*`)

Requires `auth:sanctum` and `trading.read`. Prefer query: `prefer=auto|bridge|simulation`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/market/snapshot` | Aggregated snapshot (persists by default) |
| GET | `/market/quotes` | Normalized quotes |
| GET | `/market/candles/{symbol}` | Normalized candles |
| GET | `/market/symbols` | Normalized symbols |
| GET | `/market/snapshots/latest` | Latest persisted snapshot row |
| GET | `/market/extension-hooks` | Phase 6/7 PENDING stubs |

See `PHASE_5_ARCHITECTURE.md`.

Implementation: `trading-engine/src/nexa_mt5/api.py`. Local mock startup: `trading-engine/README.md`.

### Candle timeframes

`M1`, `M5`, `M15`, `M30`, `H1`, `H4`, `D1`.

### Symbol validation

1–20 characters, alphanumeric plus `.`, uppercased by the bridge.

## Access matrix summary

| Caller | May call |
|---|---|
| React browser | Laravel `/api/v1/mt5/*` only |
| Laravel | Python `/v1/*` with server token |
| Python bridge | MT5 terminal read API only (Windows real mode) |
| Public internet | Nothing by default; bridge is not deployed |

See also `AUTHORIZATION.md`, `MT5_INTEGRATION_CONTRACT.md`, and `PHASE_4_ARCHITECTURE.md`.
