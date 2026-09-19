# Phase 14 Contract (stub only)

**Status: NOT IMPLEMENTED.** Do not treat as enabled capability.

## Boundary

Phase 13 ends at advisory/shadow TradeIntelligenceEngine with Mock AI/news/calendar providers. Zero broker-changing calls from intelligence. No risk/settings/strategy mutation. LIVE remains hard-disabled.

Phase 14 may address (examples only):

1. Operator workflow / delivery hardening beyond advisory desk
2. Controlled paper-trading loops still behind existing gates
3. Observability and retention — still DEMO-first; LIVE remains hard-disabled

## Non-negotiable constraints carried forward

1. LIVE remains hard-disabled until separate governance.
2. Auto Demo remains OFF unless explicitly unlocked.
3. React never talks to the bridge for writes.
4. Intelligence never calls MT5 order APIs or mutates risk/settings/strategies.
5. Exactly one authorized `order_send` call site (Phase 10 execution module).
6. Evidence labels (Historical/DEMO/Backtest/OOS/Execution/Portfolio) remain strictly separate.
7. Research/intelligence results never auto-promote strategies or risk.

## Explicit non-goals of this stub

- No LIVE enablement
- No AutoTrading from AI
- No Phase 14 implementation work
