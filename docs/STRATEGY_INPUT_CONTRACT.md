# Strategy Input Contract (Phase 7)

Strategies consume:

1. **MarketSnapshot** — Phase 5 `MarketDataEngineService::snapshot` (quotes, quality, gate, source)
2. **TechnicalSnapshot** — `TechnicalAnalysisEngine::snapshot` adapter over Phase 6 `IndicatorEngineService` + closed candles + derived structure/S-R
3. **MultiTimeframeTechnicalSnapshot** — primary + higher frames with aligned bias

## Rules

- Closed candles only
- Respect data quality / analysis gate
- Never call MT5/bridge from strategy plugins
- Adapter flag `TechnicalSnapshot.adapter=true` until a fuller Phase 6 TA engine lands

## Gaps vs full Phase 6 TA master prompt

Native ADX indicator, dedicated MARKET_STRUCTURE / SUPPORT_RESISTANCE engines, and a non-adapter TechnicalAnalysisEngine were not present on the Phase 6 tip. Phase 7 ships thin deterministic adapters and documents this gap.
