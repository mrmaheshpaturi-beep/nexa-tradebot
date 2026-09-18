# Phase 5 Architecture — Market Data Engine

## Purpose

Phase 5 adds a **Market Data Engine** on top of the Phase 4 read-only MT5 bridge. It normalizes quotes, candles, and symbols; applies freshness and validation rules; scores quality; and publishes an aggregated **Market Snapshot** for the dashboard and later Phase 6/7 consumers. Broker execution remains disabled.

## System diagram

```text
XM DEMO / MT5
      ↓
Python Bridge (Phase 4 + /v1/market/*)
      ↓
MARKET DATA ENGINE
      ↓
┌──────────┬──────────┬───────────┐
Quotes     Candles     Symbols
   ↓          ↓           ↓
Freshness + Validation + Quality
              ↓
       Market Snapshot
              ↓
     ┌────────┴─────────┐
     ↓                  ↓
Dashboard          Phase 6 (PENDING)
Market Watch       Indicator Engine
Charts                  ↓
                    Phase 7 (PENDING)
                  Strategies
```

## Layers

| Layer | Responsibility |
|---|---|
| Python `MarketDataEngine` | Normalize bridge ticks/rates/symbols; compute spread, freshness, quality issues/score |
| Laravel `MarketDataEngineService` | Prefer bridge snapshot when configured; else simulation mock enrichment; persist quotes/candles/symbols/snapshots |
| React Phase 5 pages | Market Watch + Live Charts consume `/api/v1/market/snapshot` with quality/freshness badges |
| Extension hooks | Explicit `PENDING` stubs for Phase 6 indicators and Phase 7 strategies |

## APIs

### Python bridge

- `GET /v1/market/quotes`
- `GET /v1/market/quotes/{symbol}`
- `GET /v1/market/candles/{symbol}`
- `GET /v1/market/symbols`
- `GET /v1/market/snapshot`

### Laravel (session auth)

- `GET /api/v1/market/snapshot`
- `GET /api/v1/market/quotes`
- `GET /api/v1/market/candles/{symbol}`
- `GET /api/v1/market/symbols`
- `GET /api/v1/market/snapshots/latest`
- `GET /api/v1/market/extension-hooks`

Query flags: `prefer=auto|bridge|simulation`, `persist`, `timeframe`, `candle_count`.

## Quality model

- Score 0–100 with labels: EXCELLENT / GOOD / DEGRADED / POOR / INVALID
- Quote issues: MISSING_PRICE, NON_POSITIVE_PRICE, INVERTED_SPREAD, ZERO_SPREAD, MISSING_SYMBOL, MISSING_TIMESTAMP, STALE
- Candle issues: MISSING_OHLC, OHLC_INCONSISTENT, NON_POSITIVE_OHLC, MISSING_TIMESTAMP
- `usable=false` quotes/bars are marked and excluded from chart series

## Freshness

Live quotes older than `stale_after_seconds` (default 15) are STALE and lose quality points. Historical candles are not force-staled by bar age.

## Safety

- READ-ONLY only. No `order_send`, no DEMO/LIVE execution flags.
- SIMULATION vs DEMO environments remain labeled in snapshot payloads.
- Bridge credentials stay server-side.

## Persistence

Migration `2026_09_18_120000_create_phase_five_market_data` adds:

- `market_symbols`
- `market_quotes`
- `market_candles`
- `market_snapshots`
