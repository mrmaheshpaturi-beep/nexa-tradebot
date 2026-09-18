# Market Data Contract

## Phase 3 provider

`MarketDataProvider` is bound to `MockMarketDataProvider`. All returned data is deterministic, explicitly marked `source=MOCK` and `environment=SIMULATION`, and unsuitable for market decisions or broker execution.

## Quote contract

Quotes contain `symbol`, decimal-string `bid`, `ask`, `spread`, UTC ISO-8601 `timestamp`, `source`, and `environment`.

| Symbol | Bid | Ask | Digits | Tick size |
|---|---:|---:|---:|---:|
| EURUSD | 1.10000 | 1.10020 | 5 | 0.00001 |
| GBPUSD | 1.27500 | 1.27530 | 5 | 0.00001 |
| USDJPY | 145.100 | 145.120 | 3 | 0.001 |
| XAUUSD | 2350.10 | 2350.30 | 2 | 0.01 |
| NAS100 | 19000.00 | 19001.00 | 2 | 0.01 |
| BTCUSD | 60000.00 | 60010.00 | 2 | 0.01 |

Authenticated `GET /api/v1/instruments` and `/instruments/{public_id}` include the backend `mock_quote` with each instrument specification. This is the authoritative Phase 3 UI reference; the React form does not invent prices.

## Instrument specification

Each instrument defines public ID, symbol/display name, asset class, base/quote currencies, digits, point/tick size, tick value, contract size, volume min/max/step, minimum stop distance, margin rate, and enabled state.

The seed uses a zero minimum stop distance. Side geometry is still enforced. UI number controls use tick/volume steps as guidance; server volume validation is authoritative. Phase 3 does not claim full broker symbol-rule parity.

## Execution semantics

- MARKET BUY entry: ask.
- MARKET SELL entry: bid.
- BUY position mark/close: bid.
- SELL position mark/close: ask.
- Pending intent protection reference: requested entry.
- Open-position SL/TP modification reference: current close-side quote.

This produces an immediate spread loss for an opened position and corresponding account equity/free-margin values.

## Candle contract

The provider can return up to 500 deterministic candles for `M1`, `M5`, `M15`, `M30`, `H1`, `H4`, or `D1`. Each includes symbol/timeframe, UTC open/close times, OHLC decimal strings, tick volume, `MOCK`, and `SIMULATION`. No Phase 3 HTTP candle endpoint or stream is exposed; retained chart screens still use frontend mock services.

## Freshness and availability

Timestamps are generated at request time, but prices are fixed. A fresh timestamp is not evidence of a live feed. Unsupported symbols fail validation. There are no subscriptions, sockets, gaps, trading-session calendars, market-open checks, stale thresholds, provider failover, or historical guarantees.

## Phase 4 boundary

The initial Phase 4 contract may read adapter state and market data, but must not place, modify, cancel, or close anything. See `MT5_INTEGRATION_CONTRACT.md`.
