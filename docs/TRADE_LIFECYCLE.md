# Trade Lifecycle

## Scope

Phase 3 implements a persistent, simulation-only lifecycle:

```text
optional Signal
  → TradeIntent
  → RiskDecision
  → ExecutionCommand
  → Order
  → Deal
  → Position
  → PositionEvent(s)
  → AccountSnapshot(s)
```

These records are not interchangeable. A signal is analysis, an intent is a request, a risk decision is an approval or rejection, a command is an idempotent execution instruction, an order tracks acceptance/fill/cancellation, a deal is a fill, and a position is current exposure.

## Manual MARKET flow

1. `POST /api/v1/trade-intents` persists a `DRAFT`, then transitions it to `PENDING_RISK`.
2. `POST /trade-intents/{id}/evaluate` creates one immutable decision and moves the intent to `RISK_APPROVED` or `RISK_REJECTED`.
3. Only an approved intent can be sent to `POST /trade-intents/{id}/execute`.
4. The execution gate requires the intent and account to be `SIMULATION`, the account enabled, emergency stop false, and `simulation_execution_enabled` true.
5. The simulation adapter accepts a MARKET command and deterministically fills BUY at ask or SELL at bid.
6. The order advances `CREATED → SUBMITTED → ACCEPTED → FILLED`.
7. One entry deal and one open position are created. No external identifier or broker transmission is produced.
8. The position is marked at the close side of the mock quote: BUY at bid and SELL at ask. The snapshot therefore includes spread P/L.

`trading_enabled` remains false and is not used to grant simulation lifecycle execution. It cannot enable a broker path because no broker adapter exists.

## Protection geometry

Initial MARKET protection is evaluated against the expected entry: ask for BUY and bid for SELL. Pending-order protection is evaluated against `requested_entry`.

| Side | Valid stop loss | Valid take profit |
|---|---|---|
| BUY | below entry/reference | above entry/reference |
| SELL | above entry/reference | below entry/reference |

For an open position, modifications use the current close-side mock quote: bid for BUY and ask for SELL. A null value removes that protection. SL and TP are modified through separate commands. Instrument digits, tick size, volume bounds/step, and minimum stop distance are returned to the UI; the seeded minimum stop distance is zero.

The deterministic EURUSD quote is bid `1.10000`, ask `1.10020`. Thus BUY `SL=1.09, TP=1.12` is valid, while BUY `SL=1.04, TP=1.06` is rejected because TP is below the ask entry.

## Pending orders

`BUY_LIMIT`, `SELL_LIMIT`, `BUY_STOP`, and `SELL_STOP` must match their side. The simulation adapter leaves them `ACCEPTED`; it has no trigger/fill scheduler. An accepted pending order can transition `ACCEPTED → CANCEL_PENDING → CANCELLED` through an idempotent cancel command. It creates no deal or position.

## Position management

- Partial close validates the instrument volume bounds and step, creates an opposite MARKET closing order and `PARTIAL_EXIT` deal, reduces current volume/margin, recalculates unrealized P/L, and appends `PARTIALLY_CLOSED`.
- Full close creates an `EXIT` deal, sets volume/margin/unrealized P/L to zero, records realized P/L, and transitions to `CLOSED`.
- SL and TP changes create separate execution commands and position events.
- Each successful open, protection change, partial close, or full close captures an account snapshot.
- Closed positions cannot be modified or reopened by the state-transition API.

## Signal flow

Only a current user's unexpired `GENERATED` or `VALID` SIMULATION signal with BUY/SELL direction can create an intent. The intent inherits direction, instrument, strategy and available price references. The signal becomes `CONSUMED`. This endpoint creates an intent only; risk evaluation and execution remain explicit later actions.

## Idempotency and failures

Intent keys and command keys are unique per user. Replaying the same key returns the existing resource without another downstream mutation. Adapter failures produce a failed command and safe system/audit records without an order or position. State transitions are guarded against reversal.

## Intentional omissions

There is no broker transport, MT5 adapter, pending trigger engine, real quote stream, automatic signal execution, trailing stop engine, break-even engine, reconciliation scheduler, or PAPER/DEMO/LIVE execution.
