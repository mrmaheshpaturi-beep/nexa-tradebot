# Architecture

## Phase 4 system

Nexa TradeBot is a React 19/TypeScript/Vite client and Laravel 13/Sanctum API with a read-only MT5 DEMO bridge. Phase 3 simulation execution remains the only executable path.

```text
Browser / React
  ├─ TradingSourceProvider (SIMULATION | MT5_DEMO READ-ONLY)
  ├─ session/CSRF API client
  ├─ Phase 3 lifecycle pages (SIMULATION mutations only)
  ├─ Phase 4 MT5 read-only pages (Laravel-only)
  └─ retained mock analytical screens
                 │ same-origin /api and /sanctum
Laravel
  ├─ web session + Sanctum + active user + permission middleware
  ├─ TradeLifecycleService / SimulationExecutionAdapter (SIMULATION only)
  ├─ TradingBridgeClient → circuit breaker / retry / cache
  ├─ Mt5ReadModelService → sync + report-only reconciliation
  └─ Eloquent → SQLite (confirmed locally)
                 │ authenticated GET (server-side token)
Python FastAPI bridge (trading-engine/)
  ├─ MockMT5Connector (Linux/dev)
  └─ RealMT5Connector (Windows host only, lazy MetaTrader5 import)
```

The Vite dev server proxies `/api` and `/sanctum` to Laravel. React never calls the Python bridge directly and never receives bridge or terminal secrets.

## Trading-domain boundary

Phase 3 pipeline remains `Signal? → TradeIntent → RiskDecision → ExecutionCommand → Order → Deal → Position`. MT5 external tables are observations for reconciliation and dashboards, not lifecycle replacements.

## Phase 4 read boundary

Laravel exposes `/api/v1/mt5/*` read and read-model sync routes. The Python bridge exposes GET-only `/v1/*` endpoints with bearer authentication. No order placement, modification, cancellation, or execution endpoint exists in either layer.

## Health truth model

`/system/status` reports simulation readiness separately from `mt5_bridge` configuration/state. Broker transmission and demo/live execution remain false. Terminal adapter becomes `MT5_READ_ONLY` only when a bridge is configured and the circuit is `CONNECTED`.

## Deployment

Phase 4 does not require public bridge exposure. Windows terminal validation is manual and host-local. See `MT5_SETUP.md` and `PHASE_4_REPORT.md`.

## Nonexistent architecture

There is no MT5 write adapter, broker order path, DEMO/LIVE execution enablement, queue worker for broker commands, or Phase 5 automation in this delivery.
