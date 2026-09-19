# Analytics Engine

Phase 12 research analytics over finalized DEMO/SIMULATION outcomes.

## Inputs (from Phase 12 contract)

TradeSummary, strategy identity/version, candidate/signal, RiskDecision, ExecutionResult, management events, MAE/MFE, R-multiple, net P/L, regime, session, timeframe.

## Capabilities

- Reproducible `AnalyticsDataset` + content hash / fingerprint
- Immutable `AnalyticsSnapshot` metrics (core, risk-adjusted, R, MAE/MFE, cost, execution, management)
- Win rate is **N/A** when no completed outcomes
- Deterministic — no undisclosed random seeds
- CSV/JSON export
- Trade explorer
- BACKTEST vs DEMO comparison with strict label separation
- Promote endpoints return **403** — never auto-change strategies/risk

## Safety

Zero broker-changing calls. LIVE hard-blocked. Does not consume BACKTEST as DEMO.
