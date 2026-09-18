# Nexa TradeBot

Nexa TradeBot Phase 5 is an authenticated trading operations terminal with a **Market Data Engine** on top of the Phase 4 read-only MT5 DEMO bridge. Stack: React 19/TypeScript/Vite, Laravel 13/Sanctum, Python FastAPI bridge.

Implemented through Phase 5:

- Phase 3 simulation trade lifecycle (intent → risk → simulation execution)
- Phase 4 read-only MT5 bridge (Mock + Windows Real connectors), Laravel sync/reconcile, MT5 UI
- Phase 5 market data engine: quotes/candles/symbols normalization, freshness + validation + quality, market snapshot API, Market Watch + Charts

Not implemented: broker order execution, DEMO/LIVE trading, Phase 6 indicators, Phase 7 strategies, Hostinger production deploy.

## Local setup

Requirements: Node.js 22+, PHP 8.3+, Composer 2, Python 3.12+.

### 1. Python market/bridge service (mock mode)

```bash
cd trading-engine
python3 -m pip install -e ".[dev]"
cp .env.example .env
# set NEXA_MT5_SERVICE_TOKEN=local-dev-token
# NEXA_MT5_MODE=mock
python3 -m uvicorn nexa_mt5.api:app --host 127.0.0.1 --port 8765
```

### 2. Laravel API

```bash
cd backend
composer install
cp .env.example .env
# TRADING_BRIDGE_URL=http://127.0.0.1:8765
# TRADING_BRIDGE_SERVICE_TOKEN=local-dev-token
touch database/database.sqlite
php artisan key:generate
php artisan migrate
DEV_SUPER_ADMIN_PASSWORD='choose-at-least-12-characters' php artisan db:seed
php artisan serve --host=0.0.0.0 --port=45281
```

### 3. React UI

```bash
npm install
npm run dev -- --host=0.0.0.0 --port=45280
```

Sign in with `admin@nexa.local` and the seeded password. Vite proxies `/api` and `/sanctum` to Laravel.

Open [Nexa TradeBot](http://127.0.0.1:45280) → **Market Watch** / **Live Charts**.

Without `TRADING_BRIDGE_SERVICE_TOKEN`, the engine serves simulation-enriched mock snapshots (`prefer=simulation`).

## Validation

```bash
cd trading-engine && python3 -m pytest && python3 -m ruff check src tests && python3 -m mypy src

cd backend && php artisan test && ./vendor/bin/pint --test

npm run typecheck && npm run lint && npm test && npm run build
```

## Documentation

- `docs/PHASE_5_REPORT.md`
- `docs/PHASE_5_ARCHITECTURE.md`
- `docs/MARKET_DATA_CONTRACT.md`
- `docs/PHASE_4_REPORT.md` / `docs/MT5_BRIDGE_API.md` / `docs/MT5_WINDOWS_SETUP.md`
- `docs/ARCHITECTURE.md`

## Warnings

- Real MT5 validation still needs a Windows host with MetaTrader 5 DEMO (`MT5_WINDOWS_SETUP.md`).
- Seed defaults keep emergency stop on and simulation execution off until an operator changes them.
- No Hostinger deploy is configured in this phase.
