# MT5 Integration Contract

## Status

Documentation only. Phase 3 contains no MT5 package, process, adapter, endpoint, credential, terminal connection, broker transport, or live execution. The health contract truthfully reports terminal `OFFLINE`, broker `DISCONNECTED`, and broker transmission false.

## Initial Phase 4 scope: read-only

If separately approved, Phase 4 must start read-only. Permitted capabilities:

- report adapter/terminal connectivity and version;
- read account identity metadata without secrets;
- read symbol specifications, quotes and market/session status;
- read balances, margin and positions for reconciliation;
- persist heartbeats and source/freshness metadata;
- compare external read models with the simulation/application ledger.

Explicitly prohibited in the initial phase:

- place, modify or cancel orders;
- open, modify, partially close or close positions;
- enable DEMO or LIVE execution;
- expose terminal passwords/tokens to React;
- let signals, AI, schedules or web requests call MT5;
- reuse the simulation adapter as proof of broker readiness.

## Proposed boundary

```text
Laravel application
  → authenticated, allowlisted read request
  → isolated MT5 read adapter/service
  → terminal on controlled Windows host
  ← normalized read DTO + source timestamp + adapter health
```

The adapter must be separately deployable, least-privileged, deny writes by API design, authenticate both directions, validate schemas, enforce timeouts/rate limits, redact secrets, and emit correlation IDs. Laravel remains the authorization and audit boundary.

## Data contracts

Every response must carry environment (`DEMO` initially if approved), account/terminal identity, source timestamp, received timestamp, freshness classification, adapter version, and correlation ID. Decimal values must remain lossless strings across transport. External ticket IDs must never replace internal public IDs.

Read models should cover terminal health, account snapshot, symbol specification, quote and externally observed order/deal/position. They must remain distinct from Phase 3 simulation records unless a deliberate reconciliation process links them.

## Credentials and host controls

Credentials must be encrypted server-side or held only by the isolated adapter/terminal host, never committed, logged, audited as before/after payload, returned by an API, or exposed through `VITE_` variables. Required design work includes key ownership/rotation, host hardening, network allowlists, service identity, revocation, backup/recovery and incident response.

## Acceptance gates before any write phase

A later MT5 DEMO write phase requires separate approval and must add threat modeling, demo-only account allowlists, authoritative risk approval, emergency kill switches, durable outbox/idempotency semantics, acknowledgement/retry classification, reconciliation, immutable execution audit, clock/freshness controls, failure injection, rollback and an operator runbook.

LIVE/real-money activation is not implied and requires its own governance decision after DEMO evidence.
