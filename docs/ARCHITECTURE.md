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

## Health truth model

`/api/v1/system/status` separates simulation readiness from optional `mt5_bridge` metadata. Broker transmission and demo/live execution remain false.

## Deployment

Phase 4 does not require public bridge exposure. See `PHASE_4_REPORT.md` for verification results and deployment boundaries.

## Nonexistent architecture

No MT5 write adapter, broker order path, DEMO/LIVE execution enablement, queue worker for broker commands, or Phase 5 automation exists in this repository state.
