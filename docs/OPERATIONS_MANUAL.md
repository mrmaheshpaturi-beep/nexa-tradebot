# Operations Manual — Nexa TradeBot RC1

Updated: 2026-09-19T16:02:05Z

## Daily operator loop

1. Open Ops Control Center (`#/ops-control-center`)
2. Confirm liveness / readiness / trading-readiness separation
3. Confirm broker mode is DEMO (never LIVE/UNKNOWN)
4. Confirm automation mode is OFF | DRY_RUN | DEMO_AUTO only
5. Review alerts and DLQ depth
6. Confirm SAFE_MODE / emergency stop posture before any DEMO_AUTO

## Safe modes

- Global / portfolio / account scoped safe modes via OCC + Fleet
- Emergency stop blocks new entries
- After restore: **reconcile before trading** (never auto-resume)

## Deploy / maintenance

Use trading-aware deploy (`TradingAwareDeployService`): pause DEMO entry → drain → deploy → health → reconcile → explicit resume.

## Incidents

Follow `INCIDENT_RESPONSE.md` and `SECURITY_OPERATIONS.md`. UNKNOWN execution states → reconcile only (no blind `order_send` retry).
