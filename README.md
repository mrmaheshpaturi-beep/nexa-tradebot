# Nexa TradeBot

Phase 2 is an authenticated, database-backed foundation for a simulation-only trading operations terminal. It combines a React 19/TypeScript/Vite client with a Laravel 13 API, session authentication, five-role RBAC, portable migrations, persistent configuration and simulation records, audit logging, and 22 protected operational screens.

Market prices, candles, scanning, and AI analysis remain explicit mocks. There is no MT5 adapter, broker credential storage, broker connection, demo/live execution, real market feed, or real-money capability.

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

Sign in with `admin@nexa.local` and the development password supplied only when seeding. Change that credential before sharing any environment.

The frontend dev server proxies `/api` and `/sanctum` to Laravel on port `43128`. SQLite is the confirmed local database; migrations use Laravel Schema Builder for future MySQL/PostgreSQL portability.

## Validation

```bash
npm run typecheck
npm run lint
npm test
npm run build
cd backend
composer test
./vendor/bin/pint --test
composer audit --locked
```

See `docs/PROJECT_RULES.md`, `docs/ARCHITECTURE.md`, `docs/DATABASE_SCHEMA.md`, `docs/AUTHORIZATION.md`, and `docs/PHASE_2_REPORT.md`.
