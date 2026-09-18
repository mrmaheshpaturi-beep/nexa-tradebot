# Signal Engine

Creates and manages `Signal` lifecycle from strategy evaluations.

## Status

GENERATED → VALID → CONSUMED | EXPIRED | REJECTED | CANCELLED

## Protections

- Duplicate fingerprint (strategy + symbol + TF + direction + candle close key + config version)
- Per-strategy cooldown
- Expiry (`expires_at`)
- Explicit invalidation API
- Source `STRATEGY` (also legacy MOCK/SIMULATION)

## SIMULATE

`POST /signals/{id}/trade-intent` creates a **Simulation** TradeIntent only. Never broker execution.
