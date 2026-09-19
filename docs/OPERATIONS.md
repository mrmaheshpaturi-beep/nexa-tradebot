# Operations Runbook

## Daily posture

- LIVE hard-blocked; LIVE_AUTO absent
- Prefer Ops Control Center (`#/ops-control-center`) and System Operations (`#/system-operations`)
- Watchdog: LOG → ALERT → DEGRADE → PAUSE_NEW_ENTRIES → SAFE_MODE (never duplicate orders)
- Stale CRITICAL heartbeat blocks new entries
- Bad data quality blocks new trades
- Scoped safe modes: GLOBAL|PORTFOLIO|ACCOUNT|AUTOMATION|DEPLOY_MAINTENANCE

## Health

- Liveness: process up
- Readiness: env validation ok
- Trading readiness: DEMO path only; LIVE always NOT_READY

## Deploy

See `PRODUCTION_HARDENING.md` and `PHASE_19_OPERATOR_CHECKLIST.md`. Reconcile UNKNOWN before resume.

## Incidents

See `INCIDENT_RESPONSE.md`. After DR restore, always reconcile before trading.
