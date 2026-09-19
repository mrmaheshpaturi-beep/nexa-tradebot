# Managed Position

`ManagedPosition` is the ownership boundary for Phase 11. Only `ownership=NEXA_MANAGED` on DEMO may receive broker-changing actions.

Foreign / manual / other-EA positions are classified and never auto-managed (`FOREIGN_IGNORED` / `REQUIRES_REVIEW`).

State machine highlights: DETECTED → ELIGIBLE → MANAGING → PROTECTING → CLOSING → CLOSED; also PAUSED, BLOCKED, ORPHANED, ERROR.
