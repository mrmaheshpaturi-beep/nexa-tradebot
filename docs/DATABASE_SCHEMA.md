# Database Schema

## Scope and portability

Phase 2 uses Eloquent models and Laravel Schema Builder migrations in `backend/database/migrations`. Local development is confirmed on SQLite (`DB_CONNECTION=sqlite`). The migrations avoid vendor-specific SQL and are intended to remain portable to MySQL and PostgreSQL, but neither MySQL nor PostgreSQL has been validated in this repository. JSON columns, decimals, timestamps, UUID strings, foreign-key behavior, and index behavior must be exercised on each target engine before deployment.

Laravel also creates its own `migrations` bookkeeping table when migrations first run. The 30 application/framework tables declared by this repository are listed below. `timestamps` means nullable `created_at` and `updated_at` columns unless a table says otherwise. All `id` columns are auto-incrementing big-integer primary keys unless another primary key is specified.

## Identity, authorization, and framework tables

- `users`: `id`; `name` string; unique `email` string; indexed `status` string(20), default `ACTIVE`; nullable `email_verified_at` timestamp; hashed `password` string; nullable `last_login_at` timestamp; nullable `remember_token`; timestamps.
- `password_reset_tokens`: primary-key `email` string; `token` string; nullable `created_at` timestamp. Tokens are genuine Laravel broker tokens; delivery is not configured.
- `sessions`: primary-key `id` string; nullable, indexed `user_id` foreign identifier; nullable `ip_address` string(45); nullable `user_agent` text; `payload` long text; indexed `last_activity` integer. The migration does not declare a foreign-key constraint for `user_id`.
- `personal_access_tokens`: `id`; polymorphic `tokenable_type` string plus `tokenable_id` unsigned big integer and their composite index; `name` text; unique `token` string(64); nullable `abilities` text; nullable `last_used_at`; nullable, indexed `expires_at`; timestamps. Sanctum support exists, but the Phase 2 browser flow uses session cookies and does not issue personal access tokens.
- `roles`: `id`; unique `name`; `label`; timestamps.
- `permissions`: `id`; unique `name`; `label`; timestamps.
- `role_user`: `role_id` FK → `roles.id` and `user_id` FK → `users.id`, both cascade on delete; composite primary key (`role_id`, `user_id`).
- `permission_role`: `permission_id` FK → `permissions.id` and `role_id` FK → `roles.id`, both cascade on delete; composite primary key (`permission_id`, `role_id`).
- `cache`: primary-key `key`; `value` medium text; indexed `expiration` big integer.
- `cache_locks`: primary-key `key`; `owner`; indexed `expiration` big integer.
- `jobs`: `id`; indexed `queue`; `payload` long text; unsigned-small-integer `attempts`; nullable unsigned `reserved_at`; unsigned `available_at`; unsigned `created_at`.
- `job_batches`: primary-key `id`; `name`; integer `total_jobs`, `pending_jobs`, and `failed_jobs`; `failed_job_ids` long text; nullable `options` medium text; nullable `cancelled_at`; `created_at`; nullable `finished_at`.
- `failed_jobs`: `id`; unique `uuid`; `connection`; `queue`; `payload` and `exception` long text; `failed_at` timestamp defaulting to current time; composite index (`connection`, `queue`, `failed_at`).

## Preferences, configuration, and operational records

- `user_preferences`: `id`; unique `user_id` FK → `users.id`, cascade on delete; `timezone` default `UTC`; `locale` string(10), default `en`; `theme` string(20), default `system`; `sidebar_collapsed` boolean false; `default_dashboard` default `overview`; nullable JSON `favorite_symbols`; `default_timeframe` string(10), default `H1`; unsigned-small-integer `table_page_size`, default 25; `notifications_enabled` boolean true; timestamps. This is a one-to-one user preference record.
- `application_settings`: `id`; unique `key`; `group` string(30), default `general`; JSON `value`; `is_public` boolean false; nullable `updated_by` FK → `users.id`, null on user deletion; timestamps.
- `notifications`: UUID primary-key `id`; `user_id` FK → `users.id`, cascade on delete; `type`; `category` string(50), default `SYSTEM`; `severity` string(20), default `INFO`; `title`; `message` text; nullable JSON `data`; `is_read` boolean false; nullable `read_at`; timestamps.
- `audit_logs`: `id`; nullable `user_id` FK → `users.id`, null on delete; `action`; `module` string(50); nullable `entity_type` and `entity_id`; `description` text; `result` string(20), default `SUCCESS`; nullable JSON `before` and `after`; nullable `ip_address` string(45); nullable `user_agent` text; `occurred_at` and `created_at` default-current timestamps; composite index (`entity_type`, `entity_id`). There is no `updated_at`; the model rejects updates and deletes.
- `system_events`: `id`; `level` string(20), default `INFO`; `category`; `message`; nullable JSON `context`; `occurred_at` default-current timestamp. It has no Laravel timestamps.

## Trading foundation tables

- `risk_profiles`: `id`; nullable `user_id` FK → `users.id`, null on delete; `name`; `status` string(20), default `INACTIVE`; `is_default` false; decimal `max_risk_per_trade` (8,4), default 1; `max_lot_size` (12,4), default 1; `max_daily_loss` (8,4), default 4; `max_weekly_loss` (8,4), default 8; `max_drawdown` (8,4), default 12; unsigned `max_open_positions`, default 8; `max_open_risk` (8,4), default 6; unsigned `max_trades_per_day`, default 20; unsigned `max_consecutive_losses`, default 4; `min_margin_level` (10,2), default 300; `max_spread` (10,2), default 3; `max_slippage` (10,2), default 1.5; `min_reward_risk` (8,4), default 2; nullable `created_by` and `updated_by` FKs → `users.id`, null on delete; timestamps.
- `broker_accounts`: `id`; `user_id` FK → `users.id`, cascade on delete; nullable `risk_profile_id` FK → `risk_profiles.id`, null on delete; `name`; nullable `broker`, `server`, and `account_reference`; `platform` default `NONE`; `environment` string(20), default `SIMULATION`; `currency` string(3), default `USD`; unsigned `leverage`, default 1; `status` string(20), default `DISCONNECTED`; `is_enabled` false; nullable `last_connected_at`; nullable `created_by` FK → `users.id`, null on delete; nullable JSON `metadata`; timestamps; unique (`user_id`, `account_reference`). These are credential-free metadata records, not connections.
- `trading_strategies`: `id`; `user_id` FK → `users.id`, cascade on delete; nullable `risk_profile_id` FK → `risk_profiles.id`, null on delete; `name`; unique `slug`; `category` string(50); nullable `description` text; `status` string(20), default `DRAFT`; `mode` string(20), default `MANUAL`; unsigned `version`, default 1; decimal `minimum_signal_score` (6,3), default 0.70; JSON `symbols` and `timeframes`; nullable JSON `sessions` and `parameters`; `enabled` false; `auto_trading_enabled` false; nullable `created_by` FK → `users.id`, null on delete; timestamps.
- `strategy_settings`: `id`; `trading_strategy_id` FK → `trading_strategies.id`, cascade on delete; `key`; nullable JSON `value`; timestamps; unique (`trading_strategy_id`, `key`).
- `strategy_versions`: `id`; `trading_strategy_id` FK → `trading_strategies.id`, cascade on delete; unsigned `version`; nullable `created_by` FK → `users.id`, null on delete; JSON `configuration`; nullable `change_summary` text; timestamps; unique (`trading_strategy_id`, `version`).
- `account_snapshots`: `id`; `broker_account_id` FK → `broker_accounts.id`, cascade on delete; decimal `balance`, `equity`, `margin`, `free_margin`, and `floating_pnl` (18,4), all default 0; nullable `margin_level` (12,4); `drawdown` (8,4), default 0; `captured_at`; composite index (`broker_account_id`, `captured_at`). No Laravel timestamps.
- `signals`: `id`; nullable `trading_strategy_id` FK → `trading_strategies.id`, null on delete; `symbol` string(20); `direction` string(10); nullable `timeframe` string(10); nullable decimals `score` (6,3), `entry_price`, `stop_loss`, `take_profit_1`, and `take_profit_2` (prices are 18,8); nullable `risk_reward` (8,4); nullable `market_regime` string(30); `status` string(20), default `NEW`; `source` string(50), default `SIMULATION`; nullable `explanation` text; `environment` string(20), default `SIMULATION`; `generated_at`; nullable `expires_at`; nullable JSON `metadata`; timestamps.
- `orders`: `id`; unique UUID `public_id`; unique UUID `command_id`; `user_id` FK → `users.id`, restrict on delete; nullable `broker_account_id` FK → `broker_accounts.id`, null on delete; nullable `signal_id` FK → `signals.id`, null on delete; `idempotency_key` string(100); `symbol` string(20); `direction` string(10); `type` string(20), default `MARKET`; `volume` decimal(12,4); nullable `requested_price`, `stop_loss`, and `take_profit` decimals(18,8); nullable `risk_amount` decimal(18,4) and `risk_percent` decimal(8,4); nullable `comment` string(255); `status` string(20), default `SIMULATED`; `environment` string(20), default `SIMULATION`; `simulated` true; `broker_transmitted` false; timestamps; unique (`user_id`, `idempotency_key`).
- `deals`: `id`; `order_id` FK → `orders.id`, cascade on delete; nullable `broker_account_id` FK → `broker_accounts.id`, null on delete; nullable `position_id` FK → `positions.id`, null on delete; `symbol` string(20); `direction` string(10); `volume` decimal(12,4); `price` decimal(18,8); `commission` and `profit` decimals(18,4), default 0; `environment` string(20), default `SIMULATION`; `dealt_at`. No Laravel timestamps.
- `positions`: `id`; nullable `broker_account_id` FK → `broker_accounts.id`, null on delete; nullable, unique `opening_order_id` FK → `orders.id`, null on delete; `symbol` string(20); `direction` string(10); `volume` decimal(12,4); `open_price` decimal(18,8); nullable `current_price` decimal(18,8); `status` string(20), default `OPEN`; `environment` string(20), default `SIMULATION`; timestamps.
- `trades`: `id`; nullable `position_id` FK → `positions.id`, null on delete; `user_id` FK → `users.id`, restrict on delete; `symbol` string(20); `entry_price` decimal(18,8); nullable `exit_price` decimal(18,8); `profit` decimal(18,4), default 0; `environment` string(20), default `SIMULATION`; `opened_at`; nullable `closed_at`; timestamps.
- `risk_events`: `id`; nullable `risk_profile_id` FK → `risk_profiles.id`, null on delete; nullable `order_id` FK → `orders.id`, null on delete; `rule`; `severity` string(20); `decision` string(20); `message` text; nullable JSON `context`; `occurred_at` default-current timestamp. No Laravel timestamps.

## Relationship map

- User ↔ Role ↔ Permission are many-to-many through `role_user` and `permission_role`.
- User has one UserPreference; has many BrokerAccounts, TradingStrategies, RiskProfiles, Orders, Notifications, AuditLogs, and Trades.
- RiskProfile belongs to an optional owner and optional creator/updater; has many BrokerAccounts and RiskEvents.
- BrokerAccount belongs to User, optional RiskProfile, and optional creator; has many AccountSnapshots, Orders, and Positions.
- TradingStrategy belongs to User, optional RiskProfile, and optional creator; has many StrategySettings, StrategyVersions, and Signals.
- StrategySetting belongs to TradingStrategy. StrategyVersion belongs to TradingStrategy and optional creator.
- ApplicationSetting belongs to an optional updater. Notification and AuditLog belong to an optional or required user as specified above.
- AccountSnapshot belongs to BrokerAccount. Signal belongs to an optional TradingStrategy and has many Orders.
- Order belongs to User, optional BrokerAccount, and optional Signal; has many Deals and RiskEvents and at most one Position through `opening_order_id`.
- Deal belongs to Order and optionally BrokerAccount and Position. Position optionally belongs to BrokerAccount and opening Order; has many Deals and Trades. Trade belongs to User and optional Position. RiskEvent optionally belongs to RiskProfile and Order.

Some database foreign keys are intentionally broader than API ownership rules. Controllers and services additionally enforce that strategy, risk-profile, broker-account, signal, notification, and simulation-order resources belong to the current user.

## Environment columns and invariants

Exactly six tables have an `environment` column: `broker_accounts`, `signals`, `orders`, `deals`, `positions`, and `trades`. Each defaults to `SIMULATION`. The implemented PHP `TradingEnvironment` enum contains only `SIMULATION`; broker-account controllers overwrite client input with `SIMULATION`, the broker request accepts no other value, and simulation-order creation writes `SIMULATION`. No `PAPER`, `DEMO`, or `LIVE` persistence value is implemented in Phase 2.

## Local migration and seed procedure

From `backend/`:

```bash
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
DEV_SUPER_ADMIN_PASSWORD='a-local-password-of-12-or-more-characters' php artisan db:seed
php artisan migrate:status
```

`DatabaseSeeder` deliberately fails unless `DEV_SUPER_ADMIN_PASSWORD` is present and at least 12 characters. It creates/updates `admin@nexa.local`, assigns `SUPER_ADMIN`, and creates disconnected simulation-only seed records with the emergency stop active. Do not put the seed password in source control.

> **Destructive command warning:** `php artisan migrate:fresh` drops every table and all data before rebuilding the schema. Use `php artisan migrate:fresh --seed` only against a disposable local/test database after verifying the active connection. Never run it against a shared or production database. Normal upgrades use `php artisan migrate`; inspect and back up the target first. No deployment or production migration was performed in Phase 2.

## Environment configuration reference

`.env.example` contains:

- Application: `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE`.
- Development seed: `DEV_SUPER_ADMIN_PASSWORD` (required only when running the development seeder; minimum 12 characters).
- Maintenance and hashing: `APP_MAINTENANCE_DRIVER`, optional `APP_MAINTENANCE_STORE`, optional `PHP_CLI_SERVER_WORKERS`, `BCRYPT_ROUNDS`.
- Logging: `LOG_CHANNEL`, `LOG_STACK`, `LOG_DEPRECATIONS_CHANNEL`, `LOG_LEVEL`.
- Database: `DB_CONNECTION`; commented portability examples `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
- Session: `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN`. Additional Laravel-supported session variables exist in `config/session.php` (`SESSION_EXPIRE_ON_CLOSE`, `SESSION_CONNECTION`, `SESSION_TABLE`, `SESSION_STORE`, `SESSION_COOKIE`, `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE`, `SESSION_PARTITIONED_COOKIE`) but are not listed in `.env.example`.
- Infrastructure: `BROADCAST_CONNECTION`, `FILESYSTEM_DISK`, `QUEUE_CONNECTION`, `CACHE_STORE`, optional `CACHE_PREFIX`, `MEMCACHED_HOST`, `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`.
- Mail/reset-delivery infrastructure: `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`. The current defaults do not implement reset-token delivery.
- Object storage: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_USE_PATH_STYLE_ENDPOINT`.
- Frontend build label: `VITE_APP_NAME`. No secret may be exposed through any `VITE_` variable.

Laravel authentication also reads optional `AUTH_GUARD`, `AUTH_PASSWORD_BROKER`, `AUTH_MODEL`, `AUTH_PASSWORD_RESET_TOKEN_TABLE`, and `AUTH_PASSWORD_TIMEOUT` in `config/auth.php`; these are not present in `.env.example`.
