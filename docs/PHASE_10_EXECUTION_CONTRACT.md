# Phase 10 Execution Contract

**Status: IMPLEMENTED (DEMO-only, gated).** See `PHASE_10_REPORT.md` and `EXECUTION_ENGINE.md`.

## Boundary

Phase 9 ends at:

```text
TradeIntent → RiskDecision (APPROVED) + ProposedPlan + RiskReservation
```

Phase 10 consumes those artifacts for:

1. **SIMULATION** — existing `TradeLifecycleService` path (unchanged adapter)
2. **DEMO** — `ExecutionEngineService` with two-step confirmation and sole authorized `order_send`

## Required inputs from Phase 9

| Artifact | Required fields |
|---|---|
| RiskDecision | `status=APPROVED`, approved volume/risk, profile version |
| ProposedPlan | sizing/prices; still not auto-routed |
| RiskReservation | Active reservation consumed/released on fill/reject |
| TradeIntent | Owner, DEMO account, instrument, side, protections |

## Non-negotiable constraints

1. LIVE hard-fail at ExecutionGate + verifier + bridge + request account mode.
2. UNKNOWN account trade mode hard-fail.
3. `allow_demo_execution` default false; `auto_demo_execution` locked false.
4. Two-step manual confirmation before DEMO submit.
5. `order_check` before the sole `authorized_order_send`.
6. CI uses fake adapters only — never real MT5.
7. SimulationExecutionAdapter remains for SIMULATION; DEMO intents never silently route to simulation fills.
8. No silent mock prices under MT5 DEMO label.

## Sole order_send location

`trading-engine/src/nexa_mt5/execution.py::authorized_order_send`
