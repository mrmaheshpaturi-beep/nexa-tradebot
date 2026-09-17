# Nexa TradeBot backend

Laravel 13 persistence and session API for the Nexa TradeBot simulation environment. This backend has no MT5 adapter, broker connection, real execution path, or execution endpoint.

## Safety contract

- Environment is always `SIMULATION`.
- `trading_enabled=false`, `auto_trading_enabled=false`, and `emergency_stop=true` by default.
- `allow_demo_execution=false` and `allow_live_execution=false` are hard-disabled.
- Broker accounts contain metadata only; credentials are neither accepted nor stored.
- Orders are local simulation records and always return `simulated=true` and `broker_transmitted=false`.

## Local setup

Use only local SQLite for development:

```bash
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
```

Development seeding requires an explicit secret and fails if it is absent or shorter than 12 characters:

```bash
DEV_SUPER_ADMIN_PASSWORD='choose-a-local-password' php artisan db:seed
```

The seeded account is `admin@nexa.local`. The password is never embedded in source. Seed data is disconnected, simulation-only, and starts with emergency stop active.

## Session authentication

Sanctum uses the Laravel session guard. Browser clients must first request `/sanctum/csrf-cookie`, send credentials with cookies, and include the decoded `XSRF-TOKEN` as `X-XSRF-TOKEN` on writes. Login is limited to five attempts per minute per email/IP.

Password-reset requests create a genuine reset token but do not pretend an email was sent. The response explicitly says token delivery is not configured. Connect an approved notification provider before exposing reset delivery outside development.

## API (`/api/v1`)

Public:

- `POST /auth/login`
- `POST /auth/password/request`
- `POST /auth/password/reset`
- `GET /system/status` and the compatibility alias `GET /simulation/status`

Authenticated:

- `GET /auth/me`, `POST /auth/logout`
- `GET /dashboard`
- `GET|POST /users`, `PUT /users/{user}`
- `POST /users/{user}/activate|suspend|disable`
- `GET|POST /strategies`, `PUT /strategies/{strategy}`
- `GET|POST /risk-profiles`, `PUT /risk-profiles/{riskProfile}`
- `GET|POST /broker-accounts`, `PUT /broker-accounts/{brokerAccount}`
- `GET /settings`, `PUT /settings/{key}`, `PUT /emergency-stop`
- `GET|PUT /preferences`
- `GET /notifications`, `POST /notifications/{notification}/read`
- `GET /audit-logs`
- `POST /simulation/orders`

Protected routes enforce `ACTIVE` user status and granular permissions assigned through `SUPER_ADMIN`, `ADMIN`, `TRADER`, `ANALYST`, and `VIEWER`. Simulation order writes require a globally unique `command_id` plus a user-scoped `idempotency_key`, run in a transaction with their immutable audit entry, and are rejected while emergency stop is active or trading is disabled.

## Quality checks

```bash
php artisan migrate:fresh --force
php artisan test
vendor/bin/pint --test
```

Migrations use Laravel's portable schema builder and avoid vendor-specific SQL so they remain suitable for later MySQL/PostgreSQL validation.
