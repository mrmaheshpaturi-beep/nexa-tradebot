# Phase 11 Contract (stub only)

**Status: NOT IMPLEMENTED.** Input contract for a future phase. Do not treat as enabled capability.

## Boundary

Phase 10 ends at gated DEMO ExecutionEngine with manual two-step confirmation and sole authorized `order_send`.

Phase 11 may address (examples only):

1. Hardened DEMO operations (observability, retention, operator runbooks)
2. Expanded position management over the DEMO write path (modify/close) behind the same gates
3. Durable outbox / exactly-once delivery improvements beyond process-local nonce cache
4. AI analysis still behind risk + execution gates (no auto-routing)

## Non-negotiable constraints carried forward

1. LIVE remains hard-disabled until separate governance.
2. Auto Demo remains OFF unless explicitly unlocked by a future governance decision.
3. React never talks to the bridge for writes.
4. RiskEngine never calls MT5 order APIs.
5. Exactly one authorized `order_send` call site unless a future phase documents a deliberate replacement.
6. No silent mock prices under MT5 DEMO label.

## Explicit non-goals of this stub

- No LIVE enablement
- No AutoTrading
- No Phase 11 implementation work
