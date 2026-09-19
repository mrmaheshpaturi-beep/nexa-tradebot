# Phase 10 Execution Contract (stub only)

**Status: NOT IMPLEMENTED.** This document is an input contract for a future phase. Do not treat it as enabled capability.

## Boundary

Phase 9 ends at:

```text
TradeIntent → RiskDecision (APPROVED) + ProposedPlan + RiskReservation
```

Phase 10 may consume those artifacts to create `ExecutionCommand` for a future adapter. It must not weaken Phase 3 `ExecutionGate`.

## Required inputs from Phase 9

| Artifact | Required fields |
|---|---|
| RiskDecision | `status=APPROVED`, `public_id`, `profile_version`, `config_hash`, `approved_volume`, `approved_risk` |
| ProposedPlan | `proposed_volume`, prices, `broker_routable=false` until explicitly upgraded by governance |
| RiskReservation | Active margin/risk reservation idempotency key |
| TradeIntent | Owner, account, instrument, side, order type, protections |

## Non-negotiable constraints

1. MT5 / DEMO / LIVE execution remain DISABLED until a separate governance decision.
2. Application-owned `order_send` remains NONE until Phase 10+ explicitly implements a write bridge with allowlists, reconciliation, and audit.
3. React must never talk to the bridge for writes.
4. RiskEngine must never call MT5 order APIs.
5. Fail closed remains mandatory when reservation expired or decision superseded.
6. SimulationExecutionAdapter remains the only executable path unless a new adapter is introduced behind ExecutionGate.

## Suggested Phase 10 sequence (future)

1. Consume approved ProposedPlan → ExecutionCommand (SIMULATION first).
2. Reservation consume/release on fill/reject.
3. Optional DEMO write adapter behind explicit flags (default false).
4. Reconciliation + durable delivery.

## Explicit non-goals of this stub

- No DEMO enablement
- No LIVE enablement
- No `order_send` implementation
- No auto-routing from RiskEngine to brokers
