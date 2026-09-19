# Phase 20 Contract (stub only)

**Status: NOT IMPLEMENTED.** Do not treat as enabled capability.

Phase 19 ends at Production Hardening / Security / Ops / DR / Trading-Aware Deploy.

Phase 20 may address (examples only — not authorized by this stub):
1. Further product capabilities beyond Phase 19 hardening
2. Any LIVE-adjacent work remains gated by separate governance

## Non-negotiable constraints carried forward

1. LIVE remains hard-disabled until separate governance
2. LIVE_AUTO must not be introduced without governance
3. AI never calls MT5 or bypasses qualification/risk/execution/management
4. Exactly one authorized `order_send` call site (Phase 10)
5. AI cannot approve, deploy, route, allocate, or change risk / active strategy config
6. Fleet routing remains account-bound into Phase 10 only — no copy trading
7. Independent account environment verification remains mandatory
8. DEMO-only deployments remain the only promotion path until separate LIVE decision
9. UNKNOWN execution → reconcile, never blind retry
10. Soak/chaos never against LIVE

## Explicit non-goals of this stub

- No LIVE enablement
- No LIVE_AUTO
- No Phase 20 implementation work
