# State Machines

Model transitions are validated by enum transition maps. Direct database writes can bypass model guards; these are application invariants, not database check constraints.

## Trade intent

```text
DRAFT → PENDING_RISK → RISK_APPROVED → COMMAND_CREATED
                     ↘ RISK_REJECTED
PENDING_RISK → CANCELLED | EXPIRED
RISK_APPROVED → CANCELLED | EXPIRED
```

One intent has at most one risk decision, one signal and one placement command.

## Execution command

```text
CREATED → QUEUED → PROCESSING → ACKNOWLEDGED → COMPLETED
   │         │          │              └→ FAILED
   └─────────┴──────────┴→ FAILED | CANCELLED | EXPIRED
```

Completed and failed commands are terminal.

## Order

```text
CREATED → SUBMITTED → ACCEPTED → FILLED
                         ├→ CANCEL_PENDING → CANCELLED
                         ├→ PARTIALLY_FILLED → FILLED
                         └→ REJECTED | EXPIRED | FAILED
```

Phase 3 MARKET orders go to `FILLED`; typed pending orders stop at `ACCEPTED` until cancelled. `SIMULATED` is retained only as a Phase 2 compatibility status for `/simulation/orders`.

## Position

```text
OPEN → PARTIALLY_CLOSED → PARTIALLY_CLOSED → CLOSED
  └────────────────────────────────────────→ CLOSED
```

SL/TP changes do not change position status. They append typed position events. `CLOSED` is terminal.

## Signal

```text
GENERATED | VALID → CONSUMED
GENERATED | VALID → EXPIRED | REJECTED | CANCELLED
```

Only unexpired BUY/SELL signals in `GENERATED` or `VALID` can create one intent. `NEUTRAL` is never executable.

## Risk decision

Risk decisions are outcomes rather than a multi-step machine:

```text
APPROVED | REJECTED
```

The reason code records the deterministic gate that approved or rejected the intent. A rejected decision creates no execution command.

## Terminal, session and heartbeat

Terminal and service status vocabulary is `UNKNOWN`, `ONLINE`, `DEGRADED`, `OFFLINE`, `ERROR`; the seeded simulation terminal is `OFFLINE`. Trading sessions are schema/domain foundations only and are not opened by a Phase 3 endpoint. Heartbeats are read-only seeded operational records.

## Environment gate

`SIMULATION`, `PAPER`, `DEMO`, and `LIVE` are domain vocabulary. Only `SIMULATION.isExecutable()` is true. PAPER/DEMO/LIVE persistence may exist in future-facing enum vocabulary, but the Phase 3 execution gate rejects all three.
