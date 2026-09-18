# Architecture

## Implemented Phase 3 system

Nexa TradeBot is a React 19/strict TypeScript/Vite client and Laravel 13/Sanctum API. Phase 3 adds a persistent simulation trading domain while preserving Phase 2 authentication, RBAC, settings and operational persistence.

```text
Browser / React
  ├─ HashRouter + protected routes
  ├─ session/CSRF API client
  ├─ typed Phase 2/3 services
  ├─ persisted lifecycle, order, position, signal and health screens
  └─ explicitly retained mock-only analytical screens
                 │ same-origin /api and /sanctum
Laravel
  ├─ web session + Sanctum stateful middleware
  ├─ active-user + named permission middleware
  ├─ controllers / lifecycle services / guarded state machines
  ├─ MarketDataProvider → MockMarketDataProvider
  ├─ ExecutionAdapter → SimulationExecutionAdapter
  └─ Eloquent → confirmed local SQLite
```

The Vite development server proxies `/api` and `/sanctum` to `http://127.0.0.1:43128`. The browser obtains the CSRF cookie, sends HTTP-only session cookies and `X-XSRF-TOKEN`, and stores no bearer token.

## Trading-domain boundary

The Phase 3 pipeline is `Signal? → TradeIntent → RiskDecision → ExecutionCommand → Order → Deal → Position → PositionEvent → AccountSnapshot`. Each is a distinct persistent entity. Controllers never call a broker. Execution is gated and delegated to the deterministic simulation adapter.

Primary services:

- `TradeLifecycleService`: orchestration, ownership, idempotency, transactions, transitions and audit.
- `SimulationRiskEvaluator`: account/instrument/volume/snapshot/protection/risk/reward checks.
- `ExecutionGate`: exact simulation/account/settings gate.
- `SimulationExecutionAdapter`: fixed-price MARKET fills and accepted local mutations.
- `MockMarketDataProvider`: deterministic quotes/candles/specifications.
- `FinancialCalculator`: volume, P/L, margin, risk and reward/risk calculations.
- `AccountStateCalculator`/`Updater`: deterministic snapshots with same-timestamp ID tie-breaking.
- `SimulationPositionReconciliationService`: deal/position consistency check.

See `TRADE_LIFECYCLE.md`, `EXECUTION_MODEL.md`, `STATE_MACHINES.md`, and `MARKET_DATA_CONTRACT.md`.

## React boundaries

- `src/api/client.ts`: cookies, CSRF, normalized validation errors and `401` handling.
- `src/api/services.ts`/`types.ts`: typed transport contracts.
- `src/pages/PhaseThreeTradingPages.tsx`: manual intent/risk/execute flow, signals, orders, position controls and lifecycle/detail drawers.
- `src/pages/PersistentOperationsPages.tsx`: exact backend health and risk controls.
- `src/services/mockServices.ts`: retained deterministic fixtures for screens without backend producers.

The manual form defaults to seeded EURUSD when present, displays the backend mock bid/ask and instrument precision, and explains valid BUY/SELL protection geometry. React permission checks are presentation only; Laravel middleware is authoritative.

## HTTP boundary

Public: login, password request/reset, `/system/status`, and `/simulation/status`.

Authenticated Phase 3 reads:

- instruments, signals, trade intents, orders, positions and heartbeats;
- individual instrument/signal/intent/order/position detail;
- backend mock quote embedded in instrument responses.

Authorized mutations:

- create/evaluate/execute trade intent;
- create an intent from an eligible signal;
- cancel an accepted pending order;
- close/partially close a position;
- modify SL or TP.

The released `/simulation/orders` endpoint remains a deprecated Phase 2 compatibility write. Full route/permission mapping is in `AUTHORIZATION.md`.

## Persistence

The repository declares 38 application/framework tables plus Laravel's migration ledger. The Phase 3 migration adds instruments, terminals, sessions, heartbeats, intents, risk decisions, execution commands and position events and expands existing account/signal/order/deal/position/snapshot records. Migrations use Schema Builder. SQLite is verified; MySQL/PostgreSQL remain unverified portability targets.

## Health contract

The backend reports Web/API/auth state, actual database connectivity, MOCK market data, simulation risk/execution readiness, seeded heartbeat, offline simulation terminal, disconnected broker, and hard-false demo/live execution. The frontend does not relabel unavailable broker capability as healthy.

## Deployment architecture

The standard production build is code-split static assets. `build:hostinger` can create a single-file frontend, but that artifact does not contain Laravel. A safe deployment requires the Laravel public directory, PHP runtime, writable storage/cache, environment key, same-origin `/api` and `/sanctum`, persistent database, secure cookie/proxy configuration and migration control. Never replace a working static site with a client that points at absent APIs.

## Nonexistent architecture

There is no MT5 adapter, broker session, credential vault, real market stream, external heartbeat writer, queue-driven execution worker, WebSocket trading feed, real AI, DEMO/LIVE execution, or real-funds path. `MT5_INTEGRATION_CONTRACT.md` is documentation only and limits a separately approved initial Phase 4 to read-only integration.
# Architecture

## Phase 3 system

Nexa TradeBot is a React 19/strict TypeScript 6/Vite 8 client with a Laravel 13/Sanctum 4 API. Phase 3 adds a persistent simulation trading domain while preserving session authentication, five-role RBAC and the absolute no-broker boundary.

```text
React SPA
  ├─ AuthProvider / ProtectedRoute / permission-aware controls
  ├─ typed API client (cookies + CSRF)
  ├─ Phase 3 manual, signal, order, position and health pages
  └─ retained MOCK screens where no backend producer exists
                 │ /api + /sanctum
Laravel
  ├─ web session → auth:sanctum → active user → permission
  ├─ validated controllers and ownership checks
  ├─ TradeLifecycleService
  │   ├─ deterministic SimulationRiskEvaluator
  │   ├─ ExecutionGate
  │   ├─ SimulationExecutionAdapter
  │   ├─ FinancialCalculator / AccountStateUpdater
  │   └─ AuditService + domain events
  ├─ MockMarketDataProvider
  └─ Eloquent → confirmed local SQLite
```

The Vite development server proxies `/api` and `/sanctum` to `127.0.0.1:43128`. The browser uses an HTTP-only Laravel session and `X-XSRF-TOKEN`; no API token is stored in browser storage.

## Frontend boundaries

- `src/api/client.ts`: CSRF initialization, same-origin credentials, JSON/validation normalization and `401` handling.
- `src/api/services.ts` and `types.ts`: typed Phase 2/3 REST boundary.
- `src/pages/PhaseThreeTradingPages.tsx`: manual intent→risk→command orchestration, backend quote/spec guidance, persisted signals/orders/positions, detail drawers and position controls.
- `src/pages/PersistentOperationsPages.tsx`: risk controls, account metadata, notifications, exact health and audit pages.
- `src/services/mockServices.ts`: retained, labeled UI fixtures for capabilities with no backend producer.

The manual ticket defaults to seeded EURUSD when available and shows the quote returned by the instrument API, digits, tick size, stop distance and protection geometry. React checks are guidance only; Laravel owns validation and authorization.

## Backend boundaries

Core contracts:

- `MarketDataProvider` → `MockMarketDataProvider`;
- `ExecutionAdapter` → `SimulationExecutionAdapter`;
- `PositionReconciliationService` → simulation ledger reconciliation.

`TradeLifecycleService` owns intent creation, signal conversion, execution, cancellation, protection modification and close workflows. `SimulationRiskEvaluator` creates one deterministic decision. `ExecutionGate` permits only enabled SIMULATION accounts while the simulation switch is on and emergency stop is off. Each logical command is idempotent per user and state transitions are guarded.

MARKET fills use ask for BUY and bid for SELL. Positions are marked/closed on the opposite quote side, so spread is represented in unrealized P/L and snapshots. Pending types stop at `ACCEPTED`; there is no trigger scheduler.

See `TRADE_LIFECYCLE.md`, `EXECUTION_MODEL.md`, `STATE_MACHINES.md` and `MARKET_DATA_CONTRACT.md`.

## Persistence

The Phase 3 migration adds instruments, terminals, sessions, heartbeats, intents, risk decisions, execution commands and position events, and expands existing account/signal/order/deal/position records. Distinct resources retain distinct identifiers (`SIM-SIG`, `SIM-INT`, `SIM-RISK`, `SIM-CMD`, `SIM-ORD`, `SIM-DEAL`, `SIM-POS`, `SIM-EVT`).

SQLite is confirmed locally. Schema Builder is used for MySQL/PostgreSQL portability, but those engines are unverified. See `DATABASE_SCHEMA.md`.

## API surface

Public: login, reset request/reset, system status and simulation-status alias.

Protected Phase 3:

- instruments: list/show, including deterministic backend mock quotes;
- signals: list/show/create intent;
- trade intents: list/create/show/evaluate/execute;
- orders: list/show/cancel accepted pending;
- positions: list/show/close/partial close/modify SL/modify TP;
- service heartbeats: list.

Legacy `/simulation/orders` remains a deprecated Phase 2 compatibility write and does not represent the Phase 3 lifecycle.

## Health truth model

Health separates implemented capability from unavailable integrations: web/auth/domain online; database connected or unavailable; mock market data; simulation adapter/risk gate ready or stopped; terminal offline; broker disconnected; live execution disabled; broker transmission false.

## Deployment and build

`npm run build` produces the normal code-split SPA. `build:hostinger` remains an optional static single-file frontend artifact and does not include Laravel. No deployment is part of Phase 3.

## Nonexistent architecture

There is no Python service, Redis requirement, socket price feed, real AI, MT5 adapter, broker credential store, external terminal session, broker order path, DEMO execution or LIVE execution. `MT5_INTEGRATION_CONTRACT.md` describes a future Phase 4 read-only starting boundary only; it is not implementation.
