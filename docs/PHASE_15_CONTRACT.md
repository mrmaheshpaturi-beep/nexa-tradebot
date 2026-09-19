# Phase 15 Contract (stub only)

**Status: NOT IMPLEMENTED.** Do not treat as enabled capability.

## Boundary

Phase 14 ends at Automated DEMO Trading Orchestrator (`OFF` | `DRY_RUN` | `DEMO_AUTO`). LIVE_AUTO does not exist. Phase 14 never calls `order_send` directly.

Phase 15 may address (examples only):

1. Operational hardening / observability beyond DEMO automation
2. Notification delivery channels (email/Telegram) still behind explicit ops approval
3. Controlled deployment evidence — LIVE remains hard-disabled

## Non-negotiable constraints carried forward

1. LIVE remains hard-disabled until separate governance.
2. LIVE_AUTO must not be introduced without governance.
3. AI never calls MT5 or bypasses qualification/risk/execution/management.
4. Exactly one authorized `order_send` call site (Phase 10 execution module).
5. No auto-changing risk limits or strategy code without explicit operator workflow.
6. Evidence labels remain strictly separate.

## Explicit non-goals of this stub

- No LIVE enablement
- No LIVE_AUTO
- No Phase 15 implementation work
