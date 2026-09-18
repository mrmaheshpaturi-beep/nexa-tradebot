# Market Data Quality

`MarketDataQualityService` scores quotes/candles and exposes a gate for future strategies.

Statuses: GOOD, DEGRADED, BAD, UNAVAILABLE.

Gate: `usable_for_analysis` — STALE/disconnected data blocks analysis. Phase 5 never executes trades.
