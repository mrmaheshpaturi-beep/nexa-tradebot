# Phase 9 Report — Authoritative RiskEngine

## Summary

Phase 9 delivers the authoritative server-side RiskEngine on Phase 8 foundations. Trade intents are evaluated by versioned rule modules into immutable `RiskDecision` + `ProposedPlan` records with reservations and locks. DEMO/LIVE remain DISABLED. `order_send` remains NONE. Phase 10 is contract-only.

## Branch / worktree

- Branch: `cursor/phase-9-risk-engine-56f9`
- Worktree: `/tmp/nexa-phase9-56f9`
- Base: Phase 8 tip `dd99df3`

## Delivered

1. Authoritative `RiskEngineService` (fail closed)
2. Modular rules (`risk-rules/v1`) with profile/engine version stamps
3. Symbol-aware `PositionSizingService` + `ProposedPlan`
4. Stop / R:R / daily / weekly / drawdown / exposure / correlation / margin / spread / session / loss-streak
5. Risk locks + reservations + idempotent re-evaluation
6. APIs + RBAC (`risk_engine.view|evaluate|lock`) + audit/events/health
7. Risk dashboard UI + retained risk settings page
8. Additive Laravel migration (local/dev)
9. Docs + `PHASE_10_EXECUTION_CONTRACT.md` stub
10. Deterministic PHPUnit + Vitest coverage

## Safety verification

| Control | Result |
|---|---|
| MT5 EXECUTION | DISABLED |
| DEMO EXECUTION | DISABLED |
| LIVE EXECUTION | DISABLED |
| order_send usage | NONE |
| ExecutionGate DEMO/LIVE reject | PASS |
| ProposedPlan broker_routable | always false |
| RiskEngine → MT5 order APIs | NONE |

## Tests / quality gates

| Gate | Result |
|---|---|
| PHPUnit | 114 passed / 1 skipped / 0 failed |
| Vitest | 22 passed |
| TypeScript (`tsc -b`) | PASS |
| ESLint | PASS (0 errors; 1 pre-existing react-refresh warning) |
| Production build | PASS |
| Phase 4 no-execution audit | PASS |
| Python trading-engine pytest | PASS |

## Demo

- Laravel: `http://127.0.0.1:48407`
- Vite: `http://127.0.0.1:58407`
- Walkthrough: `media/phase-9/risk-engine-dashboard.png`, `media/phase-9/system-health-risk-engine.png`

## Remaining Phase 9 issues

- Real MT5 PENDING WINDOWS (unchanged from prior phases)
- Optional ATR stop requires ATR injection (field present; default null)
- News restriction reason code reserved, not implemented as a calendar feed
- System Health subtitle historically Phase 8; Risk Engine card added

## Manual actions required

- Local migrate on disposable SQLite only: `php artisan migrate`
- Do not run `migrate:fresh` against shared/hosted databases
- Do not start Phase 10 implementation from this branch without a new approval

## Phase 10

NOT STARTED (contract only — `docs/PHASE_10_EXECUTION_CONTRACT.md`)
