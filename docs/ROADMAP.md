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

## Later phases requiring separate approval

1. Phase 9 candidate → intent / DEMO write path (not started; contract remains simulation-first).
2. Authoritative portfolio risk engine.
3. MT5 DEMO write adapter with durable delivery and reconciliation.
4. AI analysis behind the risk boundary.
5. News/session intelligence.
6. Historical backtesting and real-price paper trading.
7. Persistent analytics/report exports and notification delivery.
8. Security hardening, tamper-evident audit, observability and recovery.
9. DEMO end-to-end validation and controlled deployment.

LIVE/real-money activation is not implied by any phase. It requires a separate governance, legal, security, operational and rollback decision after DEMO evidence.
