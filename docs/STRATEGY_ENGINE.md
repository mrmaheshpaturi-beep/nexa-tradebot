# Strategy Engine

Phase 7 analysis layer. Consumes Market Data (Phase 5) and Indicators / Technical adapters (Phase 6+) to produce `StrategyEvaluation` and optional `Signal` records.

## Flow

```
MarketSnapshot + closed candles
        +
TechnicalSnapshot / MultiTimeframeTechnicalSnapshot (adapter over IndicatorEngine)
        ↓
StrategyEngine + built-in plugins (1–12)
        ↓
ConfluenceEngine → SignalEngine (fingerprint / cooldown / expiry)
        ↓
SIMULATE → Simulation TradeIntent only
```

## Safety

- No `order_send`
- DEMO/LIVE execution rejected by ExecutionGate
- `auto_trading_enabled` forced false
- `auto_simulation` default false
- Scores are 0–100 confluence measures, **not** win probabilities

## APIs

- `GET /api/v1/strategy-engine/catalog`
- `GET /api/v1/strategy-engine/health`
- `POST /api/v1/strategy-engine/scan`
- `POST /api/v1/strategy-engine/confluence`
- `GET /api/v1/strategy-engine/matrix`
- `POST /api/v1/strategy-engine/run`
- `POST /api/v1/strategies/{id}/evaluate`
- `GET /api/v1/strategies/{id}/performance`

## Scheduler

`php artisan strategies:evaluate` — ON_CANDLE_CLOSE idempotent evaluations via candle close key + fingerprint.
