# Market Scanner Engine

Phase 8 coordinated multi-symbol / multi-timeframe / multi-strategy scanner.

## Flow

`MarketDataEngine → TechnicalAnalysisEngine → MarketScannerEngine → StrategyEngine → SignalEngine / Confluence → SignalOrchestrator → Candidate Queue`

## Responsibilities

- Configurable universe (`scanner_configs`: symbols, timeframes, strategy ids, trigger mode)
- Triggers: `ON_CANDLE_CLOSE`, `ON_INTERVAL`, `MANUAL`
- Idempotent runs via `scanner_runs.run_key`
- Bulk-efficient cell evaluation across the universe
- Emits candidates to the orchestrator — **never** broker orders

## CLI

```bash
php artisan scanner:run --user=1 --trigger=ON_CANDLE_CLOSE --prefer=simulation
```

## APIs

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/scanner/health` | Heartbeat, last scan |
| GET | `/api/v1/scanner/universe` | Default symbols/TFs |
| GET | `/api/v1/scanner/board` | Live board + queue |
| POST | `/api/v1/scanner/run` | Start scan |
| GET/POST | `/api/v1/scanner/matrix` | Opportunity matrix |
| GET/PUT | `/api/v1/scanner/configs` | Universe config |
| GET | `/api/v1/scanner/runs` | Recent runs |

## Safety

- `order_send`: false
- `broker_routing`: false
- MT5 remains read-only
- Auto-trading remains DISABLED
