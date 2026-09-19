# Performance Baseline — Phase 20

Measured: 2026-09-19T16:02:05Z (local SQLite agent environment)

| Metric | Value | Notes |
|---|---|---|
| OCC dashboard resolve | 37.92 ms | `OpsControlCenterService::dashboard` |
| RiskEngineService resolve | 1.18 ms | container resolve only |
| PHPUnit wall time | ~16.2 s | 250 tests |
| Vitest wall time | ~4.7 s | 27 tests |
| Vite production build | ~543 ms | chart chunk advisory |
| pytest | <2 s | 25 tests |

These are **local baselines**, not production SLOs. Re-measure on target VPS before capacity claims.
