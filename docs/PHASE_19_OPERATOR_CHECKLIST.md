# Phase 19 Operator Checklist

## Daily

- [ ] Open `#/ops-control-center` — confirm liveness ALIVE, readiness READY, LIVE NOT_READY
- [ ] Confirm no unexpected scoped safe modes (or intentional ones documented)
- [ ] Confirm emergency_stop / kill posture understood
- [ ] Review alerts / incidents (Phase 15 Alert Center)
- [ ] Confirm LIVE_AUTO still absent

## Before deploy

- [ ] Enter maintenance via hardening deploy API
- [ ] Pause AUTO DEMO entries
- [ ] Drain SAFETY/OPS queues
- [ ] Register version + activate
- [ ] Health: liveness / readiness / trading-readiness
- [ ] Reconcile any UNKNOWN executions (never blind retry)
- [ ] Explicit resume only after reconcile

## After incident / restore

- [ ] Isolated restore verify (or verified backup)
- [ ] Reconcile broker vs app
- [ ] Confirm DEMO account verification
- [ ] Clear safe modes only with operator intent
- [ ] Do not auto-resume

## Manual / infra (do not mark PASS in CI)

- [ ] Windows MT5 real terminal validation
- [ ] Multi-day DEMO soak
- [ ] VPS / Hostinger restore drill
