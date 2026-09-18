# Phase 5 Report — Market Data Engine

## Summary

Phase 5 ships a complete Market Data Engine on top of the Phase 4 read-only MT5 bridge: ingestion, normalization, freshness/validation/quality scoring, market snapshot APIs, persistence, and React Market Watch + Charts. Execution remains disabled. Phase 6/7 are extension stubs only.

## Branch

`cursor/phase-5-market-data-56f9` (from Phase 4 `cursor/phase-4-mt5-readonly-56f9`)

## Delivered components

### Python bridge (`trading-engine/`)

- `nexa_mt5/market_data.py` — MarketDataEngine
- Expanded mock symbols: EURUSD, GBPUSD, USDJPY, XAUUSD, NAS100, BTCUSD
- Endpoints under `/v1/market/*` including aggregated `/v1/market/snapshot`
- Adapter version `0.2.0`

### Laravel

- `MarketDataEngineService` — bridge-first with simulation fallback
- `MarketDataController` — snapshot/quotes/candles/symbols/hooks
- Persistence tables for symbols, quotes, candles, snapshots
- System status reports `market_data_engine.status = READY`

### React

- `PhaseFiveMarketPages` — Market Watch + Live Charts on snapshot API
- Quality/freshness badges; unusable candles excluded from chart
- Phase 6/7 extension hook panel (PENDING)

## Safety audit

- No `order_send` / broker write paths added
- Snapshot payloads always `read_only: true`
- Extension hooks declare `execution.order_send = false`

## Verification

| Gate | Result |
|---|---|
| Python pytest | Pass (market + prior Phase 4 tests) |
| Laravel PhaseFiveMarketDataTest | Pass |
| Frontend Vitest Phase 5 | Pass (expected) |
| Typecheck / lint / build | Run in verification pass |

## How to run (Linux / mock)

```bash
# Bridge
cd trading-engine
python3 -m pip install -e ".[dev]"
cp .env.example .env   # set NEXA_MT5_SERVICE_TOKEN
python3 -m uvicorn nexa_mt5.api:app --host 127.0.0.1 --port 8765

# Laravel
cd backend
composer install
cp .env.example .env
# TRADING_BRIDGE_URL=http://127.0.0.1:8765
# TRADING_BRIDGE_SERVICE_TOKEN=<same token>
touch database/database.sqlite
php artisan key:generate
php artisan migrate
DEV_SUPER_ADMIN_PASSWORD='choose-at-least-12-characters' php artisan db:seed
php artisan serve --host=0.0.0.0 --port=45281

# Vite
npm install
npm run dev -- --host=0.0.0.0 --port=45280
```

Open the Vite URL, sign in as `admin@nexa.local`, open **Market Watch** and **Live Charts**.

Without bridge token, snapshot uses simulation mock enrichment (`prefer=simulation`).

## Warnings

- Real MT5 still requires a Windows host with MetaTrader 5 DEMO terminal (Phase 4 Windows setup).
- Mock timestamps are fresh but prices are deterministic — not a live feed.
- Phase 6 indicator engine and Phase 7 strategies are not implemented.

## Related docs

- `docs/PHASE_5_ARCHITECTURE.md`
- `docs/MARKET_DATA_CONTRACT.md`
- `docs/PHASE_4_REPORT.md`
- `docs/MT5_BRIDGE_API.md`
