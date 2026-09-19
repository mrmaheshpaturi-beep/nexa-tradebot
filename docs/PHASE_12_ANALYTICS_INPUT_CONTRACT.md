# Phase 12 Analytics Input Contract

**Status: IMPLEMENTED in Phase 12** (see `PHASE_12_REPORT.md`, `ANALYTICS_ENGINE.md`).

## Boundary

Phase 11 ends at DEMO-only TradeManagementEngine. Phase 12 consumes finalized research inputs without broker writes.

## Inputs received

1. TradeSummary (immutable finalize)
2. Strategy identity/version
3. Candidate data
4. Signal data
5. RiskDecision
6. ExecutionResult
7. Management events
8. MAE / MFE / R-multiple / net P/L
9. Market regime / session / timeframe

## Non-negotiable constraints

1. LIVE remains hard-disabled
2. Auto Demo remains OFF unless unlocked
3. React never talks to the bridge for writes
4. Analytics/Backtest never call MT5 order APIs
5. Exactly one authorized `order_send` call site
6. No auto-promote of strategies/risk from research results
7. BACKTEST and DEMO remain strictly separate environments/labels
