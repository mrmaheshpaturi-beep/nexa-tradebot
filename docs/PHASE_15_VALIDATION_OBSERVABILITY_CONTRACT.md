# Phase 15 — Validation, Observability & Production Hardening Contract

**Status: IMPLEMENTED (DEMO / ops only).** LIVE remains hard-disabled. LIVE_AUTO does not exist. LIVE_PRODUCTION environment does not exist.

## Boundary

Phase 15 adds observability, health, alerts, forward validation, data quality gating, backups/DR foundations, circuit breakers, resource monitors, and operational dashboards **without** enabling LIVE trading or weakening Phase 14 DEMO verification / Risk / Execution / Qualification.

## Non-negotiable constraints

1. Phase 15 `order_send` call sites = **0**
2. AI execution call sites = **0**
3. LIVE_AUTO does not exist; refuse LIVE_PRODUCTION
4. Evidence labels DEMO / BACKTEST / WALK_FORWARD / DRY_RUN never mixed
5. Stale CRITICAL heartbeat → block new entries
6. Bad data / clock drift → NO NEW TRADE
7. UNKNOWN execution → RECONCILE not RETRY
8. Watchdog never duplicates orders
9. Drift monitor never auto-disables strategies on noise
10. Readiness scorecard is **operational**, never “safe for real money”
11. Environments: LOCAL | STAGING | DEMO_VPS only
12. NotificationProvider required; paid email/Telegram optional
13. Soak long durations are MANUAL — CI uses short configurable soaks
14. CI never claims Windows reboot executed

## Health APIs

- `GET /api/v1/health`
- `GET /api/v1/health/liveness`
- `GET /api/v1/health/readiness`
- `GET /api/v1/health/trading-readiness` — LIVE always `NOT_READY`

## Explicit non-goals

- LIVE enablement / LIVE_AUTO / Phase 16 implementation
- AI trade authority / auto risk or strategy mutation
