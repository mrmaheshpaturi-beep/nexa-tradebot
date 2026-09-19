# Phase 18 Contract (stub only)

**Status: NOT IMPLEMENTED.** Do not treat as enabled capability.

Phase 17 ends at Advanced Market Intelligence (extending Phase 13 TradeIntelligenceEngine).

Phase 18 may address (examples only — not authorized by this stub):
1. Further product capabilities beyond Phase 17 advanced intelligence
2. Any LIVE-adjacent work remains gated by separate governance

## Non-negotiable constraints carried forward

1. LIVE remains hard-disabled until separate governance
2. LIVE_AUTO must not be introduced without governance
3. AI never calls MT5 or bypasses qualification/risk/execution/management
4. Exactly one authorized `order_send` call site (Phase 10)
5. AI cannot approve, deploy, or change active strategy config
6. Strategy governance / lab never call order_send
7. Intelligence / advanced intelligence remain advisory/shadow only
8. DEMO-only deployments remain the only promotion path until separate LIVE decision

## Explicit non-goals of this stub

- No LIVE enablement
- No LIVE_AUTO
- No Phase 18 implementation work
