# Architecture

## Repository overview

Nexa TradeBot is a React 19/TypeScript/Vite client with a Laravel 13/Sanctum API.

| Phase | Capability |
|---|---|
| Phase 1–2 | Simulation UI, session auth, RBAC, persistence foundation |
| Phase 3 | Persistent simulation trading domain (only executable environment) |
| Phase 4 | Read-only MT5 DEMO bridge (external observations; no broker writes) |
| Phase 5 | Market Data Engine (freshness/validation/quality + snapshot for UI) |

Phase 3 execution remains `SIMULATION` only. Phase 4–5 add external read models and market snapshots without enabling DEMO/LIVE execution.

## Phase 3 system (summary)

```text
React SPA → Laravel (session + permissions) → SimulationExecutionAdapter → SQLite
```

Canonical pipeline: `Signal? → TradeIntent → RiskDecision → ExecutionCommand → Order → Deal → Position`.

See `TRADE_LIFECYCLE.md`, `EXECUTION_MODEL.md`, `STATE_MACHINES.md`, `MARKET_DATA_CONTRACT.md`, and `PHASE_3_REPORT.md`.

## Phase 4 system (summary)

Phase 4 adds a Python FastAPI read-only bridge, Laravel `TradingBridgeClient`, MT5 read-model persistence, and React source selection (`SIMULATION` | `MT5 DEMO READ-ONLY`).

**Full Phase 4 design:** [`PHASE_4_ARCHITECTURE.md`](PHASE_4_ARCHITECTURE.md)

**API reference:** [`MT5_BRIDGE_API.md`](MT5_BRIDGE_API.md)

**Windows validation:** [`MT5_WINDOWS_SETUP.md`](MT5_WINDOWS_SETUP.md) — status **PENDING WINDOWS ENVIRONMENT**

## Phase 5 system (summary)

Phase 5 adds a Market Data Engine that normalizes bridge/mock quotes, candles, and symbols with freshness and quality metadata, persists snapshots, and powers Market Watch / Live Charts.

**Full Phase 5 design:** [`PHASE_5_ARCHITECTURE.md`](PHASE_5_ARCHITECTURE.md)

**Completion audit:** [`PHASE_5_REPORT.md`](PHASE_5_REPORT.md)

## Health truth model

`/api/v1/system/status` separates simulation readiness from optional `mt5_bridge` metadata and reports `market_data_engine.status = READY`. Broker transmission and demo/live execution remain false.

## Deployment

Phase 5 does not require public bridge exposure. See `PHASE_5_REPORT.md` for verification results and deployment boundaries.

## Nonexistent architecture

No MT5 write adapter, broker order path, DEMO/LIVE execution enablement, Phase 6 indicator engine, or Phase 7 strategy automation exists beyond declared PENDING hooks.
