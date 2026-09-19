# Trade Management Engine

Phase 11 DEMO-only engine that manages **Nexa-owned** positions after Phase 10 DEMO fill.

```
MT5 DEMO Position → Sync → ManagedPosition → TradeManagementEngine
  → Policy + Rules → Decision → Safety Revalidation → DEMO Broker Action
  → Reconciliation → Updated Position
```

Decisions: HOLD, MOVE_STOP, MOVE_BREAK_EVEN, TRAIL_STOP, UPDATE_TP, PARTIAL_CLOSE, FULL_CLOSE, CANCEL_PENDING, NO_ACTION, BLOCKED.

Rule priority (deterministic): Emergency(10) → Risk(20) → StrategyInvalidation(30) → Time(40) → Session(45) → Partial(50) → BreakEven(60) → Trail(70) → TP(80) → Hold.

LIVE / REAL / UNKNOWN / AMBIGUOUS → zero broker-changing actions. Sole `order_send`: `nexa_mt5.execution.authorized_order_send`.
