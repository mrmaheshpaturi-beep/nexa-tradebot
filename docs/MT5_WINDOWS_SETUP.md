# MT5 Windows Setup — Real Terminal Read Validation

## Status

**PENDING WINDOWS ENVIRONMENT.** Linux and cloud agents validate only the mock connector. This document describes the manual operator workflow for real MT5 DEMO read validation on a controlled Windows host.

## Scope

Windows-host setup only. This document does **not** authorize production deployment, public DNS, firewall exposure, service auto-start, or Phase 5 work.

## Prerequisites

1. Controlled Windows host with MetaTrader 5 installed and logged into a **DEMO** account.
2. Python 3.12+ with pip.
3. Network path from the Laravel host to the bridge bind address (localhost or private LAN only).
4. Matching `SERVICE_TOKEN` in both bridge `.env` and Laravel `TRADING_BRIDGE_SERVICE_TOKEN`.

## Install bridge dependencies

```powershell
cd trading-engine
python -m pip install -e ".[windows]"
copy .env.example .env
```

Configure `.env`:

```env
SERVICE_TOKEN=<long-random-shared-secret>
CONNECTOR_MODE=real
# optional when terminal is already logged in:
# LOGIN=
# PASSWORD=
# SERVER=
# TERMINAL_PATH=
```

See `MT5_CREDENTIALS.md` for secret handling. Never commit `.env`.

## Start the bridge (manual, local bind)

```powershell
powershell -ExecutionPolicy Bypass -File .\start.ps1
```

Default bind: `127.0.0.1:8765`. The script does not register Windows startup, alter firewall rules, or deploy the service.

Verify:

```powershell
curl -H "Authorization: Bearer <SERVICE_TOKEN>" http://127.0.0.1:8765/v1/health
```

Expect `data.read_only: true` and `meta.environment: DEMO`.

## Configure Laravel

On the application host (`backend/.env`):

```env
TRADING_BRIDGE_URL=http://<windows-bridge-host>:8765
TRADING_BRIDGE_SERVICE_TOKEN=<same-secret-as-bridge>
```

Use a private address only. Do not expose the bridge to the public internet without a separate security review.

## UI validation workflow

1. Sign in to Nexa TradeBot with a role that has `mt5.read` (and sync/reconcile as needed).
2. Set global source selector to **MT5 DEMO READ-ONLY**.
3. Open **MT5 Accounts** → create connection metadata.
4. **Test** connection (requires `mt5.connections.manage`).
5. **Enable** the connection, then **Sync** (requires `mt5.sync`).
6. Review **MT5 Dashboard**, **Market**, **Charts**, and **Read Models**.
7. Run **Reconciliation** (requires `mt5.reconcile`) and archive the report.

## Safety checks before sign-off

| Check | Expected |
|---|---|
| Bridge `/v1/health` | `read_only: true` |
| Laravel `/api/v1/system/status` | `execution.broker_transmission: false` |
| Any bridge route | GET only; no order write endpoints |
| React network tab | No direct calls to bridge URL; no secrets in responses |

## Evidence to record

- Bridge health JSON (redact token).
- Sync summary counts (symbols, positions, orders, deals).
- Reconciliation run status and mismatch items (if any).
- Screenshot or export showing MT5 DEMO source badge and read-only safety strip.

## Local mock alternative

For Linux/CI development without a Windows terminal, use the mock connector documented in `PHASE_4_ARCHITECTURE.md` § Local development. Mock reads are not broker readiness proof.
