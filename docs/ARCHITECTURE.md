# Architecture

## Repository overview

Nexa TradeBot is a React 19/TypeScript/Vite client with a Laravel 13/Sanctum API.

| Phase | Capability |
|---|---|
| Phase 1–2 | Simulation UI, session auth, RBAC, persistence foundation |
| Phase 3 | Persistent simulation trading domain (only executable environment) |
| Phase 4 | Read-only MT5 DEMO bridge (external observations; no broker writes) |
| Phase 5 | Market Data Engine (freshness/validation/quality + snapshot for UI) |
| Phase 6 | Indicator Engine (closed-candle indicators; no broker writes) |
| Phase 7 | Strategy Engine + Signal/Confluence (analysis only; no broker writes) |
| Phase 8 | Market Scanner + Signal Orchestrator (candidates only; no broker writes) |
| Phase 9 | Authoritative RiskEngine (decisions/plans/locks; no broker writes) |

Phase 3 execution remains `SIMULATION` only. Phase 4–9 add external read models, market snapshots, indicators, strategy signals, candidate queues, and risk authority without enabling DEMO/LIVE execution.

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

## Phase 6 system (summary)

```text
Closed candles (MarketDataEngine)
  → IndicatorEngine (SMA/EMA/RSI/MACD/ATR/BBANDS)
  → /api/v1/indicators/* → Live Charts overlays + panels
  → Phase 7 Strategy Engine (READY)
```

**Design:** [`INDICATOR_ENGINE.md`](INDICATOR_ENGINE.md) · [`PHASE_6_ARCHITECTURE.md`](PHASE_6_ARCHITECTURE.md)

**Completion audit:** [`PHASE_6_REPORT.md`](PHASE_6_REPORT.md)

## Phase 7 system (summary)

```text
MarketSnapshot + TechnicalSnapshot/MTF adapters
  → StrategyEngine (12 built-in plugins)
  → ConfluenceEngine + SignalEngine
  → /api/v1/strategy-engine/* + Signals/Strategies UI
  → SIMULATE → Simulation TradeIntent only
```

**Design:** [`STRATEGY_ENGINE.md`](STRATEGY_ENGINE.md) · [`PHASE_7_ARCHITECTURE.md`](PHASE_7_ARCHITECTURE.md)

**Completion audit:** [`PHASE_7_REPORT.md`](PHASE_7_REPORT.md)

## Phase 8 system (summary)

```text
MarketData + Technical adapters
  → MarketScannerEngine (universe × TF × strategies)
  → StrategyEngine / Signal / Confluence
  → SignalOrchestrator → Candidate Queue
  → Scanner board UI + Alert foundation
```

**Design:** [`SCANNER_ENGINE.md`](SCANNER_ENGINE.md) · [`SIGNAL_ORCHESTRATOR.md`](SIGNAL_ORCHESTRATOR.md) · [`PHASE_8_ARCHITECTURE.md`](PHASE_8_ARCHITECTURE.md)

**Completion audit:** [`PHASE_8_REPORT.md`](PHASE_8_REPORT.md)

## Phase 9 system (summary)

```text
TradeIntent + MarketSnapshot/symbol specs + RiskProfile
  → RiskEngine (versioned rule modules)
  → RiskDecision + ProposedPlan (+ Reservation / Lock)
  → /api/v1/risk-engine/* + Risk UI
  → SIMULATION ExecutionCommand only after APPROVED (Phase 3 path)
```

**Design:** [`RISK_ENGINE.md`](RISK_ENGINE.md) · [`RISK_RULES.md`](RISK_RULES.md) · [`POSITION_SIZING.md`](POSITION_SIZING.md)

**Phase 10 input (contract only):** [`PHASE_10_EXECUTION_CONTRACT.md`](PHASE_10_EXECUTION_CONTRACT.md)

**Completion audit:** [`PHASE_9_REPORT.md`](PHASE_9_REPORT.md)

## Health truth model

`/api/v1/system/status` separates simulation readiness from optional `mt5_bridge` metadata and reports `market_data_engine`, `indicator_engine`, `strategy_engine`, `market_scanner`, `signal_orchestrator`, and `risk_engine` as READY. Broker transmission and demo/live execution remain false.

## Deployment

Phase 9 does not require public bridge exposure. See `PHASE_9_REPORT.md` for verification results and deployment boundaries.

## Nonexistent architecture

No MT5 write adapter, broker order path, or DEMO/LIVE execution enablement exists. Phase 7–8 automation is analysis/signals/candidates only. Phase 9 risk proposals never auto-route to brokers.
