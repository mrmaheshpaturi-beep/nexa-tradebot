# Phase 17 Contract (stub only)

**Status: NOT IMPLEMENTED.** Do not treat as enabled capability.

Phase 16 ends at Strategy Governance / Release Engineering / Controlled DEMO Promotion.

Phase 17 may address (examples only — not authorized by this stub):
1. Further product capabilities beyond Phase 16 governance
2. Any LIVE-adjacent work remains gated by separate governance

## Non-negotiable constraints carried forward

1. LIVE remains hard-disabled until separate governance
2. LIVE_AUTO must not be introduced without governance
3. AI never calls MT5 or bypasses qualification/risk/execution/management
4. Exactly one authorized `order_send` call site (Phase 10)
5. AI cannot approve, deploy, or change active strategy config
6. Strategy governance / lab never call order_send
7. DEMO-only deployments remain the only promotion path until separate LIVE decision

## Explicit non-goals of this stub

- No LIVE enablement
- No LIVE_AUTO
- No Phase 17 implementation work
