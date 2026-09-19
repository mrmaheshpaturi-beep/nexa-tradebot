# Phase 14 Automation Orchestration Contract

**Status: IMPLEMENTED (DEMO_AUTO / DRY_RUN).** See `PHASE_14_REPORT.md` and `AUTOMATED_TRADING_ORCHESTRATOR.md`.

## Boundary

Phase 13 ends at advisory/shadow TradeIntelligenceEngine.

Phase 14 adds `AutomatedTradingOrchestrator` coordinating the full DEMO automation pipeline without requiring manual approve on every DEMO trade — **only when actual MT5 account trade mode == DEMO**.

## Modes

| Mode | Broker writes | Notes |
|---|---|---|
| OFF | None | Default after install |
| DRY_RUN | None | Full qualification/risk path simulation |
| DEMO_AUTO | Via Phase 10 only | Requires verified DEMO + unlocks |
| LIVE_AUTO | — | **Does not exist** |

## Non-negotiable constraints

1. LIVE remains hard-disabled.
2. AI never final authority; never calls MT5; failure → WAIT/EXPIRE/REJECT.
3. Phase 10 sole `order_send`; Phase 14 sites = 0.
4. Fresh RiskDecision per workflow; no stale reuse.
5. No blind retry on UNKNOWN — reconcile.
6. No auto risk/strategy mutation; no online self-optimization.
7. CI uses Fake/Mock broker adapters only.
