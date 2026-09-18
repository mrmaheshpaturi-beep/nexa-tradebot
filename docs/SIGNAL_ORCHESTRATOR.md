# Signal Orchestrator

Phase 8 candidate aggregation, prioritization, conflict detection, and queue presentation.

## Non-goals

- Does **not** map candidates to broker orders
- Does **not** call `order_send` or MT5 write APIs
- Does **not** enable DEMO/LIVE execution

## Responsibilities

1. **Ingest** actionable scan evaluations / signals into `signal_candidates`
2. **Rank** by confluence, quality, freshness penalties
3. **Conflict-detect** opposing directions and multi-strategy agreement within `symbol|timeframe`
4. **Lifecycle**: ACTIVE / QUEUED → DISMISSED / INVALIDATED / EXPIRED
5. **Mark for SIMULATE** — flag only (`marked_for_simulate`); target is SIMULATION_ONLY
6. **Present** ranked queue to the dashboard

## APIs

| Method | Path |
|---|---|
| GET | `/api/v1/scanner/queue` |
| GET | `/api/v1/scanner/candidates/{public_id}` |
| POST | `/api/v1/scanner/candidates/{public_id}/dismiss` |
| POST | `/api/v1/scanner/candidates/{public_id}/invalidate` |
| POST | `/api/v1/scanner/candidates/{public_id}/mark-simulate` |
| POST | `/api/v1/scanner/expire-due` |

## Fingerprint

SHA-256 over user, strategy/plugin, symbol, timeframe, direction, candle close key, configuration version. Replays update rank metadata without duplicating rows.

## Alert foundation

`AlertPipelineService` writes `scanner_alert_events` and optional in-app `notifications`. Email/SMS are explicitly NOT implemented (`HOOK` channel records only).
