# Phase 11 Report — Advanced Trade & Position Management Engine

## 1. Executive Summary

Phase 11 delivers a DEMO-only TradeManagementEngine on Phase 10 foundations. Nexa-managed positions support break-even, trailing stops, multi-target partial closes, and protective exits behind PositionManagementGate. LIVE modification/partial/full close remain hard-blocked. Foreign positions are never auto-managed. CI uses FakeDemoBridgeClient only. Sole `order_send` remains `trading-engine/src/nexa_mt5/execution.py::authorized_order_send`.

## 2. Phase Status

**PASS WITH WARNINGS**

## 3. Trade Management Architecture

`MT5 DEMO Position → Sync → ManagedPosition → TradeManagementEngine → Policy/Rules → Decision → Safety Revalidation → DEMO Broker Action → Reconciliation → Updated Position`

## 4. Managed Position Model

`ManagedPosition` with ownership, volumes, SL/TP, BE/trail state, MAE/MFE, policy version binding.

## 5. Ownership Detection

`PositionOwnershipService` — only `NEXA_MANAGED` receives broker-changing actions; foreign/manual/other-EA blocked.

## 6. Management Policy

Versioned `TradeManagementPolicy` (BE, trail, partials, exits).

## 7. Policy Versioning

Positions store `management_policy_id` + `management_policy_version`. Migration mode enum: KEEP_ORIGINAL / MIGRATE.

## 8. Management Rule Registry

Modular rules under `App\TradeManagement\Rules\*`.

## 9. Rule Priority

Emergency(10) → Risk(20) → StrategyInvalidation(30) → Time(40) → Session(45) → Partial(50) → BreakEven(60) → Trail(70) → TP(80) → Hold.

## 10. Position Monitor

Bounded-frequency `PositionMonitorService` with cache lock; evaluate preferred.

## 11–17. Break-Even / Trailing

R-multiple BE (never worsen, offset, one-time). Trailing: FIXED_DISTANCE, ATR_BASED, PERCENTAGE, STRUCTURE_BASED with start/step, never loosen, stops-level + precision.

## 18–21. Take-Profit / Partial / Full Close

Multi-target TP1/TP2/TP3; `PartialCloseService` / `PositionCloseService` DEMO-only; volume min/step residual safety; hedging ticket-specific; recon before complete.

## 22–26. Exits

Strategy invalidation, time, session/weekend, risk, emergency, CLOSE ALL (SUPER_ADMIN + confirm + DEMO).

## 27–28. Gate / DEMO Verification

`PositionManagementGate` + `DemoAccountVerifier` + request.account.trade_mode + bridge independent DEMO check.

## 29–30. Idempotency / Serialization

Per-position locks; unique `(user_id, idempotency_key)` on actions.

## 31–33. Reconciliation / Manual MT5 / Restart

UNKNOWN → reconcile, no blind retry; ADOPT/ALERT/REQUIRES_REVIEW; crash recovery service.

## 34–36. Events / Summary / MAE-MFE

Immutable lifecycle events; TradeSummary immutable after finalize; MAE/MFE foundation.

## 37. Database Changes

Additive migration `2026_09_19_180000_create_phase_eleven_trade_management.php` + risk_lock protective flags.

## 38. API Changes

`/api/v1/trade-management/*`, `/api/v1/positions-managed/*` with RBAC `trade_management.*`.

## 39–40. Frontend / Dashboard

`#/trade-management` — metrics, positions, WHY, chart markers, pause/resume, safety badges.

## 41–51. Tests Executed / Results

| Suite | Result |
|---|---|
| Phase 11 PHPUnit | 16/16 passed |
| Phase 9+10+11 regression | 37/37 passed |
| Full Laravel PHPUnit | 139 passed / 1 skipped / 0 failed |
| Break-even / trailing / partial / exits / foreign / LIVE / concurrency / recon / security | Covered in PhaseElevenTradeManagementTest |
| Vitest | 26 passed |
| Python trading-engine | PASS |
| TypeScript | PASS |
| ESLint | PASS (0 errors; 1 pre-existing react-refresh warning) |
| Production build | PASS |
| Phase 11 audit script | PASS |
| Phase 10 audit regression | PASS |

## 52–56. Python / Laravel / TS / ESLint / Build

All PASS (see above).

## 57. DEMO Integration Result

**MANUAL DEMO MANAGEMENT TEST REQUIRED** — no Windows MT5 DEMO terminal; `NEXA_MT5_DEMO_INTEGRATION` not enabled.

## 58. Security Audit

PASS — LIVE hard-blocked multi-layer; Fake bridge in CI; no PHP `order_send`.

## 59. LIVE Execution Audit

**NONE** — LIVE modification / partial / full close HARD BLOCKED.

## 60. Known Limitations

- Real Windows MT5 DEMO management E2E pending
- Metric grid CSS is stacked on some viewports (functional)
- Breadcrumb still labels older phase strings in shell

## 61. Technical Debt

- Optional: claim ManagedPosition automatically on Phase 10 fill path
- Optional: richer chart component for markers

## 62. Files Changed

TradeManagement namespace, migration, enums/models, Fake/HTTP bridge + Python management endpoints, controller/routes/RBAC, React dashboard, tests, docs, audit script, media.

## 63. Manual Actions Required

- Local migrate on disposable DB: `php artisan migrate`
- Enable DEMO: `allow_demo_execution=true` (SUPER_ADMIN)
- Do not enable LIVE; `allow_live_execution` remains locked false
- Do not start Phase 12 without approval

## 64. Phase 12 Contract

`docs/PHASE_12_ANALYTICS_INPUT_CONTRACT.md` (stub only)

## 65. Recommendation for Phase 12

Wait for approval. Do not start Phase 12 from this branch without explicit authorization.

## Preview

- Laravel: http://127.0.0.1:48411
- Vite: http://127.0.0.1:58411
- UI: `#/trade-management`
- Branch: `cursor/phase-11-trade-management-56f9`
- Worktree: `/tmp/nexa-phase11-56f9`
- Media: `media/phase-11/`
