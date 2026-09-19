# Production Hardening (Phase 19)

Version: `ProductionHardening/v1`

## App vs broker environments

| Axis | Allowed | Forbidden |
|---|---|---|
| App environment | LOCAL, STAGING, DEMO_VPS | LIVE_PRODUCTION, LIVE, … |
| Broker trade mode | SIMULATION, DEMO | LIVE, UNKNOWN, LIVE_AUTO |

Safe defaults keep emergency stop on, live execution false, auto trading false.

## Ops Control Center

UI: `#/ops-control-center`  
API: `/api/v1/hardening/ops`

Separates:
- **Liveness** — process up
- **Readiness** — env/config validation
- **Trading-readiness** — DEMO path only; LIVE always NOT_READY; LIVE_AUTO does not exist

## Scoped safe modes

GLOBAL | PORTFOLIO | ACCOUNT | AUTOMATION | DEPLOY_MAINTENANCE

## Trading-aware deploy

1. Pause new entries / enter maintenance  
2. Drain queues / deploy / migrate  
3. Liveness + readiness  
4. Trading-readiness gate  
5. **Reconcile UNKNOWN before resume** (never blind retry)  
6. Explicit operator resume  

## Queues

DB-backed prioritized queue with idempotency keys and dead-letter. Redis is optional.

## Workers

Graceful shutdown + restart reconciliation required before trading resume.

## Soak / chaos

TEST/DEMO/LOCAL/STAGING only. Never against LIVE. Multi-day soak is manual — not claimed by CI.
