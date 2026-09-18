# Phase 4 Architecture — Read-Only MT5 Bridge

## Purpose

Phase 4 adds an external **MT5 DEMO read-only** observation path without changing the Phase 3 simulation execution boundary. SIMULATION remains the only executable environment. No broker writes, DEMO/LIVE execution, or `order_send` usage exists in application-owned code.

## System diagram

```text
Browser / React
  ├─ TradingSourceProvider (SIMULATION | MT5_DEMO READ-ONLY)
  ├─ session/CSRF API client (never bridge token)
  ├─ Phase 3 lifecycle pages — mutations only when source = SIMULATION
  ├─ Phase 4 MT5 pages — Laravel APIs only, no mock fallback in MT5 mode
  └─ retained mock analytical screens
                 │ same-origin /api and /sanctum
Laravel
  ├─ auth:sanctum → active user → permission middleware
  ├─ TradeLifecycleService / SimulationExecutionAdapter (SIMULATION only)
  ├─ Mt5BridgeController — read proxies, connection test, sync, reconcile
  ├─ TradingBridgeClient — retry, timeout, circuit breaker, short cache
  ├─ Mt5ReadModelService — persist external read models + report-only reconcile
  └─ Eloquent read models (mt5_* tables, account_snapshots expansion)
                 │ server-side Bearer token only
Python FastAPI bridge (trading-engine/)
  ├─ MockMT5Connector — Linux/CI deterministic reads
  └─ RealMT5Connector — Windows host only, lazy MetaTrader5 import
                 │ read-only terminal API
MetaTrader 5 DEMO terminal (Windows validation: PENDING WINDOWS ENVIRONMENT)
```

## Layer responsibilities

| Layer | Responsibility | Explicitly not responsible for |
|---|---|---|
| React | Source selection, truthful connectivity UI, read-only MT5 views | Bridge auth, terminal credentials, broker writes |
| Laravel | Authorization, audit, sync/reconcile orchestration, circuit breaker | Direct MT5 package access, order execution |
| Python bridge | Normalized read DTOs, staleness, redacted logs, bearer auth | Order placement/modification/cancel |
| MT5 terminal | External DEMO account state | Application lifecycle or simulation ledger |

## Data flow

1. Operator selects **MT5 DEMO READ-ONLY** in the global source selector.
2. React calls `/api/v1/mt5/*` with the Laravel session only.
3. Read proxies flow through `TradingBridgeClient` to Python `/v1/*` GET endpoints.
4. Authorized sync persists external positions/orders/deals, aliases, snapshots, and sync cursors.
5. Reconciliation compares persisted open positions with a fresh bridge read and stores a report only.

External ticket IDs never replace internal simulation public IDs. See `RECONCILIATION.md` and `DATABASE_SCHEMA.md`.

## Local development (Linux / cloud mock)

Phase 4 automated verification runs on Linux using `MockMT5Connector`. This is not proof of real terminal readiness.

```bash
cd trading-engine
python3 -m pip install -e ".[dev]"
cp .env.example .env
# set SERVICE_TOKEN
python3 -m uvicorn nexa_mt5.api:app --host 127.0.0.1 --port 8765
```

Configure Laravel (`backend/.env`):

```env
TRADING_BRIDGE_URL=http://127.0.0.1:8765
TRADING_BRIDGE_SERVICE_TOKEN=your-local-token
```

Run migrations, seed, start Laravel and Vite as in Phase 3. Use the UI source selector and MT5 Accounts workflow (create → test → enable → sync).

For Windows real-terminal setup see `MT5_WINDOWS_SETUP.md`. For credentials see `MT5_CREDENTIALS.md`. For API inventory see `MT5_BRIDGE_API.md`.

## Health and truth model

`/api/v1/system/status` reports:

- simulation engine readiness (unchanged Phase 3 semantics);
- `mt5_bridge.configured`, `mt5_bridge.state`, `mt5_bridge.mode = READ_ONLY`;
- `execution.broker_transmission = false`, `allow_demo_execution = false`, `allow_live_execution = false`.

Terminal adapter becomes `MT5_READ_ONLY` only when the bridge is configured and the circuit reports `CONNECTED`. React does not relabel unavailable broker capability as healthy.

## Security boundary

- Bridge service token and terminal credentials stay server-side or on the Windows bridge host.
- No `VITE_` bridge variables. No bridge URL in React bundles.
- Python responses and logs redact passwords, tokens, and terminal paths.
- Static audit: `scripts/phase4-no-execution-audit.sh`.

## Persistence overview

Phase 4 migration adds: `mt5_bridge_connections`, `mt5_account_mappings`, `instrument_aliases`, `mt5_external_positions`, `mt5_external_orders`, `mt5_external_deals`, `mt5_sync_cursors`, `mt5_reconciliation_runs`, `mt5_reconciliation_items`, and expands `account_snapshots` with `source`, `environment`, `external_snapshot_id`.

## Deployment boundary

Phase 4 does **not** require public bridge exposure, DNS, firewall rules, or Windows service registration. Real MT5 validation is manual on a controlled Windows host. Phase 5 is out of scope.

## Related documents

| Document | Scope |
|---|---|
| `ARCHITECTURE.md` | Repository-wide overview and Phase 3 summary |
| `MT5_BRIDGE_API.md` | Laravel and Python read API inventory |
| `MT5_WINDOWS_SETUP.md` | Windows real-terminal operator steps |
| `MT5_CREDENTIALS.md` | Secret handling and rotation |
| `MT5_INTEGRATION_CONTRACT.md` | Read-only contract and write-phase gates |
| `RECONCILIATION.md` | Report-only reconciliation semantics |
| `PHASE_4_REPORT.md` | Completion audit and verification results |
