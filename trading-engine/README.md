# Nexa MT5 read-only bridge

Python 3.12+ service for authenticated MetaTrader 5 DEMO reads. It has no trade-write API or execution route. Linux and CI use the deterministic mock connector; the official MetaQuotes package is an optional Windows-only dependency.

## Local mock

```bash
cd trading-engine
python3.12 -m venv .venv
. .venv/bin/activate
pip install -e '.[dev]'
export NEXA_MT5_SERVICE_TOKEN='choose-a-long-random-local-token'
uvicorn nexa_mt5.api:app --host 127.0.0.1 --port 8765 --no-access-log
```

Use `Authorization: Bearer <service token>`. All endpoints are under `/v1`: `health`, `terminal`, `account`, `symbols`, symbol detail, quotes, candles, positions, pending orders, bounded order/deal history, and heartbeat.

## Windows real read mode

Follow `../docs/MT5_WINDOWS_SETUP.md` and `../docs/MT5_CREDENTIALS.md`. Install `.[windows]`, set `NEXA_MT5_MODE=real`, and run `.\start.ps1`. The script binds to `127.0.0.1` unless explicitly configured and does not register startup, alter firewall rules, or deploy the service.

## Verification

```bash
pytest
ruff check .
mypy src
```

The real connector cannot be validated on Linux and remains `PENDING WINDOWS ENVIRONMENT`.
