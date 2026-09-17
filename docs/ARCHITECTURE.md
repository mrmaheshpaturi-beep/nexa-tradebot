# Architecture

## Implemented Phase 2 system

Nexa TradeBot is a React 19 + strict TypeScript 6 + Vite 8 single-page client and a separate Laravel 13 + Sanctum 4 API in `backend/`. Phase 2 adds session authentication, role/permission enforcement, portable migrations, and selected database-backed workflows while preserving the simulation-only boundary.

```text
Browser / React
  ├─ HashRouter + ProtectedRoute
  ├─ AuthContext (identity and UI permission hints)
  ├─ api/client.ts (cookies, CSRF, JSON errors, 401 callback)
  ├─ api/services.ts (endpoint adapter)
  ├─ persistentServices.ts → authenticated Laravel REST calls
  └─ mockServices.ts → retained market/trading fixtures
                   │ same-origin /api and /sanctum
Laravel
  ├─ web session guard + Sanctum stateful API
  ├─ auth:sanctum → active-user → permission middleware
  ├─ validated controllers and application services
  └─ Eloquent → SQLite development database
```

The Vite development server proxies `/api` and `/sanctum` to `http://127.0.0.1:43128`. The browser obtains `/sanctum/csrf-cookie`, sends an HTTP-only Laravel session cookie and `X-XSRF-TOKEN`, and includes credentials on every API request. No token is kept in local storage. Sanctum's personal-access-token table exists for framework support, but Phase 2 does not issue API tokens.

## React boundaries

- `src/api/client.ts`: initializes CSRF for writes, normalizes JSON/validation errors, sends same-origin credentials, and clears auth state on `401`.
- `src/api/services.ts`: typed auth and Phase 2 endpoint calls.
- `src/auth/`: login/reset-request presentation, startup session restoration, logout, permission lookup, and route protection.
- `src/services/persistenceServices.ts`: page-facing adapters for dashboard, strategies, risk, accounts, notifications, audit logs, settings/preferences, and simulation orders.
- `src/services/mockServices.ts`: explicitly retained deterministic fixtures where no Phase 2 API exists.
- `src/domain/types.ts`: transport-independent Phase 1 trading models. `src/api/types.ts` describes Laravel response shapes.
- `src/pages/PersistentTradingPages.tsx`, `PersistentOperationsPages.tsx`, and `SettingsPage.tsx`: database-backed screens.
- `src/pages/TradingPages.tsx` and `OperationsPages.tsx`: retained simulation/mock screens.

`ProtectedRoute` blocks unauthenticated navigation, and `can()` hides or disables controls. Those are user-experience boundaries only; backend middleware remains authoritative.

### Persistence versus retained mocks

Database-backed UI: current identity, dashboard counts/latest account snapshot, strategies list, risk profiles, emergency-stop mutation, broker-account metadata list, notification list/read state, audit logs, application settings, personal preferences, user administration, and simulation-order creation.

Retained deterministic mocks: market watch, scanner, AI signals, live charts/quotes/candles, auto-trading presentation, positions, pending orders, trade history, backtesting, paper trading, analytics/equity history, reports, news calendar, and parts of the dashboard chart. There is no market-data, signals-list, positions-list, deals-list, trades-list, risk-events-list, account-snapshots-list, simulated-orders-list, backtest, analytics, news, report-export, or system-events-list API.

## Laravel HTTP boundary

All routes are under `/api/v1` and use Laravel's `web` middleware so session and CSRF facilities are available.

Public:

- `POST /auth/login`
- `POST /auth/password/request`
- `POST /auth/password/reset`
- `GET /system/status`
- `GET /simulation/status` (compatibility alias)

Active authenticated session:

- `GET /auth/me`; `POST /auth/logout`
- `GET /dashboard`
- `GET|POST /users`; `PUT /users/{user}`; `POST /users/{user}/activate|suspend|disable`
- `GET|POST /strategies`; `PUT /strategies/{strategy}`
- `GET|POST /risk-profiles`; `PUT /risk-profiles/{riskProfile}`
- `GET|POST /broker-accounts`; `PUT /broker-accounts/{brokerAccount}`
- `GET /settings`; `PUT /settings/{key}`; `PUT /emergency-stop`
- `GET|PUT /preferences`
- `GET /notifications`; `POST /notifications/{notification}/read`
- `GET /audit-logs`
- `POST /simulation/orders`

There is no role-discovery endpoint, simulated-order list endpoint, or mark-all-notifications endpoint.

## Backend services and enforcement

- `UserService` atomically creates/updates users, synchronizes one assigned role, creates preferences, changes status, and records audit entries.
- `SettingsService` supplies fail-safe defaults, rejects enabling hard-locked execution settings, persists global values transactionally, and audits changes.
- `SimulationOrderService` checks stop/trading gates and ownership, provides user-scoped idempotency plus globally unique command IDs, persists a simulation-only order and audit record in one transaction, and never creates a deal or position.
- `AuditService` captures actor, entity, before/after data, IP, user agent, result, and timestamp. The model rejects update and delete operations.
- Resource controllers validate request fields and enforce current-user ownership where records are user scoped.

The legacy Phase 1 `SimulationRepository` and `InMemorySimulationRepository` remain, but `/simulation/status` now maps to `SystemController`; the Phase 2 persisted order path uses `SimulationOrderService`.

## Data architecture and portability

SQLite is confirmed for local development. The migrations use Laravel Schema Builder and no vendor-specific SQL. This is a portability design for MySQL and PostgreSQL, not evidence that either engine has been tested. See `DATABASE_SCHEMA.md` for every table, relationship, constraint, index, environment column, and migration command.

## Build architecture

The standard `npm run build` runs TypeScript project compilation then Vite's normal code-split production build. Lazy route modules produce separate assets. The current chart chunk exceeds Vite's 500 kB advisory threshold.

`npm run build:hostinger` sets `HOSTINGER_SINGLE_FILE=true`; `vite-plugin-singlefile` inlines JavaScript and CSS into `dist/index.html`. This is an optional static-hosting artifact, not a Laravel deployment. The API still must be hosted and routed separately; uploading the single file alone leaves authenticated/persistent pages without a backend.

## Nonexistent future architecture

There is no deployment, Python service, Redis integration, WebSocket stream, real market-data ingestion, AI engine, authoritative risk engine, execution engine, MT5 adapter, broker session, credential vault, or real-funds path. A possible later boundary remains:

```text
React → Laravel → future trading services
                  Strategy → Signal/AI → authoritative Risk Engine
                                              → Execution Engine → MT5 adapter
```

This is recommendation-level architecture only. AI must never bypass the future risk engine or call MT5 directly.
