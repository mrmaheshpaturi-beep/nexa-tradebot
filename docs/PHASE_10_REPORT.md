# Phase 10 Report — DEMO-only ExecutionEngine

## Summary

Phase 10 delivers a gated DEMO-only ExecutionEngine on Phase 9 foundations. Simulation remains on `TradeLifecycleService` + `SimulationExecutionAdapter`. DEMO requires two-step manual confirmation, fresh quote/account/spec, Phase 9 risk revalidation, submission locks, and the sole authorized `order_send` path in `trading-engine/src/nexa_mt5/execution.py::authorized_order_send`. LIVE and UNKNOWN account modes hard-fail at multiple layers. Auto Demo defaults OFF (locked). CI uses `FakeDemoBridgeClient` only.

## Branch / worktree

- Branch: `cursor/phase-10-execution-engine-56f9`
- Worktree: `/tmp/nexa-phase10-56f9`
- Base: Phase 9 tip `4e8e339`

## Delivered checklist

| Item | Status |
|---|---|
| DEMO-only ExecutionEngine lifecycle | PASS |
| Immutable intents/results/events once recorded | PASS |
| Deterministic idempotency + DB uniqueness | PASS |
| Execution/submission locks | PASS |
| Actual DEMO verification (trade mode/server/login) | PASS (fake bridge in CI) |
| LIVE / UNKNOWN hard fail (multi-layer) | PASS |
| Adapter / request builder / retcode mapper | PASS |
| Two-step manual confirmation | PASS |
| Auto Demo default OFF | PASS |
| Fresh quote/account/spec + risk revalidation | PASS |
| Price/stop/TP/volume gates | PASS |
| order_check before sole order_send | PASS |
| Reservations consume/release | PASS |
| Timeout/UNKNOWN no blind retry | PASS |
| Crash recovery + reconciliation | PASS |
| Order/deal/position sync | PASS (recon sync + local ledger) |
| Netting/hedging + partial fills | PASS |
| Bridge auth/replay + independent DEMO verify | PASS |
| APIs/RBAC/audit/health/metrics | PASS |
| Execution UI | PASS |
| Migrations (additive) | PASS |
| Docs + Phase 11 contract stub | PASS |
| Safety/test matrix | PASS WITH WARNINGS (real DEMO integration pending) |

## Sole order_send location

`trading-engine/src/nexa_mt5/execution.py::authorized_order_send`

CI / unit tests never call MetaTrader5. Real Windows DEMO path is behind `NEXA_MT5_DEMO_INTEGRATION` / bridge `mode=real`.

## Safety verification

| Control | Result |
|---|---|
| MT5 LIVE EXECUTION | HARD FAIL |
| MT5 DEMO EXECUTION | ENABLED (manual confirm) when `allow_demo_execution=true`; default DISABLED |
| Auto Demo | OFF (locked false) |
| SIMULATION | Unchanged SimulationExecutionAdapter |
| order_send locations | 1 authorized DEMO path |
| Silent mock under DEMO label | Rejected |

## Tests / quality gates

| Gate | Result |
|---|---|
| PHPUnit | 123 passed / 1 skipped / 0 failed |
| Vitest | 24 passed |
| TypeScript (`tsc -b`) | PASS |
| ESLint | PASS (0 errors; 1 pre-existing react-refresh warning) |
| Production build | PASS |
| Phase 10 execution audit | PASS |
| Python trading-engine pytest | PASS |

## REAL DEMO INTEGRATION

**INTEGRATION TEST REQUIRED** — no Windows MT5 DEMO terminal in this environment; `NEXA_MT5_DEMO_INTEGRATION` not enabled.

## Demo preview

- Laravel: `http://127.0.0.1:48410`
- Vite: `http://127.0.0.1:58410`
- UI: `#/demo-execution`

## Phase 11

NOT STARTED (contract only — `docs/PHASE_11_CONTRACT.md`)

## Remaining Phase 10 issues

- Real Windows MT5 DEMO end-to-end validation pending
- Bridge write path validated with Mock/Fake backends in CI
- Netting vs hedging inferred from bridge metadata; broker-specific quirks need live DEMO evidence

## Manual actions required

- Local migrate on disposable SQLite only: `php artisan migrate`
- To enable DEMO: set `allow_demo_execution=true` (SUPER_ADMIN); complete two-step confirm before submit
- Do not enable LIVE; `allow_live_execution` remains locked false
- Do not start Phase 11 implementation from this branch without a new approval
