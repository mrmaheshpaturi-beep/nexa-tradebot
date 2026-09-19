# Execution Engine

## Scope

Phase 10 introduces `ExecutionEngineService` for **DEMO** only. SIMULATION continues to use `TradeLifecycleService` + `SimulationExecutionAdapter`.

```text
DEMO Intent → Risk APPROVED → Confirm(1) → Confirm(2)
  → submission lock → fresh verify/quote/spec → price gates
  → order_check → authorized_order_send
  → Order / Deal / Position + reservation consume
```

## Components

| Component | Role |
|---|---|
| `ExecutionGate` | SIMULATION + gated DEMO; LIVE/PAPER/UNKNOWN hard fail |
| `DemoAccountVerifier` | trade_mode/server/login verification |
| `ExecutionConfirmationService` | Two-step manual confirm; Auto Demo OFF |
| `ExecutionSubmissionLockService` | Per-intent submit lock |
| `ExecutionPriceGate` | Volume/price/SL/TP gates |
| `Mt5OrderRequestBuilder` | Centralized request build |
| `Mt5RetcodeMapper` | Retcode → outcome (no blind retry on UNKNOWN) |
| `FakeDemoBridgeClient` | CI default; never hits MT5 |
| `TradingBridgeDemoClient` | Optional HTTP write client |
| `ExecutionReconciliationService` | Order/deal/position sync |
| `ExecutionCrashRecoveryService` | UNKNOWN recovery without re-send |

## Sole order_send

`trading-engine/src/nexa_mt5/execution.py::authorized_order_send`

Must be preceded by successful `order_check` and independent DEMO verification on the bridge.

## Immutability

`ExecutionResult` and `ExecutionEvent` reject updates/deletes after create.

## Settings

| Key | Default | Notes |
|---|---|---|
| `allow_demo_execution` | false | Unlockable |
| `auto_demo_execution` | false | Locked false |
| `allow_live_execution` | false | Locked false |

## APIs

- `GET /api/v1/execution/health`
- `GET /api/v1/execution/dashboard`
- `POST /api/v1/execution/intents/{id}/confirmations`
- `POST /api/v1/execution/confirmations/{id}/step2`
- `POST /api/v1/execution/demo/submit`
- `POST /api/v1/execution/commands/{id}/recover`
- `POST /api/v1/execution/reconcile`
