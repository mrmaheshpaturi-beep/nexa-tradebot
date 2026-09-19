# Broker Fleet Architecture

Phase 18 introduces multi-broker / multi-account fleet controls without duplicating the Phase 10 ExecutionEngine.

## Core components

| Component | Role |
|---|---|
| `BrokerFleetService` | Orchestrator for providers, accounts, portfolios, routing, recon, health, emergencies |
| `Mt5FleetAdapter` | Connector abstraction; verifies DEMO; **no** `order_send` |
| `FleetSafety` | Non-negotiable matrix (AI/route/LIVE_AUTO/copy trading) |
| `ExecutionRoute` | Account-bound idempotent route decision → Phase 10 handoff |
| `AccountFingerprint` | Login/server/mode/currency hash; mismatch → SAFE_MODE |
| `TradingNode` + leases | Split-brain foundation; conflicting lease → SAFE_MODE |

## Execution boundary

```text
Fleet route decision (ROUTED_PHASE10)
  → Phase 10 ExecutionEngineService::submitDemo
    → DemoBridgeClient::checkAndSend
      → trading-engine authorized_order_send  (SOLE site)
```

Phase 18 creates **zero** new order_send paths.

## Safety

- Independent environment verification via `DemoAccountVerifier`
- LIVE / UNKNOWN hard-blocked
- Fingerprint mismatch / foreign positions / split-brain → SAFE_MODE
- AI route / allocate / risk change → refused
- Copy trading → absent
- LIVE_AUTO → does not exist

## UI

- `#/portfolio-command-center` — command center + tabs for connections, wizard, exposure, risk, allocation, routing, reconciliation
- `#/broker-connections` / `#/account-wizard` — aliases into the same surface

## Manual / mock status

- CI and Linux agents use **MOCK** terminals (`endpoint_mode=MOCK`, `FakeDemoBridgeClient`)
- Real Windows MT5 terminal validation: **PENDING MANUAL**
