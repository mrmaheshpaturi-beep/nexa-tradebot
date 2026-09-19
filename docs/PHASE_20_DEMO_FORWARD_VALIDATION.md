# DEMO Forward Validation — Phase 20

**Policy:** Force no fake trades. Do not claim DEMO orders without broker evidence.

| Step | Description | Status |
|---|---|---|
| 1 | Windows MT5 DEMO login verified | PENDING |
| 2 | Bridge health read-only OK | PENDING (Windows) |
| 3 | Account trade_mode = DEMO verified | PENDING |
| 4 | Risk approve → Execution confirm → order_check → authorized_order_send | PENDING REAL DEMO TEST |
| 5 | Broker ticket / deal id persisted | PENDING (no evidence) |
| 6 | Reconciliation matches broker | PENDING |
| 7 | Partial close / SLTP via sole path | PENDING |
| 8 | SAFE_MODE / pause stops new entries | Covered in automated tests (non-broker) |

**Claimed DEMO fills this run:** **0**  
**Status:** **PENDING REAL DEMO TEST**
