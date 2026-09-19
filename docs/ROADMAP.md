# Roadmap

## Delivered

### Phase 1 — simulation interface

React terminal, typed frontend domain, deterministic UI mocks, charts and simulation-safe presentation.

### Phase 2 — authentication and persistence

Laravel/Sanctum session auth, five-role RBAC, portable schema foundation, persistent administration/settings/risk/account/strategy records, audit and safe legacy simulation-order persistence.

### Phase 3 — persistent simulation trading domain

- canonical instrument, signal, intent, risk-decision, command, order, deal, position, event, terminal/session/heartbeat and snapshot contracts;
- deterministic mock quote/provider and financial calculations;
- explicit intent → risk → simulation-command orchestration;
- MARKET fills, pending acceptance/cancellation, SL/TP changes, partial/full close and signal-to-intent;
- state guards, user-scoped idempotency, controlled failures, account recalculation and audit;
- persisted React manual/signals/orders/positions/lifecycle/health workflows;
- backend quote/spec guidance and exact protection-side explanations;
- exact simulation/no-broker health and expanded RBAC;
- SQLite migration/seed and automated quality/security verification;
- Phase 3 architecture, contracts, state machines and audit report.

Phase 3 does not deploy and does not implement MT5, broker connectivity, real market data, real AI or live execution.

## Known Phase 3 limitations

- Pending orders have no market-trigger/fill/expiry scheduler.
- Backend quotes are fixed mocks; frontend charts/scanner/news/backtests/paper/analytics remain mocks.
- The deterministic risk evaluator implements core simulation gates, not every future reason code or broker-grade rule.
- Heartbeats are seeded/read-only; sessions are schema foundation only.
- No role-discovery, account-snapshot list or position-event top-level API.
- Password reset delivery/completion UI remains unconfigured.
- MySQL/PostgreSQL portability is unverified.
- No browser E2E/Lighthouse suite, deployment hardening, observability/recovery exercise or distributed execution reconciliation.
- Standard build may retain the existing large chart-chunk advisory.

## Phase 4 — read-only MT5 integration (delivered in code)

Implemented on `cursor/phase-4-mt5-readonly-56f9`:

- Python FastAPI read-only bridge with mock and Windows-only real connector abstraction;
- Laravel bridge client, MT5 APIs, sync cursors, external read models, report-only reconciliation, RBAC;
- React SIMULATION/MT5 DEMO source selector and read-only MT5 pages;
- automated tests, static no-execution audit, and documentation.

SIMULATION remains the only executable environment. Real MT5 terminal validation is **PENDING WINDOWS ENVIRONMENT**. No deployment, public bridge exposure, or Phase 5 work occurred.

See `PHASE_4_REPORT.md`, `PHASE_4_ARCHITECTURE.md`, `MT5_INTEGRATION_CONTRACT.md`, `MT5_BRIDGE_API.md`, and `MT5_WINDOWS_SETUP.md`.

## Phase 5 — Market Data Engine (delivered — PASS WITH WARNINGS; Real MT5 PENDING WINDOWS)

Implemented on `cursor/phase-5-market-data-56f9`:

- Python Market Data Engine with freshness, validation, and quality scoring;
- Laravel snapshot APIs and persistence (`market_*` tables);
- React Market Watch + Live Charts consuming the snapshot;
- Phase 6 input contract (`getClosedCandles`) ready.

Still READ-ONLY for MT5. Real Windows terminal validation remains pending.

See `PHASE_5_REPORT.md` and `PHASE_5_ARCHITECTURE.md`.

## Phase 6 — Indicator Engine (delivered)

Implemented on `cursor/phase-6-indicator-engine-56f9`:

- IndicatorEngine consuming closed candles from MarketDataEngine only;
- Providers: SMA, EMA, RSI, MACD, ATR, Bollinger Bands;
- Laravel catalog/compute/series/batch APIs with quality gate + cache;
- React chart overlays and indicator panel with source/freshness;
- Phase 7 strategies consume indicator/technical adapters.

See `PHASE_6_REPORT.md`, `PHASE_6_ARCHITECTURE.md`, and `INDICATOR_ENGINE.md`.

## Phase 7 — Strategy Engine + Signals + Confluence (delivered)

Implemented on `cursor/phase-7-strategy-engine-56f9`:

- Built-in TradingStrategy plugins (12) + StrategyRegistry;
- TechnicalAnalysisEngine adapter + MTF/structure/S-R snapshots;
- SignalEngine + ConfluenceEngine with transparent 0–100 scoring;
- Gates, fingerprint/cooldown/expiry, performance stats (no fake win rates);
- Signals / Strategies UI (not “AI”), scanner, matrix, health;
- ANALYSIS AND SIGNALS ONLY — no broker execution / AutoTrading.

See `PHASE_7_REPORT.md`, `STRATEGY_ENGINE.md`, `SIGNAL_ENGINE.md`, `CONFLUENCE_ENGINE.md`.

## Phase 8 — Market Scanner + Signal Orchestration (delivered)

Implemented on `cursor/phase-8-market-scanner-56f9`:

- MarketScannerEngine — multi-symbol/MTF/strategy universe scans with MANUAL / ON_INTERVAL / ON_CANDLE_CLOSE;
- SignalOrchestrator — candidate ranking, conflict detection, lifecycle, mark-for-SIMULATE;
- Candidate Queue + live Scanner board UI;
- Alert pipeline foundation (in-app + hook placeholder);
- System health + APIs; no broker routing / order_send.

See `PHASE_8_REPORT.md`, `SCANNER_ENGINE.md`, `SIGNAL_ORCHESTRATOR.md`, `PHASE_8_ARCHITECTURE.md`.

## Phase 9 — Authoritative RiskEngine (delivered)

Implemented on `cursor/phase-9-risk-engine-56f9`:

- Authoritative `RiskEngineService` with modular versioned rules;
- Symbol-aware position sizing + ProposedPlan (never broker orders);
- Daily/weekly loss, drawdown, exposure, correlation, margin, spread, session, loss-streak, locks;
- Reservations / concurrency protection; immutable decisions;
- Risk APIs, RBAC, audit, events, health heartbeat, Risk UI;
- Phase 10 execution contract stub only.

See `PHASE_9_REPORT.md`, `RISK_ENGINE.md`, `PHASE_10_EXECUTION_CONTRACT.md`.

## Phase 10 — DEMO-only ExecutionEngine (delivered)

Implemented on `cursor/phase-10-execution-engine-56f9`:

- ExecutionEngine with DEMO lifecycle, two-step confirmation, locks, gates, reconciliation/recovery;
- Sole authorized `order_send` in Python bridge execution module;
- Fake CI adapter; LIVE hard-fail; Auto Demo OFF;
- Execution UI + docs; Phase 11 contract stub only.

See `PHASE_10_REPORT.md`, `EXECUTION_ENGINE.md`, `PHASE_10_EXECUTION_CONTRACT.md`.

## Later phases requiring separate approval

1. Phase 11 operational hardening / expanded DEMO position ops (contract only — `PHASE_11_CONTRACT.md`).
2. Durable outbox / exactly-once delivery beyond process-local nonce cache.
3. AI analysis behind the risk boundary.
4. News/session intelligence.
5. Historical backtesting and real-price paper trading.
6. Persistent analytics/report exports and notification delivery.
7. Security hardening, tamper-evident audit, observability and recovery.
8. DEMO end-to-end validation and controlled deployment.

LIVE/real-money activation is not implied by any phase. It requires a separate governance, legal, security, operational and rollback decision after DEMO evidence.

## Phase 11 — Advanced Trade & Position Management (DEMO)

Complete on branch `cursor/phase-11-trade-management-56f9`.

## Phase 12 — Analytics + Backtesting / Research Engine

Complete on branch `cursor/phase-12-analytics-backtest-56f9`:

- AnalyticsEngine datasets/snapshots/metrics/exports over TradeSummary lineage
- BacktestEngine deterministic BACKTEST runs with lineage, costs, WF/OOS, MC, opt, portfolio
- Strict BACKTEST/DEMO separation; zero broker-changing calls; no auto-promote
- Phase 13 contract stub only (superseded by Phase 13 delivery)

See `PHASE_12_REPORT.md`, `ANALYTICS_ENGINE.md`, `BACKTEST_ENGINE.md`.

## Phase 13 — Trade Intelligence Engine

Complete on branch `cursor/phase-13-trade-intelligence-56f9`:

- TradeIntelligenceEngine (ADVISORY/SHADOW) with assessments, ensemble, ranking, market quality
- AIAnalysisService + Mock AI/News/Calendar (CI deterministic; paid not required)
- Evidence label separation; usage/audit/health; AI Trade Desk UI
- Zero intelligence→execution/risk/settings/strategy mutation paths; LIVE hard-blocked
- Phase 14 contract stub only

See `PHASE_13_REPORT.md`, `TRADE_INTELLIGENCE_ENGINE.md`, `AI_ANALYSIS_SERVICE.md`, `PHASE_14_CONTRACT.md`.

## Phase 14 — Automated DEMO Trading Orchestrator

Complete on branch `cursor/phase-14-demo-automation-56f9`:

- AutomatedTradingOrchestrator (OFF / DRY_RUN / DEMO_AUTO; no LIVE_AUTO)
- Startup/safety gates, qualification, workflows, locks, kill switch, Control Center UI
- Phase 10 sole order_send; Phase 14 sites = 0; AI never authority / never MT5
- Phase 15 contract stub only

See `PHASE_14_REPORT.md`, `AUTOMATED_TRADING_ORCHESTRATOR.md`, `PHASE_14_AUTOMATION_ORCHESTRATION_CONTRACT.md`.

## Later phases requiring separate approval

1. Phase 15 operational follow-ons (contract only — `PHASE_15_CONTRACT.md`).
2. Durable outbox / exactly-once delivery beyond process-local nonce cache.
3. Security hardening, tamper-evident audit, observability and recovery.
4. Real Windows MT5 DEMO end-to-end automation validation and controlled deployment.

LIVE/real-money activation is not implied by any phase. It requires a separate governance, legal, security, operational and rollback decision after DEMO evidence.



## Phase 15 — Validation, Observability & Production Hardening

**Status: COMPLETE (PASS WITH WARNINGS).** See `PHASE_15_REPORT.md`.


## Phase 16 — Strategy Governance / Release Engineering / Controlled DEMO Promotion

Complete on branch `cursor/phase-16-strategy-governance-56f9`:

- StrategyGovernanceService — immutable versions, lifecycle, RC, evidence, two-step approvals
- DEMO-only promotion into Phase 14 AutomationProfile; LIVE_AUTO absent
- Strategy Lab / comparisons / portfolios / change requests
- Governance UI; Phase 17 contract stub only

See `PHASE_16_REPORT.md`, `STRATEGY_GOVERNANCE.md`, `PHASE_16_CONTRACT.md`, `PHASE_17_CONTRACT.md`.

## Later phases requiring separate approval

1. Phase 17 operational follow-ons (contract only — `PHASE_17_CONTRACT.md`).
2. Durable outbox / exactly-once delivery beyond process-local nonce cache.
3. Real Windows MT5 DEMO end-to-end automation validation and controlled deployment.

LIVE/real-money activation is not implied by any phase. It requires a separate governance, legal, security, operational and rollback decision after DEMO evidence.
