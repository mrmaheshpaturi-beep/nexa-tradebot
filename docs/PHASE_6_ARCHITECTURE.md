# Phase 6 Architecture — Indicator Engine

## Purpose

Phase 6 adds an **Indicator Engine** that computes normalized technical indicator series from **closed candles only**, sourced exclusively through the Phase 5 Market Data Engine. No broker writes. Phase 7 strategies remain PENDING.

## Data flow

```text
MT5 DEMO / Mock → MarketDataEngine → getClosedCandles()
                                      ↓
                              IndicatorEngineService
                         (providers + quality gate + cache)
                                      ↓
                     /api/v1/indicators/{catalog,compute,series,batch}
                                      ↓
                     React Live Charts overlays + indicator panel
                                      ↓
                     Phase 7 Strategies (PENDING — do not implement)
```

## Design rules

1. Indicators consume `MarketDataEngineService::getClosedCandles` only.
2. Never call MT5 / `TradingBridgeClient` from indicator code.
3. React never talks to the bridge.
4. Refuse or degrade on BAD / UNAVAILABLE / blocked quality gate.
5. Timestamps align to candle `open_time` (UTC).
6. `execution.order_send` always false.

## Components

| Layer | Component |
|-------|-----------|
| Python | `nexa_mt5.indicators` pure math + tests |
| Laravel | `IndicatorProvider` implementations, `IndicatorEngineService`, `IndicatorCache`, `IndicatorController` |
| React | Chart line overlays + RSI/MACD/ATR panel on Live Charts |

## Extension hooks

- `phase_6_indicator_engine` → **READY**
- `phase_7_strategies` → **PENDING**
