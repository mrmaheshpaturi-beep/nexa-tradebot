# Database Schema

## Scope and verification

Repository migrations declare 47 application/framework tables, plus Laravel's generated `migrations` ledger. Phases 3–4 are verified on local SQLite only. Migrations use Laravel Schema Builder and avoid vendor-specific SQL; MySQL/PostgreSQL remain untested portability targets.

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

## New Phase 4 tables

### `mt5_bridge_connections`

User-owned bridge metadata: public UUID, mode, DEMO environment, status, enablement, last tested/connected/stale timestamps, JSON metadata.

### `mt5_account_mappings`

Maps a bridge connection to a `broker_accounts` row and external account ID with sync timestamps.

### `instrument_aliases`

External symbol to optional `trading_instruments` link, normalized symbol, observed spec JSON/hash.

### `mt5_external_positions`, `mt5_external_orders`, `mt5_external_deals`

Account-mapping-scoped external observations with unique external IDs and JSON payloads.

### `mt5_sync_cursors`

Per-mapping resource cursors (`POSITIONS`, `ORDERS`, `DEALS`).

### `mt5_reconciliation_runs`, `mt5_reconciliation_items`

Report-only reconciliation sessions and per-resource comparison rows.

### `account_snapshots` expansion

Adds `source`, `environment`, and `external_snapshot_id` for idempotent MT5 snapshot ingestion.

## New Phase 9 tables / columns

Additive migration `2026_09_19_070000_create_phase_nine_risk_engine.php` (local/dev verified). Never run `migrate:fresh` against shared/hosted DBs.

### `risk_profiles` expansions

`version`, `rules_bundle_version`, `config_hash`, `rule_config`, `session_allowlist`, `max_correlated_exposure`, `atr_stop_multiplier`, `require_stop_loss`, `sizing_enabled`.

### `risk_rule_definitions`

Catalog of modular rule codes/versions/priorities.

### `proposed_plans`

One sizing proposal per risk decision; `broker_routable` defaults false; sizing facts immutable.

### `risk_reservations`

User-scoped idempotent margin/risk/exposure reservations with ACTIVE/RELEASED/EXPIRED/CONSUMED.

### `risk_locks`

Active locks that block new intents; release audited; anti-spam reuse of equivalent active locks.

### `risk_decisions` expansions

`engine_version`, `profile_version`, `rules_bundle_version`, `config_hash`, `proposed_plan_id`, `immutable`, `rule_results`, `account_context`, `symbol_context`.

## Portability limitations

SQLite migration/rollback/test behavior is verified. Decimal behavior, JSON handling, index limits, FK alterations, `change()` operations and concurrent idempotency must be tested independently on each future MySQL/PostgreSQL target before deployment.


## Phase 10 execution tables (additive)

- `execution_confirmations` — two-step DEMO confirmation challenges/tokens
- `execution_submission_locks` — per-intent submit locks
- `execution_results` — immutable submit outcomes
- `execution_events` — immutable timeline
- `execution_reconciliation_runs` — DEMO sync runs
- `execution_commands` columns: `submission_state`, bridge correlation/nonce, `order_check_passed`, `unknown_reason`, `blind_retry_forbidden`
- `broker_accounts` columns: `broker_login`, `broker_server`, `verified_trade_mode`, `demo_verified_at`, `demo_verification`


## Phase 11 tables

`trade_management_policies`, `managed_positions`, `managed_position_targets`, `trade_management_decisions`, `position_management_actions`, `position_management_locks`, `broker_action_locks`, `trade_management_events`, `managed_position_snapshots`, `trade_summaries`, `management_confirmations`. Additive columns on `risk_locks`: `blocks_new_entries`, `blocks_protective_closes`.

## Phase 12 tables (additive)

- `analytics_datasets`, `analytics_dataset_rows`, `analytics_snapshots`, `analytics_reports`
- `backtest_data_snapshots`, `backtest_runs`, `backtest_jobs`
- `backtest_walk_forward_folds`, `backtest_optimization_trials`, `backtest_monte_carlo_paths`
- `research_comparisons` (strict BACKTEST vs DEMO labels)

## Phase 13 tables (additive)

- `intelligence_assessments`, `intelligence_opportunities`
- `intelligence_ai_analyses`, `intelligence_chat_messages`
- `intelligence_calendar_events`, `intelligence_news_items`
- `intelligence_jobs`, `intelligence_usage_meters`
- `intelligence_calibration_samples`, `intelligence_settings`

Advisory/shadow only — no broker write tables.


## Phase 14 — Automation tables

Additive migration `2026_09_19_240000_create_phase_fourteen_demo_automation.php`:

- `automation_profiles`, `automation_sessions`, `automation_workflows`
- `automation_events` (immutable), `automation_locks`, `automation_daily_counters`
- `automation_queue_jobs`, `automation_notifications` (IN_APP foundation)


## Phase 15 tables

metric_samples, system_health_snapshots, system_alerts, system_error_records, validation_sessions, validation_observations, performance_drift_checks, data_quality_scores, circuit_breakers, backup_runs, dead_letter_jobs, watchdog_events, ops_incidents.

## Phase 16 — Strategy Governance tables

Additive migration `2026_09_19_260000_create_phase_sixteen_strategy_governance.php` (never destroys trade history):

- `governed_strategy_versions` — immutable semantic versions + code/config hashes + lifecycle
- `strategy_release_candidates`, `strategy_evidence_packages`
- `strategy_validation_policies`, `strategy_validation_decisions`
- `governance_approvals`, `governance_approval_tokens` (bound, single-use, nonce/replay)
- `strategy_deployments` — DEMO_AUTO only; positions/history preserved flags
- `strategy_comparisons`, `strategy_experiments` (lab; never deploys)
- `strategy_portfolios`, `strategy_change_requests`
- `governance_events` (immutable), `governance_idempotency_keys`

## Phase 17 — Advanced Intelligence tables

Additive migration `2026_09_19_270000_create_phase_seventeen_advanced_intelligence.php`:

- `intelligence_advanced_snapshots` — orchestrator payloads (features, MTF matrix, analogs, suitability, scoring separation)
- `intelligence_memory_records` — immutable append-only pre/post-trade + research memory
- `intelligence_feature_versions` — versioned feature hash archive
- Additive columns on `intelligence_settings`: `advanced_enabled`, `orchestrator_version`, `ai_timeout_ms`, `cost_budget_tokens`

Advisory/shadow only — no broker write tables. Extends Phase 13; does not replace it.

## Phase 18 — Broker Fleet tables

Additive migration `2026_09_19_280000_create_phase_eighteen_broker_fleet.php`:

- `broker_providers`, `broker_connections` (secret refs only; no raw credentials)
- `fleet_accounts`, `account_fingerprints`, `broker_capabilities`
- `fleet_terminals` (isolation keys; supervisor state)
- `canonical_instruments`, `broker_instruments`, `instrument_mappings`
- `trading_portfolios`, `portfolio_memberships`, `allocation_plans`, `strategy_assignments`
- `fleet_risk_locks` (ACCOUNT|PORTFOLIO|GLOBAL)
- `execution_routes` (account-bound idempotency; copy_trading always false)
- `fleet_reconciliation_runs`, `fleet_health_snapshots`, `fleet_emergency_controls`
- `automation_account_scopes` (OFF|DRY_RUN|DEMO_AUTO — never LIVE_AUTO)
- `fx_valuation_rates`, `trading_nodes`, `trading_node_leases`, `fleet_audit_events`
- Additive `broker_accounts.fleet_provider_code`, `broker_accounts.fleet_safe_mode`

Fleet routes into Phase 10 — does not add broker write tables beyond existing DEMO execution path.

## Phase 19 — Production Hardening tables

Additive migration `2026_09_19_290000_create_phase_nineteen_production_hardening.php`:

- `hardening_secret_inventory` (metadata only; never stores secret values)
- `hardening_service_identities`, `hardening_node_identities`
- `hardening_mfa_challenges`, `hardening_replay_nonces`
- `hardening_queue_jobs`, `hardening_dlq_jobs` (idempotent + dead letter)
- `hardening_worker_processes` (graceful shutdown + restart reconcile flags)
- `hardening_deploy_versions` (maintenance / trading pause / reconcile-before-resume)
- `hardening_ops_safe_modes` (scoped SAFE_MODE)
- `hardening_isolated_restores`, `hardening_config_validations`
