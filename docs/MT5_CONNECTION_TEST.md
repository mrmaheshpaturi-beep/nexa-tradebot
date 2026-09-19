# MT5 Connection Test (READ-ONLY)

## Status

**Cloud/Linux agent run: FAIL for live terminal attach** (expected).  
**READ-ONLY guards: ENABLED** (verified by diagnostic).  
**order_send calls during diagnostic: 0**.

Real XM DEMO terminal validation must be run on the **Windows PC** where MetaTrader 5 is already logged into the XM DEMO account.

## Safety contract

| Rule | Status |
|---|---|
| No `mt5.order_send()` in diagnostic | Enforced |
| No open/close/modify / SL-TP / pending | Enforced |
| No test trade | Enforced |
| No DEMO AUTO / LIVE enablement | Enforced |
| No credential hard-coding / commit / log secrets | Enforced |
| Defaults | `MT5_EXECUTION_ENABLED=false`, `TRADING_MODE=READ_ONLY` |

Broker-changing paths go through `nexa_mt5.safety.assert_broker_mutation_allowed()` and are rejected while `TRADING_MODE=READ_ONLY` or `MT5_EXECUTION_ENABLED=false`. Wired into `authorized_order_send`, `execute_demo_check_and_send`, and `execute_demo_management_action`.

## What this agent verified

| Check | Result on Linux cloud agent |
|---|---|
| Python installed | PASS (3.12.x) |
| MetaTrader5 package | FAIL — not installable / not functional on Linux |
| Platform Windows | FAIL — agent OS is Linux |
| MT5 terminal / XM session | FAIL — no local `terminal64.exe` |
| Account / balance / equity | FAIL — no terminal |
| Symbols / live ticks / H1 candles | FAIL — no terminal |
| READ-ONLY mode | ENABLED |
| Order execution | DISABLED |
| `order_send` calls | 0 |

## Architecture reused

- Python bridge: `trading-engine/src/nexa_mt5/` (`RealMT5Connector`, `MockMT5Connector`, `MT5ReadService`)
- Safety module: `trading-engine/src/nexa_mt5/safety.py` (new)
- Sole authorized send site (still gated): `nexa_mt5.execution.authorized_order_send`
- Laravel MT5 read models / bridge client (Phase 4+) unchanged for this test
- `.env` remains gitignored; `.env.example` documents fail-closed defaults

## Run on the Windows PC (manual)

Prerequisites:

1. MetaTrader 5 installed and **already logged into XM DEMO** (preferred — no password required for session attach).
2. Python 3.12+ on that Windows host.
3. From the repo:

```powershell
cd <repo>
python -m pip install MetaTrader5
# optional editable bridge deps:
# cd trading-engine; python -m pip install -e ".[windows]"

$env:MT5_EXECUTION_ENABLED = "false"
$env:TRADING_MODE = "READ_ONLY"
python scripts\test_mt5_connection.py
```

Session-first attach: the script calls `mt5.initialize()` without login/password when the terminal is already authenticated. Optional env (never commit):

- `NEXA_MT5_TERMINAL_PATH` / `MT5_TERMINAL_PATH`
- `NEXA_MT5_LOGIN`, `NEXA_MT5_PASSWORD`, `NEXA_MT5_SERVER` (only if session attach fails)

### Likely `terminal64.exe` locations

If auto-discovery fails, check:

- `C:\Program Files\MetaTrader 5\terminal64.exe`
- `C:\Program Files (x86)\MetaTrader 5\terminal64.exe`
- `C:\Program Files\XM MT5\terminal64.exe`
- `C:\Program Files\XM Global MT5\terminal64.exe`
- `%APPDATA%\MetaQuotes\Terminal\*\terminal64.exe`

## Expected PASS matrix (Windows + XM DEMO logged in)

```
MT5 TERMINAL: PASS
XM CONNECTION: PASS
ACCOUNT DETECTED: PASS
ACCOUNT ENVIRONMENT: DEMO
BALANCE READ: PASS
EQUITY READ: PASS
SYMBOLS: 7/7 (or available subset)
LIVE TICKS: PASS
H1 CANDLES: PASS
READ-ONLY MODE: ENABLED
ORDER EXECUTION: DISABLED
order_send CALLS: 0
```

## Do not do next without approval

- Do **not** place a test trade
- Do **not** start DEMO AUTO
- Do **not** set `MT5_EXECUTION_ENABLED=true` to force a PASS
- Do **not** enable LIVE

## Related docs

- `docs/MT5_WINDOWS_SETUP.md` — bridge service on Windows
- `docs/MT5_CREDENTIALS.md` — secret handling
- `docs/MT5_BRIDGE_API.md` — read API contract
