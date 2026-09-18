# Phase 8 Architecture — Market Scanner + Signal Orchestration

## Packages

- `App\Services\MarketScannerEngineService`
- `App\Services\SignalOrchestratorService`
- `App\Services\AlertPipelineService`
- `App\Http\Controllers\Api\MarketScannerController`
- `App\Console\Commands\RunMarketScannerCommand`
- Models: `ScannerConfig`, `ScannerRun`, `SignalCandidate`, `ScannerAlertEvent`

## Pipeline

```
MarketDataEngine
  → TechnicalAnalysisEngine
  → MarketScannerEngine (universe × TF × strategy)
  → StrategyEngine / plugins
  → SignalEngine (optional persist) + ConfluenceEngine
  → SignalOrchestrator (rank / conflict / queue)
  → Dashboard + Alert foundation
```

## Data

Migration `2026_09_18_230000_create_phase_eight_scanner_orchestrator.php`:

- `scanner_configs`
- `scanner_runs` (idempotent `run_key`)
- `signal_candidates` (queue — not orders)
- `scanner_alert_events`

## UI

`PhaseEightMarketScanner` replaces the mock Market Scanner page with live board, matrix, filters, candidate actions, and health.

## Safety boundary

Candidates ≠ Orders. No broker routing. Execution flags always false for DEMO/LIVE/`order_send`.
