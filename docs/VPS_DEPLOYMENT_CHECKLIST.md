# VPS Deployment Checklist (DEMO_VPS / STAGING — not LIVE)

1. Confirm `NEXA_OPS_ENVIRONMENT` is `LOCAL` | `STAGING` | `DEMO_VPS` — never `LIVE_PRODUCTION`
2. Confirm broker trade mode is `SIMULATION` | `DEMO` — never LIVE/UNKNOWN/LIVE_AUTO
3. `.env` is not committed; secrets via env / secret manager; review `/api/v1/hardening/secrets`
4. Run `POST /api/v1/hardening/environments/validate` — must be ok
5. Database migrated; backups verified (`POST /api/v1/hardening/backup` / observability backup)
6. MT5 **terminal** required on Windows host for DEMO broker connectivity (Linux VPS app + Windows MT5 terminal is the supported pattern)
7. Startup recovery: services may start; **AUTO DEMO ENTRY remains paused** until explicit resume — never insecure credential auto-login to LIVE
8. Windows service foundation: document service wrappers; do **not** auto-start DEMO_AUTO entry on boot
9. Health probes: `/health`, `/health/liveness`, `/health/readiness`, `/health/trading-readiness`
10. Trading-aware deploy: maintenance → activate → reconcile → explicit resume (`/api/v1/hardening/deploy`)
11. After any restore: **reconcile before new trading** (isolated restore harness available)
12. CI uses Fake/Mock adapters only — do not claim Windows reboot / multi-day soak / VPS restore was executed in CI
