# MT5 Read-Only Bridge Setup

## Scope

Local developer and Windows-host setup only. This document does **not** authorize production deployment, public DNS, firewall exposure, or automated startup on shared infrastructure.

## Components

1. `trading-engine/` — Python 3.12 FastAPI read-only bridge
2. Laravel `TRADING_BRIDGE_*` configuration — server-side client
3. React MT5 pages — consume Laravel APIs only

## Linux / cloud development

Use the mock connector (default):

```bash
cd trading-engine
python3 -m pip install -e ".[dev]"
cp .env.example .env
# set SERVICE_TOKEN
python3 -m uvicorn nexa_mt5.api:app --host 127.0.0.1 --port 8765
```

Set matching values in `backend/.env`:

```env
TRADING_BRIDGE_URL=http://127.0.0.1:8765
TRADING_BRIDGE_SERVICE_TOKEN=your-local-token
```

## Windows real terminal validation (manual)

**Status: PENDING WINDOWS ENVIRONMENT**

1. Install MetaTrader 5 and log into a DEMO account on the controlled host.
2. Install Python 3.12 and `pip install -e ".[windows]"` from `trading-engine/`.
3. Configure `.env` with `CONNECTOR_MODE=real`, `SERVICE_TOKEN`, and optional `LOGIN`/`PASSWORD`/`SERVER`.
4. Run `powershell -ExecutionPolicy Bypass -File start.ps1`.
5. Configure Laravel with the same service token and reachable bridge URL (localhost or private network only).
6. In the UI: create MT5 connection → test → enable → sync → reconcile.

## Safety checks

- Bridge `/v1/health` must report `read_only: true`.
- Laravel `/api/v1/system/status` must keep `execution.broker_transmission: false`.
- No bridge route accepts order placement or modification.
