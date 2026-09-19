# Strategy Governance

Phase 16 authority for strategy release engineering and controlled DEMO promotion.

## Components

| Component | Role |
|---|---|
| `StrategyGovernanceService` | Registry, lifecycle, evidence, approvals, promote, rollback, lab, portfolios |
| `LifecycleGuard` | Enforces allowed transitions only |
| `ConflictResolver` | Deterministic portfolio member resolution |
| `GovernanceSafety` | Hard constants: AI cannot approve/deploy; DEMO_AUTO only; order_send=0 |

## Promotion path (DEMO only)

```text
DRAFT → IN_REVIEW → RELEASE_CANDIDATE
  → evidence + validation decision
  → two-step APPROVE_CANDIDATE → APPROVED
  → two-step PROMOTE_DEMO → DEPLOYED_DEMO
  → updates Phase 14 AutomationProfile strategy_matrix
```

LIVE / LIVE_AUTO deploy endpoints always 403.

## Approvals

- Bound to version `public_id` + `code_hash` + `config_hash` + action
- Two single-use tokens with unique nonces
- TTL / staleness checks; replay rejected
- AI actor_type refused

## Strategy Lab

Modes: ROBUSTNESS | COST_SENSITIVITY | SHADOW | AB  
Isolation key + timeout; `mutates_active_config=false`; `can_deploy=false`; never calls order_send.

## APIs

Prefix `/api/v1/governance/*` — permissions `governance.view|manage|approve|lab`.

UI: `#/strategy-governance` (also `#/strategy-lab`).
