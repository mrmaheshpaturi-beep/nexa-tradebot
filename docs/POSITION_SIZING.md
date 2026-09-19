# Position Sizing

`PositionSizingService` produces **proposals only**.

## Inputs

- TradeIntent requested volume / risk percent / SL / TP / entry
- TradingInstrument specs: digits, contract size, tick size/value, volume min/max/step, min stop
- Account equity/balance/leverage from snapshot (SIMULATION or read-only account data)
- RiskProfile max risk %, max lot, sizing_enabled

## Algorithm

1. Resolve entry from intent or quote (BUY=ask, SELL=bid).
2. If sizing enabled and stop present:  
   `volume = (equity × risk%) / (stop_distance × contract_size)`
3. Clamp to instrument step/min/max and profile max lot.
4. Prefer the safer of sized vs requested volume.
5. Compute risk amount, risk %, R:R, required margin.

No hard-coded pip assumptions. Precision uses instrument digits/tick/contract fields.

## Output

Stored on `ProposedPlan` with `sizing_breakdown` evidence and `broker_routable=false`.
