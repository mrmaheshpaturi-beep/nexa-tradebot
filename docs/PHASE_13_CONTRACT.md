# Phase 13 Contract (stub only)

**Status: NOT IMPLEMENTED.** Do not treat as enabled capability.

## Boundary

Phase 12 ends at research AnalyticsEngine + BacktestEngine (BACKTEST environment). Zero broker-changing calls. No auto-promote of strategies/risk. LIVE remains hard-disabled.

Phase 13 may address (examples only):

1. Operator workflow hardening / reporting delivery
2. Controlled paper-trading loops still behind existing gates
3. Observability and retention — still DEMO-first; LIVE remains hard-disabled

## Non-negotiable constraints carried forward

1. LIVE remains hard-disabled until separate governance.
2. Auto Demo remains OFF unless explicitly unlocked.
3. React never talks to the bridge for writes.
4. Analytics/Backtest never call MT5 order APIs.
5. Exactly one authorized `order_send` call site (Phase 10 execution module).
6. BACKTEST and DEMO labels remain strictly separate.
7. Research results never auto-promote strategies or risk.

## Explicit non-goals of this stub

- No LIVE enablement
- No AutoTrading
- No Phase 13 implementation work
