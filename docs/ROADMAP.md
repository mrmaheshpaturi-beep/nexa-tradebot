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

## Phase 4 — proposed read-only MT5 integration

Not implemented or approved by Phase 3. The first integration step must be read-only: terminal health, account/symbol/quote reads, snapshots and reconciliation. No place/modify/cancel/close capability. See `MT5_INTEGRATION_CONTRACT.md`.

Required prerequisites include architecture/threat review, isolated adapter identity, credential/key design, Windows host controls, normalized decimal/freshness contracts, timeouts, rate limits, redaction, correlation/audit, failure tests and operator runbooks.

## Later phases requiring separate approval

1. Real market-data ingestion and freshness controls.
2. Indicator computation and strategy/scanner engines.
3. Authoritative portfolio risk engine.
4. MT5 DEMO write adapter with durable delivery and reconciliation.
5. AI analysis behind the risk boundary.
6. News/session intelligence.
7. Historical backtesting and real-price paper trading.
8. Persistent analytics/report exports and notification delivery.
9. Security hardening, tamper-evident audit, observability and recovery.
10. DEMO end-to-end validation and controlled deployment.

LIVE/real-money activation is not implied by any phase. It requires a separate governance, legal, security, operational and rollback decision after DEMO evidence.
