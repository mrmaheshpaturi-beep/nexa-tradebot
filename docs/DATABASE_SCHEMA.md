# Database Schema

## Scope and verification

Repository migrations declare 38 application/framework tables, plus Laravel's generated `migrations` ledger. Phase 3 is verified on local SQLite only. Migrations use Laravel Schema Builder and avoid vendor-specific SQL; MySQL/PostgreSQL remain untested portability targets.

## Existing identity and framework tables

- `users`, `password_reset_tokens`, `sessions`, `personal_access_tokens`
- `roles`, `permissions`, `role_user`, `permission_role`
- `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`

## Existing application foundation

- `user_preferences`, `application_settings`, `notifications`, `audit_logs`, `system_events`
- `risk_profiles`, `broker_accounts`
- `trading_strategies`, `strategy_settings`, `strategy_versions`
- `account_snapshots`
- `signals`, `orders`, `deals`, `positions`, `trades`, `risk_events`

Phase 2 field-level definitions remain represented by the migrations. Phase 3 expands the trading records as described below.

## New Phase 3 tables

### `trading_instruments`

Unique public ID and symbol; name/display name; asset class; base/quote currencies; digits; point size; contract size; tick size/value; duplicated compatibility and canonical volume min/max/step fields; minimum stop distance; margin rate; enabled flag; timestamps. Indexed by enabled state and symbol.

### `trading_terminals`

Unique public ID; nullable account FK; name, platform, machine identifier, environment, status, adapter, version; last-seen/heartbeat/connection timestamps; JSON metadata; timestamps. Indexed by environment/status. Seeded terminal is offline simulation metadata.

### `trading_sessions`

Unique public ID; nullable terminal FK; required account FK; environment/status; start/end timestamps; timestamps. Indexed by account/environment/status. Phase 3 has no session mutation API.

### `service_heartbeats`

Unique public ID; nullable terminal FK; service, instance ID, status, environment, observed/last-seen timestamps; JSON details/metadata; timestamps. Indexed by service/environment/observed time.

### `trade_intents`

Unique public ID; required user/account/instrument FKs; nullable strategy FK and unique nullable signal FK; user-scoped idempotency key; origin, side, order type; volume/requested volume and entry/SL/TP/TP2 decimals; time in force, comment, risk percent, creator; status, environment, metadata; timestamps.

Unique `(user_id, idempotency_key)`; indexed by account/environment/status/created time.

### `risk_decisions`

Unique public ID; unique intent FK; nullable risk-profile FK; status/decision/reason code/message/reason; risk amounts; requested/approved volume; reward/risk; JSON checks; evaluated time; timestamps. Indexed by status/evaluated time.

### `execution_commands`

Unique public ID; required user/account FKs; nullable intent/position FKs; user-scoped idempotency key; type/status/environment; symbol/side/order type/volume/prices/expiration; attempt/failure/error fields; JSON payload; request/acknowledgement/completion/failure timestamps; timestamps.

Unique `(user_id, idempotency_key)`; indexed by account/environment/status/created time.

### `position_events`

Unique public ID; required position FK; nullable command FK; event type, origin and source; JSON previous/new state; before/after volumes; price/realized P/L; JSON changes; occurrence time; timestamps. Indexed by position/occurrence time.

## Expanded Phase 3 tables

- `broker_accounts`: unique nullable-backfilled public ID and user/environment/status index.
- `signals`: unique public ID; nullable user/account/instrument FKs; entry/TP references; consumed time; user/environment/status/generated index.
- `orders`: prefixed public ID, correlation ID, command/instrument/strategy/intent FKs, external ID, canonical side/type/volume/fill fields, JSON metadata and full lifecycle timestamps; command unique; lifecycle indexes.
- `deals`: public/external IDs, command FK, side/type/origin, swap/fee, execution timestamp, metadata and Laravel timestamps; position and account time indexes.
- `positions`: public ID, user/instrument/strategy/signal FKs, external ID, side, initial/current volume, average entry, SL/TP, realized/floating/unrealized P/L, margin, metadata and open/close times; account/status and user/status indexes.
- `account_snapshots`: adds `open_positions`.

## Relationship map

```text
User → BrokerAccount → AccountSnapshot
                   ├→ TradingSession
                   ├→ TradeIntent → RiskDecision
                   │             └→ ExecutionCommand → Order → Deal
                   │                                  └→ Position → PositionEvent
                   └→ Position
TradingStrategy → Signal → TradeIntent
TradingInstrument → Signal / TradeIntent / Order / Position
TradingTerminal → TradingSession / ServiceHeartbeat
```

Delete rules use cascade/null/restrict deliberately. API ownership is stricter than many nullable database FKs.

## Identifiers and decimals

Lifecycle resources expose prefixed `SIM-*` string IDs while retaining integer relational keys. Instrument/account public IDs are UUIDs. Prices use decimal(18,8), volume decimal(12,4), and P/L/account money decimal(18,4). API casts return many decimals as strings to avoid transport loss.

## Environment columns

Eleven tables have environment columns: broker accounts, signals, orders, deals, positions, trades, trading terminals, trading sessions, service heartbeats, trade intents and execution commands. Defaults are SIMULATION. Server-created Phase 3 records are SIMULATION; execution rejects PAPER/DEMO/LIVE.

## Local migration and seed

Confirm `DB_CONNECTION=sqlite` and that `DB_DATABASE` resolves to the disposable local `backend/database/database.sqlite` before running:

```bash
cd backend
php artisan migrate:status
DEV_SUPER_ADMIN_PASSWORD='<temporary-development-secret>' php artisan migrate:fresh --seed --force
php artisan migrate:status
```

`migrate:fresh` drops all tables and data. Never use it against a shared, hosted or production database. The seeder requires a 12+ character password supplied only in the process environment and creates an admin, roles/permissions, active simulation risk profile, enabled credential-free simulation account, initial snapshot, instruments, offline terminal/heartbeat and deterministic mock signal.

## Portability limitations

SQLite migration/rollback/test behavior is verified. Decimal behavior, JSON handling, index limits, FK alterations, `change()` operations and concurrent idempotency must be tested independently on each future MySQL/PostgreSQL target before deployment.
