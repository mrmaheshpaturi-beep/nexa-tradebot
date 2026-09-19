# Architecture

## Repository overview

Nexa TradeBot is a React 19/TypeScript/Vite client with a Laravel 13/Sanctum API.

| Phase | Capability |
|---|---|
| Phase 1–2 | Simulation UI, session auth, RBAC, persistence foundation |
| Phase 3 | Persistent simulation trading domain (only executable environment) |
| Phase 4 | Read-only MT5 DEMO bridge (external observations; no broker writes) |
| Phase 10 | DEMO-only ExecutionEngine (manual two-step confirm; sole authorized order_send) |
| Phase 5 | Market Data Engine (freshness/validation/quality + snapshot for UI) |
| Phase 6 | Indicator Engine (closed-candle indicators; no broker writes) |
| Phase 7 | Strategy Engine + Signal/Confluence (analysis only; no broker writes) |
| Phase 8 | Market Scanner + Signal Orchestrator (candidates only; no broker writes) |
| Phase 9 | Authoritative RiskEngine (decisions/plans/locks; no broker writes) |

Phase 3 execution remains `SIMULATION` via `SimulationExecutionAdapter`. Phase 4–9 add read models, indicators, strategies, scanner, and risk authority. Phase 10 adds gated DEMO ExecutionEngine (manual confirm; LIVE hard-fail).

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

Phase 10 adds a gated DEMO write path behind ExecutionGate + two-step confirmation. LIVE remains hard-fail. Phase 7–8 automation is analysis/signals/candidates only. Phase 9 risk proposals never auto-route; ExecutionEngine consumes approved plans only after manual DEMO confirmation. Sole order_send: `trading-engine/src/nexa_mt5/execution.py::authorized_order_send`.


## Phase 11 — Trade Management

DEMO-only `TradeManagementEngine` manages Nexa-owned positions (break-even, trailing, partial/full close, exits) behind `PositionManagementGate`. LIVE hard-blocked. Adapter extends Phase 10 with modify/close/partial/cancel; sole `order_send` unchanged.

## Phase 12 — Analytics + Backtesting / Research

```text
TradeSummary (+ signal/candidate/risk/execution/management lineage)
  → AnalyticsEngine → datasets/snapshots/metrics/exports/trade explorer
Closed candles snapshot
  → BacktestEngine (BACKTEST only) → lineage + WF/OOS/MC/opt/portfolio
  → BACKTEST vs DEMO comparison (labels never mixed)
  → NEVER order_send / NEVER auto-promote
```

**Design:** [`ANALYTICS_ENGINE.md`](ANALYTICS_ENGINE.md) · [`BACKTEST_ENGINE.md`](BACKTEST_ENGINE.md)

**Completion audit:** [`PHASE_12_REPORT.md`](PHASE_12_REPORT.md)

## Phase 13 — Trade Intelligence Engine

```text
Market/strategy/confluence/scanner/analytics evidence (labels kept distinct)
  → TradeIntelligenceEngine (ADVISORY | SHADOW)
      → technical/MTF/regime + ensemble + opportunity ranking
      → market quality / volatility / spread / anomaly
      → calendar/news providers (MOCK | UNAVAILABLE fail-closed)
      → AIAnalysisService (Mock in CI; structured + hashed + injection-guarded)
  → NEVER order_send / NEVER mutate risk|settings|strategy / LIVE HARD_BLOCKED
```

**Design:** [`TRADE_INTELLIGENCE_ENGINE.md`](TRADE_INTELLIGENCE_ENGINE.md) · [`AI_ANALYSIS_SERVICE.md`](AI_ANALYSIS_SERVICE.md)

**Phase 14 input (contract only):** [`PHASE_14_CONTRACT.md`](PHASE_14_CONTRACT.md)

**Completion audit:** [`PHASE_13_REPORT.md`](PHASE_13_REPORT.md)


## Phase 14 — Automated DEMO Trading Orchestrator

```text
OFF (default) | DRY_RUN (zero broker) | DEMO_AUTO
  → AutomatedTradingOrchestrator (modular tick/queues)
      → Qualification (AI not authority) → Risk → Phase 10 Execution
      → Phase 11 Management → Phase 12 Analytics
  → NEVER LIVE_AUTO / Phase 14 order_send = 0
```

**Design:** [`AUTOMATED_TRADING_ORCHESTRATOR.md`](AUTOMATED_TRADING_ORCHESTRATOR.md) · [`PHASE_14_AUTOMATION_ORCHESTRATION_CONTRACT.md`](PHASE_14_AUTOMATION_ORCHESTRATION_CONTRACT.md)

**Completion audit:** [`PHASE_14_REPORT.md`](PHASE_14_REPORT.md)
