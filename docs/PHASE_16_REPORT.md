# Phase 16 Report — Strategy Governance / Release Engineering / Controlled DEMO Promotion

## 0. Meta

- Branch: `cursor/phase-16-strategy-governance-56f9`
- Worktree: `/tmp/nexa-phase16-56f9`
- Base: Phase 15 tip `7d260ce` (`cursor/phase-15-observability-hardening-56f9`)
- Preview: Vite [http://127.0.0.1:58416](http://127.0.0.1:58416) · Laravel [http://127.0.0.1:48416](http://127.0.0.1:48416)
- Admin: `admin@nexa.local` / `NexaLocalDevPass1!`
- UI: `#/strategy-governance` · `#/strategy-lab`

## Phase verdict

**PASS WITH WARNINGS** — governance/release/DEMO promotion complete in code; Windows MT5 DEMO soak and Hostinger deployment remain pending (carried from prior phases).

## 1. Executive Summary

Phase 16 delivers `StrategyGovernanceService` with immutable semantic versions (code + config hashes), enforced lifecycle, release candidates, evidence packages (Phase 12 + Phase 15 links), validation policies/decisions, two-step human approvals (bound single-use tokens, staleness, replay protection), DEMO-only promotion into Phase 14 `AutomationProfile`, rollback/suspend/retire with positions/history preserved, comparisons, Strategy Lab, portfolios, change requests, RBAC/APIs/audit/idempotency, Governance UI, and Phase 17 contract stub only.

## 2. Phase Status

**PASS WITH WARNINGS**

## 3. StrategyGovernanceService

Implemented under `backend/app/Governance/StrategyGovernanceService.php`. Orchestrates registry, lifecycle, evidence, approvals, promote, rollback, lab, portfolios, change requests. Safety constants in `GovernanceSafety`.

## 4. Strategy Registry

`registerVersion` binds plugin keys from Phase 7 `StrategyRegistry`, writes `governed_strategy_versions` with semantic version uniqueness per strategy key.

## 5. Immutable Semantic Versions + Code Hashes

- `semantic_version` MAJOR.MINOR.PATCH
- `code_hash` = sha256(plugin key/class/defaults/category)
- `config_hash` = sha256(canonical configuration)
- Versions become immutable after leaving DRAFT

## 6. Lifecycle Enforcement (no illegal jumps)

`LifecycleGuard` + `StrategyLifecycleState::allowedNext()`. Direct API refuse for APPROVED / DEPLOYED_DEMO (require two-step approval). Documented in `PHASE_16_CONTRACT.md` and `/api/v1/governance/lifecycle`.

## 7. Release Candidates

`openReleaseCandidate` moves IN_REVIEW → RELEASE_CANDIDATE and creates `strategy_release_candidates`.

## 8. Evidence Packages

`buildEvidencePackage` with DEMO|BACKTEST|WALK_FORWARD|DRY_RUN labels; links Phase 12 analytics refs and Phase 15 forward validation sessions; insufficient samples ⇒ `can_auto_approve=false`.

## 9. Validation Policies / Decisions

Policies hard-force `auto_approve_enabled=false`. Decisions: PASS|FAIL|INSUFFICIENT|HUMAN_REJECT. Auto evaluation never grants deploy authority.

## 10. Two-Step Human Approvals

`startApproval` + `consumeApprovalStep`:
- Bound resource hash (version + code/config hashes + action)
- Single-use token hashes + unique nonces
- TTL/staleness; replay rejected
- Idempotency keys for step consumption
- AI actor_type refused

## 11. Promotion

`PROMOTE_DEMO` after APPROVED → updates/creates Phase 14 AutomationProfile strategy matrix with governed version hashes → DEPLOYED_DEMO.

## 12. DEMO-Only Deployments (Phase 14 integrated)

`strategy_deployments.target = DEMO_AUTO` only. `/governance/live-deploy` always 403. LIVE_AUTO does not exist.

## 13. Rollback / Suspension / Retirement

Preserve `positions_preserved` + `history_preserved`. Demotes AutomationProfile from ACTIVE without abandoning Phase 11 managed positions (`phase11_positions_abandoned=false` in events).

## 14. Comparisons / Diffs

`compare` persists config/code/lifecycle diffs in `strategy_comparisons`.

## 15. Experiments / Strategy Lab / Robustness / Cost Sensitivity

Lab modes ROBUSTNESS|COST_SENSITIVITY|SHADOW|AB with isolation keys + timeouts; `mutates_active_config=false`; `can_deploy=false`; order_send=0.

## 16. Dependency / Health Isolation / Timeouts

Lab isolation_key + timeout_seconds (default 30). Governance health payload on `/api/v1/governance/health` and system status `strategy_governance`.

## 17. Deterministic Conflict Resolution

`ConflictResolver`: priority ASC → weight DESC → key ASC → semver DESC; duplicate keys keep highest semver.

## 18. Versioned Strategy Portfolios

`strategy_portfolios` with `portfolio_hash` and embedded conflict resolution metadata.

## 19. Change Requests

`strategy_change_requests` always `requires_human_approval=true`, `ai_may_apply=false` even when filed by AI actor.

## 20. Governance UI

`PhaseSixteenGovernancePages` — Registry / Evidence / Approvals / Deployments / Lab / Compare / Lifecycle. DEMO-only banners; no live-auto controls.

## 21. Migrations / APIs / RBAC / Audit / Concurrency / Idempotency

- Migration `2026_09_19_260000_create_phase_sixteen_strategy_governance.php` (additive)
- Routes `/api/v1/governance/*`
- Permissions `governance.view|manage|approve|lab`
- Audit via `AuditService` + immutable `governance_events`
- Row locks on approval step; `governance_idempotency_keys`

## 22. Docs / Phase 17 Contract

Updated: ARCHITECTURE, SECURITY, ROADMAP, DATABASE_SCHEMA, AUTHORIZATION, STRATEGY_VERSIONING.  
New: PHASE_16_CONTRACT, PHASE_16_REPORT, STRATEGY_GOVERNANCE, PHASE_17_CONTRACT (**stub only**).

## 23. Safety matrix

| Control | Value |
|---|---|
| AI approve/deploy/change active config | NONE |
| Governance/Lab → MT5/order_send | NONE |
| RiskEngine mandatory | YES (unchanged) |
| Phase 10 sole execution authority | YES |
| LIVE/UNKNOWN | HARD BLOCKED |
| LIVE_AUTO | DOES NOT EXIST |

## 24. Quality gates

| Gate | Result |
|---|---|
| PHPUnit | 201 passed / 1 skipped / 0 failed (202 total) |
| Phase 16 feature tests | 9/9 passed |
| Vitest | 26 passed |
| TypeScript (`tsc -b`) | PASS |
| ESLint | PASS (1 pre-existing warning) |
| Production build | PASS |
| Python pytest (trading-engine) | PASS |
| `phase16-strategy-governance-audit.sh` | PASS |
| `phase15-observability-audit.sh` | PASS |
| `phase14-demo-automation-audit.sh` | PASS |

## 25. Remaining Phase 16 issues / warnings

1. Windows MT5 DEMO soak / Hostinger deployment still pending (prior phases)
2. Lab experiments are deterministic research stubs (not full Monte Carlo research engine)
3. Evidence package Phase 12/15 linking is by reference IDs — operators must supply/attach real session IDs when available

## 26. Manual actions required

1. Login as admin on preview → Strategy Governance
2. Register version → open RC → evidence → two-step APPROVE → two-step PROMOTE_DEMO
3. Confirm Automation Control Center profile matrix updated
4. Confirm live-deploy / ai-approve endpoints 403

## 27. DEMO governance testing notes

- Insufficient samples yield INSUFFICIENT decision; still allow human reject path
- Replay of used approval token → 422
- AI actor_type on register/approve → 403
- Rollback sets positions_preserved=true

## 28. Phase 17

**NOT STARTED** — stub only (`PHASE_17_CONTRACT.md`).
