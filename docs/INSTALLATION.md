# Installation — Nexa TradeBot RC1

**Release:** NEXA-TRADEBOT-RC1 · `v1.0.0-rc.1` · 2026-09-19T16:02:05Z

## Prerequisites

- Node.js 20+ / npm
- PHP 8.3+ with extensions used by Laravel
- Composer
- Python 3.12+ (trading-engine)
- SQLite (local) or MySQL (Hostinger/VPS — PENDING operator config)

## Local install

```bash
git checkout cursor/phase-20-demo-release-candidate-56f9
npm ci
cd backend && composer install && cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --force && php artisan db:seed --force
cd ../trading-engine && python3 -m venv .venv && .venv/bin/pip install -r requirements.txt httpx pytest
```

## Preview (Phase 20 ports)

```bash
# Laravel
cd backend && php artisan serve --host=127.0.0.1 --port=48420
# Vite
npm run dev   # http://127.0.0.1:58420
```

Admin (local seed): `admin@nexa.local` / `NexaLocalDevPass1!`

## Windows MT5 bridge

See `MT5_WINDOWS_SETUP.md`. Real terminal validation remains **PENDING** until operator environment is available.

## Non-goals

- Do not enable LIVE trading
- Do not create LIVE_AUTO
- Do not expose the Python bridge publicly without auth
