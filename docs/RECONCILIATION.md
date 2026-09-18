# MT5 Reconciliation (Report-Only)

## Purpose

Compare persisted MT5 external position projections with a fresh bridge read. This is observability for operators, not an execution or auto-correction mechanism.

## Flow

1. Operator enables and syncs an MT5 connection (`POST /api/v1/mt5/connections/{id}/sync`).
2. Authorized user runs reconciliation (`POST /api/v1/mt5/mappings/{id}/reconcile`).
3. Laravel fetches current bridge positions, compares against `mt5_external_positions` with status `OPEN`, and stores a `mt5_reconciliation_runs` record with per-item `MATCHED` or `MISMATCH` rows.

## Status codes

| Run status | Meaning |
|---|---|
| `MATCHED` | All compared positions aligned on symbol/volume |
| `MISMATCHES_FOUND` | At least one mismatch or missing projection |

## Item reason codes

- `VALUE_MISMATCH_OR_MISSING_SOURCE` — stored and live records disagree
- `MISSING_PROJECTION` — live position not present in local projection

## Boundaries

- Does not place, modify, or cancel broker orders.
- Does not mutate simulation ledger positions.
- Does not imply DEMO/LIVE execution readiness.
