# Phase 20 Contract — Final Validation / DEMO Certification / Release Candidate

**Status: IMPLEMENTED (validation phase).** Feature freeze. No Phase 21. No LIVE/LIVE_AUTO.

## Purpose

Re-validate Phases 1–19, produce release evidence, and assign exactly one final status:

`NOT_READY` | `READY_FOR_CONTROLLED_DEMO` | `DEMO_RELEASE_CANDIDATE`

## Non-negotiable constraints

1. LIVE remains hard-disabled
2. LIVE_AUTO must not be introduced
3. AI never calls MT5 or bypasses qualification/risk/execution/management
4. Exactly one authorized `order_send` call site (Phase 10)
5. AI cannot approve, deploy, route, allocate, or change risk / active strategy config
6. Fleet routing remains account-bound into Phase 10 only — no copy trading
7. Independent account environment verification remains mandatory
8. DEMO-only deployments remain the only promotion path until separate LIVE decision
9. UNKNOWN execution → reconcile, never blind retry
10. Soak/chaos never against LIVE
11. Do not fabricate DEMO broker evidence
12. Do not claim soak PASS without elapsed runtime
13. Do not create LIVE_READY / REAL_MONEY_READY / PROFIT_CERTIFIED

## Deliverables

- `PHASE_20_FINAL_REPORT.md` (60 sections)
- `PHASE_1_TO_19_AUDIT.md`
- `PHASE_20_DEFECT_REGISTER.md`
- Evidence under `docs/evidence/phase20/`
- Checklists: INSTALLATION, OPERATIONS_MANUAL, TROUBLESHOOTING, FINAL_SECURITY_CHECKLIST, DEMO_RELEASE_CHECKLIST
- `scripts/phase20-final-validation-audit.sh`

## Current decision

See final report — **READY_FOR_CONTROLLED_DEMO** (software/safety PASS; XM DEMO/soak/VPS PENDING).
