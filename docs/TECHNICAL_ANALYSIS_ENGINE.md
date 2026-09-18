# Technical Analysis Engine (Phase 7 adapter)

`App\Services\TechnicalAnalysisEngine` builds `TechnicalSnapshot` / `MultiTimeframeTechnicalSnapshot` from:

- `MarketDataEngineService::getClosedCandles`
- `IndicatorEngineService` (SMA/EMA/RSI/MACD/ATR/BBANDS)
- Derived swing structure + recent S/R from closed candles

Read-only. Deterministic. No broker calls.
