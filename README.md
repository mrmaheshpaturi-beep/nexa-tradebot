# Nexa TradeBot

Nexa TradeBot Phase 3 is an authenticated, persistent, simulation-only trading operations terminal built with React 19/TypeScript/Vite and Laravel 13/Sanctum.

Implemented: explicit trade intent → deterministic risk decision → simulation command → order/deal/position lifecycle, typed pending orders and cancellation, SL/TP changes, partial/full close, signal-to-intent, account snapshots, five-role RBAC, audit and exact health reporting.

Not implemented: MT5, broker connectivity/credentials, real market data, real AI, PAPER/DEMO/LIVE execution or real-money trading. Backend quotes and fills are deterministic MOCK data.

## Local setup

Requirements: Node.js 22+, PHP 8.3+, Composer 2.

```bash
npm install
cd backend
composer install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
DEV_SUPER_ADMIN_PASSWORD='choose-at-least-12-characters' php artisan db:seed
php artisan serve --host=0.0.0.0 --port=43128
```

In another terminal:

```bash
npm run dev -- --host=0.0.0.0 --port=43127
```

Sign in with `admin@nexa.local` and the password supplied only to the development seed process. The Vite server proxies `/api` and `/sanctum` to Laravel.

Seed defaults are fail-safe: emergency stop on and simulation execution off. An authorized operator must explicitly disable the emergency stop and enable `simulation_execution_enabled`. `trading_enabled`, broker transmission and demo/live execution remain false.

## Protection reference

The manual ticket displays the backend MOCK bid/ask and instrument precision. MARKET BUY enters at ask and SELL at bid. BUY requires SL below and TP above entry; SELL requires TP below and SL above entry. Open-position changes use the close-side quote (bid for BUY, ask for SELL).

## Validation

```bash
npm run typecheck
npm run lint
npm test
npm run build
npm audit

cd backend
composer test
./vendor/bin/pint --test
composer audit --locked
```

Use `php artisan migrate:fresh --seed --force` only after confirming a disposable local SQLite database; it destroys existing data.

## Documentation

- `docs/ARCHITECTURE.md`
- `docs/TRADING_DOMAIN.md`
- `docs/TRADE_LIFECYCLE.md`
- `docs/EXECUTION_MODEL.md`
- `docs/STATE_MACHINES.md`
- `docs/MARKET_DATA_CONTRACT.md`
- `docs/DATABASE_SCHEMA.md`
- `docs/AUTHORIZATION.md`
- `docs/SECURITY.md`
- `docs/MT5_INTEGRATION_CONTRACT.md` (future read-only contract; not implemented)
- `docs/PHASE_3_REPORT.md`

`npm run build:hostinger` creates only a static frontend artifact. It must not be deployed for Phase 3 unless the Laravel API, database, session/CSRF configuration and same-origin routing are deployed and verified with it.
