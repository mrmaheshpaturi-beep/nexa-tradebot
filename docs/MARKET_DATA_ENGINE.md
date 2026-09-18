# Market Data Engine

Central Phase 5 authority for quotes, candles, symbols, freshness, quality, sessions, and snapshots.

## Providers

- `MockMarketDataProvider` — SIMULATION deterministic quotes/candles
- `Mt5MarketDataProvider` — MT5 DEMO via Python bridge (`TradingBridgeClient`)
- Future: `ExternalMarketDataProvider` (not implemented)

React never talks to the bridge. Strategies/indicators must consume `MarketDataEngineService` / snapshot APIs.

## Lifecycle

1. Poll/ingest from selected provider
2. Normalize + validate quotes/candles
3. Classify freshness (FRESH/AGING/STALE/UNAVAILABLE)
4. Score quality + gate (`usable_for_analysis`)
5. Cache latest quotes; upsert selected history
6. Publish Market Snapshot for UI / Phase 6–7

## No silent fallback

When source preference is `bridge` / MT5 DEMO, failures return `MT5 DATA UNAVAILABLE`. Mock is only used when explicitly selected (`simulation`).

## Phase 6 contract

`MarketDataEngineService::getClosedCandles()` and `GET /api/v1/market/candles/{symbol}/closed`.

Phase 6 Indicator Engine is **READY** — see `INDICATOR_ENGINE.md`.
