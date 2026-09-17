# Roadmap

## Delivered

### Phase 1 — simulation interface foundation

Delivered the 22-screen React terminal, typed trading domain, deterministic mock services, charts, simulation-safe UI, Laravel in-memory boundary, and initial quality/documentation baseline. `PHASE_1_REPORT.md` remains the historical Phase 1 record.

### Phase 2 — authentication and portable persistence foundation

Implemented:

- Laravel session authentication with Sanctum CSRF/stateful middleware;
- active-user enforcement, five seeded roles, 23 permissions, and backend route checks;
- password-reset token request/reset endpoints with delivery explicitly unconfigured;
- SQLite-confirmed schema for identity, settings, audit, strategy/risk/account foundations, and distinct Signal/Order/Deal/Position/Trade records;
- migrations designed for MySQL/PostgreSQL portability, not yet validated on those engines;
- database-backed user administration, preferences, application settings, dashboard summary, strategies, risk profiles, broker metadata, notifications/read state, audit logs, and simulation-order writes;
- server safety locks, ownership checks, command/idempotency keys, and transactional audit;
- React auth/API adapters while retaining mocks where no API exists;
- standard split Vite build and optional Hostinger single-file static build;
- automated frontend/backend tests and static/build gates.

Phase 2 did not deploy the application and did not add broker connectivity, MT5, real market data, real AI, a risk engine, or execution.

## Known Phase 2 gaps

- No roles/permissions discovery endpoint; the five roles are duplicated in the React editor.
- User search/status filters are client-side and cover only the fetched paginated page; no pagination controls are exposed.
- No simulation-order list, mark-all-notifications, signal, deal, position, trade, risk-event, account-snapshot, or system-event list endpoint.
- No API for market data, scanner, charts, AI signals, news, backtesting, paper trading, analytics, or report export; those screens remain mocks.
- Password-reset delivery and frontend token-completion flow are not configured.
- Strategy/risk/account create/update APIs exist, but the Phase 2 UI primarily lists those resources.
- MySQL and PostgreSQL are portability targets only; SQLite is the confirmed development/test engine.
- The standard build retains a chart chunk above Vite's 500 kB advisory threshold.
- No deployment, production configuration, observability, recovery exercise, or browser end-to-end suite.

## Recommended Phase 3 only

Phase 3 should be considered a recommendation, not started work: complete the trading-domain backend contracts around the existing schema without broker execution. Priorities should include server-side pagination/filtering, role discovery, simulation-order history, missing read APIs, explicit resource policies/authorization tests, target-engine portability tests, complete audit coverage decisions, password-reset delivery, and replacing mocks only where reliable backend producers exist.

Phase 3 should continue to prohibit MT5/broker credentials and execution unless a separately approved phase explicitly authorizes a safe DEMO integration boundary.

## Later roadmap (not approved or implemented)

4. MT5 DEMO connectivity boundary
5. Real market-data ingestion
6. Indicator computation
7. Strategy engine
8. Market scanner and real signal pipeline
9. Authoritative risk engine
10. MT5 DEMO execution
11. Position and order lifecycle management
12. AI analysis behind the risk boundary
13. News and session intelligence
14. Historical-data backtesting engine
15. Real-price paper trading
16. Persistent analytics and valid report exports
17. Notification delivery
18. Security hardening and tamper-evident audit
19. Recovery, reconciliation, and observability
20. XM DEMO end-to-end validation
21. Cloud application plus Windows VPS deployment

Each later phase requires explicit owner approval, acceptance criteria, threat review, tests, and rollback planning. Real-money activation is not implied by any roadmap item and requires a separate governance decision.
