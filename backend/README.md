# Nexa TradeBot Laravel API

Laravel 13/Sanctum backend for the Phase 3 simulation-only trading domain. It persists intents, risk decisions, commands, orders, deals, positions, events and account snapshots. There is no MT5/broker adapter, credential storage, external execution, or real market feed.

## Safety

- `trading_enabled=false`, `auto_trading_enabled=false`, `allow_demo_execution=false`, `allow_live_execution=false`.
- `simulation_execution_enabled=false` and `emergency_stop=true` by default.
- Only SIMULATION commands pass the execution gate.
- Broker-account inputs prohibit credential and execution fields.
- Backend market data and fills are deterministic MOCK values.

## Local SQLite setup

```bash
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
DEV_SUPER_ADMIN_PASSWORD='choose-at-least-12-characters' php artisan db:seed
php artisan serve --host=0.0.0.0 --port=43128
```

The seed password is required only in the process environment and is never embedded in source. `migrate:fresh` is destructive; use it only after confirming local disposable SQLite.

## Authentication

The browser flow uses Laravel sessions through Sanctum stateful middleware. Obtain `/sanctum/csrf-cookie`, include cookies, and send decoded `XSRF-TOKEN` as `X-XSRF-TOKEN` on writes. Protected routes require an active user and named permission.

## Phase 3 API

Public:

- `POST /api/v1/auth/login`, `/auth/password/request`, `/auth/password/reset`
- `GET /api/v1/system/status`, `/simulation/status`

Lifecycle reads:

- `GET /api/v1/instruments[/{instrument}]`
- `GET /api/v1/signals[/{signal}]`
- `GET /api/v1/trade-intents[/{intent}]`
- `GET /api/v1/orders[/{order}]`
- `GET /api/v1/positions[/{position}]`
- `GET /api/v1/heartbeats`

Lifecycle writes:

- `POST /api/v1/trade-intents`
- `POST /api/v1/trade-intents/{intent}/evaluate`
- `POST /api/v1/trade-intents/{intent}/execute`
- `POST /api/v1/signals/{signal}/trade-intent`
- `POST /api/v1/orders/{order}/cancel`
- `POST /api/v1/positions/{position}/close|partial-close`
- `PUT /api/v1/positions/{position}/stop-loss|take-profit|protection`

Phase 2 administration/configuration endpoints remain available. `/api/v1/simulation/orders` is a deprecated compatibility write.

## Quality gates

```bash
composer test
./vendor/bin/pint --test
composer audit --locked
```

See the root documentation for architecture, lifecycle, state machines, market data, schema, authorization and security contracts.
