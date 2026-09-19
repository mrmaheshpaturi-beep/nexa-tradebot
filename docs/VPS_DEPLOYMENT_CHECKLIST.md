# VPS Deployment Checklist (DEMO_VPS / STAGING — not LIVE)

1. Confirm `NEXA_OPS_ENVIRONMENT` is `LOCAL` | `STAGING` | `DEMO_VPS` — never `LIVE_PRODUCTION`
2. `.env` is not committed; secrets via env / secret manager
3. Run `GET /api/v1/observability/env` — must be ok
4. Database migrated; backups verified (`POST /api/v1/observability/backup`)
5. MT5 **terminal** required on Windows host for DEMO broker connectivity (Linux VPS app + Windows MT5 terminal is the supported pattern)
6. Startup recovery: services may start; **AUTO DEMO ENTRY remains paused** until explicit resume — never insecure credential auto-login to LIVE
7. Windows service foundation: document service wrappers; do **not** auto-start DEMO_AUTO entry on boot
8. Health probes: `/health`, `/health/liveness`, `/health/readiness`, `/health/trading-readiness`
9. After any restore: **reconcile before new trading**
10. CI uses Fake/Mock adapters only — do not claim Windows reboot was executed in CI
