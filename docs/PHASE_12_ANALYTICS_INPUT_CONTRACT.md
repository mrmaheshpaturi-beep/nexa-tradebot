# Phase 12 Analytics Input Contract (stub only)

**Status: NOT IMPLEMENTED.** Input contract for a future phase. Do not treat as enabled capability.

## Boundary

Phase 11 ends at DEMO-only TradeManagementEngine over Nexa-managed positions (break-even, trail, partial/full close, exits) behind PositionManagementGate and the sole authorized `order_send`.

Phase 12 may address (examples only):

1. Trade analytics over finalized TradeSummary / MAE-MFE
2. Performance attribution by strategy/policy version
3. Operator reporting — still DEMO-first; LIVE remains hard-disabled

## Non-negotiable constraints carried forward

1. LIVE remains hard-disabled until separate governance.
2. Auto Demo remains OFF unless explicitly unlocked.
3. React never talks to the bridge for writes.
4. RiskEngine never calls MT5 order APIs.
5. Exactly one authorized `order_send` call site.
6. Foreign positions never auto-managed.

## Explicit non-goals of this stub

- No LIVE enablement
- No AutoTrading
- No Phase 12 implementation work
