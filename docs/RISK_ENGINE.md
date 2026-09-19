# Risk Engine

## Role

`RiskEngineService` is the **authoritative server-side** risk authority for Nexa TradeBot Phase 9.

- React never decides risk alone.
- Risk produces immutable `RiskDecision` + `ProposedPlan` records.
- Risk never creates broker orders, never calls MT5, never uses `order_send`.
- Fail closed: missing snapshot/specs/quality → REJECT.

## Type separation

```text
Signal ≠ TradeIntent ≠ RiskDecision ≠ ProposedPlan ≠ ExecutionCommand ≠ Order ≠ Deal ≠ Position
```

`ProposedPlan` is a sizing proposal only. It is never broker-routable (`broker_routable=false`).

## Pipeline

```text
TradeIntent (PENDING_RISK)
  → RiskEngineService.evaluate
     → account context + quote + symbol specs
     → PositionSizingService.propose
     → versioned RiskRule modules (priority order)
     → immutable RiskDecision
     → ProposedPlan
     → RiskReservation (on approve) / RiskLock (on breach)
  → TradeIntent RISK_APPROVED | RISK_REJECTED
```

Phase 3 `SimulationRiskEvaluator` now delegates to `RiskEngineService`.

## Versions

| Field | Meaning |
|---|---|
| `engine_version` | `RiskEngine/v1` |
| `rules_bundle_version` | `risk-rules/v1` |
| `profile_version` | Increments on profile limit/config changes |
| `config_hash` | SHA-256 of profile risk configuration |

Every decision stores these for reproducibility.

## APIs

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/risk-engine/health` | `trading.read` |
| GET | `/api/v1/risk-engine/catalog` | `risk_engine.view` |
| GET | `/api/v1/risk-engine/dashboard` | `risk_engine.view` |
| GET | `/api/v1/risk-engine/decisions` | `risk_engine.view` |
| GET | `/api/v1/risk-engine/decisions/{id}` | `risk_engine.view` |
| GET | `/api/v1/risk-engine/plans/{id}` | `risk_engine.view` |
| POST | `/api/v1/risk-engine/evaluate/{tradeIntent}` | `risk_engine.evaluate` |
| GET/POST | `/api/v1/risk-engine/locks` | view / `risk_engine.lock` |
| POST | `/api/v1/risk-engine/locks/{id}/release` | `risk_engine.lock` |

## Safety

- DEMO/LIVE rejected by `ExecutionGate` (unchanged).
- `order_send` usage: NONE.
- Simulation ledger execution remains optional and gated.
- Phase 10 execution adapter is contract-only (see `PHASE_10_EXECUTION_CONTRACT.md`).
