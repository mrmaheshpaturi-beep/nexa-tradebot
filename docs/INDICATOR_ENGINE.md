# Indicator Engine

Central Phase 6 authority for technical indicator calculation.

## Contract

```text
MarketDataEngineService::getClosedCandles(symbol, timeframe, count)
        ↓
  IndicatorEngineService (catalog / compute / series / batch)
        ↓
  Normalized series aligned to candle open_time (UTC)
```

Indicators **must not** call MT5 or the Python bridge. React **must not** talk to the bridge.

## Providers

| Name | Overlay | Default params |
|------|---------|----------------|
| SMA | yes | period=20, source=close |
| EMA | yes | period=20, source=close |
| RSI | panel | period=14, source=close |
| MACD | panel | fast=12, slow=26, signal=9 |
| ATR | panel | period=14 |
| BBANDS | yes | period=20, std_dev=2 |

Provider interface: `App\Contracts\IndicatorProvider`.

## Quality gate

Before returning series, the engine consults Market Data quality:

- `GOOD` → `READY`
- `DEGRADED` → `DEGRADED` (series still returned)
- `BAD` / `UNAVAILABLE` / gate blocked → `REFUSED` with empty series

Forming candles are never treated as finalized.

## Caching

`IndicatorCache` wraps Illuminate Cache (no Redis required). TTL ≈ 30s. Keyed by instrument + timeframe + indicator + params + prefer + count.

## APIs

| Method | Path | Permission |
|--------|------|------------|
| GET | `/api/v1/indicators/catalog` | `trading.read` |
| GET | `/api/v1/indicators/health` | `trading.read` |
| POST | `/api/v1/indicators/compute` | `trading.read` |
| POST | `/api/v1/indicators/batch` | `trading.read` |
| GET | `/api/v1/indicators/{indicator}/series` | `trading.read` |

## Normalized payload

- `instrument`, `timeframe`, `indicator`, `params`
- `timestamps_aligned_to: candle_open_time`
- `source`, `environment`, `generated_at` (UTC)
- `status`, `freshness`, `quality`, `gate`
- `series[]`, `values`
- `execution.order_send: false`

## Python parity

`nexa_mt5.indicators.IndicatorEngine` mirrors pure math for tests. Application authority remains Laravel.

## Out of scope

Strategy decisions (Phase 7), broker execution, external paid data APIs.
