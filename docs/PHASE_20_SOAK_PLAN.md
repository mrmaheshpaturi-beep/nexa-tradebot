# Soak Test Plan — Phase 20

**Framework:** `SoakChaosHarness` (TEST/DEMO only; never LIVE)  
**Agent policy:** Do NOT wait days inside the agent. Record PENDING until elapsed runtime exists.

| Tier | Duration | Goal | Status |
|---|---|---|---|
| S1 | 24h | Stability under DEMO_AUTO dry conditions | PENDING — not elapsed |
| S2 | 72h | Memory/queue/DLQ drift checks | PENDING — not elapsed |
| S3 | 7d | Weekend gap + reconnect behavior | PENDING — not elapsed |

## Pass criteria (when run)

- No LIVE path exercised
- No blind UNKNOWN retries
- Alerts fire on stale data / worker death
- Reconcile required flags remain true after simulated crash
- Evidence logs retained under `docs/evidence/phase20/soak/`

## Current evidence

None elapsed. Framework availability: PASS (Phase 19 tests). Elapsed soak: **PENDING**.
