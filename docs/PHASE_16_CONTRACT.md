# Phase 16 Contract — Strategy Governance / Release Engineering / Controlled DEMO Promotion

**Status: IMPLEMENTED** on branch `cursor/phase-16-strategy-governance-56f9`.

## Scope

1. Immutable semantic strategy + configuration versions with code hashes
2. Enforced lifecycle (illegal jumps rejected)
3. Release candidates, evidence packages, validation policies/decisions
4. Two-step human approvals (bound single-use tokens, staleness, replay protection)
5. Promotion and DEMO-only deployments integrated with Phase 14 AutomationProfile
6. Rollback / suspension / retirement preserving managed positions + history
7. Comparisons/diffs, Strategy Lab (robustness / cost sensitivity), portfolios, change requests
8. Dependency/health isolation/timeouts for lab; deterministic conflict resolution

## Allowed lifecycle transitions

| From | To |
|---|---|
| DRAFT | IN_REVIEW, RETIRED |
| IN_REVIEW | RELEASE_CANDIDATE, DRAFT, REJECTED |
| RELEASE_CANDIDATE | APPROVED (2-step), IN_REVIEW, REJECTED |
| APPROVED | DEPLOYED_DEMO (2-step promote), SUSPENDED, RETIRED |
| DEPLOYED_DEMO | SUSPENDED, ROLLED_BACK, RETIRED |
| SUSPENDED | DEPLOYED_DEMO, RETIRED, ROLLED_BACK |
| REJECTED | DRAFT |
| ROLLED_BACK | APPROVED, RETIRED, DRAFT |
| RETIRED | (terminal) |

`APPROVED` and `DEPLOYED_DEMO` require two-step human approval — direct transition API refuses.

## Non-negotiable safety

1. AI must NOT approve, deploy, or change active config
2. Governance / Lab: `order_send` call sites = 0
3. RiskEngine remains mandatory on any trading path
4. Phase 10 remains sole execution authority
5. LIVE / UNKNOWN hard blocked; LIVE_AUTO does not exist
6. DEMO-only deployments via Phase 14
7. Insufficient samples → cannot auto-approve (human may still reject)
8. Rollback/suspend/retire must not abandon Phase 11 managed positions

## Explicit non-goals

- No LIVE enablement / LIVE_AUTO
- No Phase 17 implementation (stub only: `PHASE_17_CONTRACT.md`)
- No weakening of Phase 14/15 safety

See `PHASE_16_REPORT.md`, `STRATEGY_GOVERNANCE.md`, `STRATEGY_VERSIONING.md`.
