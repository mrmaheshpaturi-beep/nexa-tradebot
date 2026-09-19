# Operations Runbook

## Daily posture

- LIVE hard-blocked; LIVE_AUTO absent
- Prefer System Operations dashboard (`#/system-operations`)
- Watchdog: LOG → ALERT → DEGRADE → PAUSE_NEW_ENTRIES → SAFE_MODE (never duplicate orders)
- Stale CRITICAL heartbeat blocks new entries
- Bad data quality blocks new trades

## Health

- Liveness: process up
- Readiness: env validation ok
- Trading readiness: DEMO path only; LIVE always NOT_READY

## Incidents

See `INCIDENT_RESPONSE.md`. After DR restore, always reconcile before trading.
