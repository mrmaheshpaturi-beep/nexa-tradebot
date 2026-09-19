# Phase 20 Defect Register

**Updated:** 2026-09-19T16:01:22Z  
**Policy:** Do not hide failing tests. Do not disable tests to obtain PASS.

| ID | Severity | Area | Description | Status | Evidence |
|---|---|---|---|---|---|
| D-20-001 | P2 | Audit script | Phase 5 audit still looked for removed `isExecutable` and treated authorized `order_send` as failure | FIXED | `scripts/phase5-no-execution-audit.sh` |
| D-20-002 | P2 | Python tests | Fresh venv lacked `httpx` for Starlette TestClient (collection error) | FIXED | pip install httpx; pytest 25/25 |
| D-20-003 | P1 | External | Windows MT5 real-terminal validation not run in this environment | OPEN / PENDING MANUAL | — |
| D-20-004 | P1 | External | XM DEMO broker order evidence absent | OPEN / PENDING REAL DEMO TEST | — |
| D-20-005 | P2 | Ops | Multi-day soak (24h/72h/7d) not elapsed in agent | OPEN / PENDING | soak framework only |
| D-20-006 | P2 | DR | Hostinger/VPS production restore drill not performed | OPEN / PENDING MANUAL | local SQLite + logical backup PASS |
| D-20-007 | P3 | Frontend | Chart vendor chunk >500kB advisory | ACCEPTED / KNOWN | build warning |
| D-20-008 | P3 | Lint | react-refresh/only-export-components warning in MarketDataContext | ACCEPTED / KNOWN | eslint |

## Counts

| Severity | Open | Fixed | Accepted |
|---|---|---|---|
| P0 | 0 | 0 | 0 |
| P1 | 2 | 0 | 0 |
| P2 | 2 | 2 | 0 |
| P3 | 0 | 0 | 2 |

## Release impact

No P0 blockers. P1 items are external/manual environment dependencies — they prevent `DEMO_RELEASE_CANDIDATE` but allow `READY_FOR_CONTROLLED_DEMO` when software/safety gates pass.
