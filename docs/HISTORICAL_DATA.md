# Historical Data

Candles persist in `market_candles` with unique `(symbol, timeframe, open_time, source, environment)`.

Backfill is controlled via `POST /api/v1/market/backfill` (permission `market.configure`), idempotent upsert, server-limited count.

Ticks are not persisted indefinitely — used for current quote/spread only.
