# Execution Model

Phase 10 extends this document: SIMULATION path unchanged; DEMO uses ExecutionEngine with two-step confirmation. See `EXECUTION_ENGINE.md`.

# Execution Model

## Boundary

Phase 3 execution means mutation of the local simulation ledger. `ExecutionAdapter` is bound only to `SimulationExecutionAdapter`. No MT5, broker, network, credential, demo, live, or real-funds adapter exists.

## Command contract

Every execution mutation creates or replays an `ExecutionCommand` containing:

- user, simulation account, optional intent/position;
- user-scoped idempotency key;
- command type, status and `SIMULATION` environment;
- symbol, side, order type, volume and relevant prices;
- request, acknowledgement, completion or failure timestamps;
- attempt count, safe failure code/message and payload.

Command types are `PLACE_ORDER`, `MODIFY_ORDER`, `CANCEL_ORDER`, `CLOSE_POSITION`, `PARTIAL_CLOSE`, `MODIFY_POSITION_SL`, and `MODIFY_POSITION_TP`. Phase 3 produces all except `MODIFY_ORDER`.

## Gate and transitions

Before a command can mutate the ledger, the server requires:

1. executable environment is exactly `SIMULATION`;
2. account environment is `SIMULATION`;
3. account is enabled;
4. emergency stop is exactly false;
5. `simulation_execution_enabled` is exactly true;
6. a placement intent has one approved risk decision.

Normal command progression is:

```text
CREATED → QUEUED → PROCESSING → ACKNOWLEDGED → COMPLETED
                              ↘ FAILED
```

The adapter rejects non-simulation commands. The application catches controlled adapter failures, persists a safe failure, emits a system event and audit entry, and does not create partial downstream state.

## Deterministic fill model

`MockMarketDataProvider` supplies fixed bid/ask quotes. MARKET BUY fills at ask; MARKET SELL fills at bid. A close executes the opposite side, so a BUY position closes/marks at bid and a SELL closes/marks at ask. Commission, swap and fees remain zero. Profit is:

```text
(close - open) × direction(+1 BUY, -1 SELL) × volume × contract_size
```

Margin is:

```text
volume × contract_size × price × margin_rate ÷ leverage
```

Pending orders are accepted but never triggered. Cancel and protection commands are accepted ledger mutations with no fill price.

## Concurrency and replay

Intent and command idempotency keys are unique per user. Critical resources are reloaded with row locks inside database transactions. A repeated key returns the existing command. State machines prevent terminal records from moving backward.

This provides local replay protection, not distributed exactly-once broker delivery. Phase 4 must not reinterpret it as broker-grade delivery.

## Events and audit

Domain events identify intent creation, risk decisions, command creation, order fills, position opens/modifications/closes. Audit entries cover lifecycle mutations and controlled failures. `PositionEvent` stores before/after state and execution-command linkage. Audit immutability remains application-model enforcement, not database tamper evidence.

## Safety invariants

- The server assigns environment; clients cannot submit it.
- Clients cannot submit `broker_transmitted`.
- `allow_demo_execution` and `allow_live_execution` are hard false.
- `trading_enabled` remains false while simulation execution can be independently enabled.
- No execution code references MT5 or a broker adapter.
