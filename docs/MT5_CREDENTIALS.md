# MT5 Credentials and Secret Handling

## Principles

- Terminal login/password and bridge service tokens never belong in React, git, audit payloads, or `VITE_` variables.
- Laravel stores only bridge URL/token in server environment configuration.
- Python bridge reads terminal credentials from its local `.env` on the Windows host when `CONNECTOR_MODE=real`.
- API responses and structured logs redact paths, passwords, and tokens.

## Laravel variables

| Variable | Purpose |
|---|---|
| `TRADING_BRIDGE_URL` | Internal bridge base URL |
| `TRADING_BRIDGE_SERVICE_TOKEN` | Bearer token for bridge authentication |

## Python variables

| Variable | Purpose |
|---|---|
| `SERVICE_TOKEN` | Required bearer token |
| `CONNECTOR_MODE` | `mock` (default) or `real` |
| `LOGIN` / `PASSWORD` / `SERVER` | Optional real-terminal bootstrap |
| `TERMINAL_PATH` | Optional explicit terminal binary path |

## Rotation

Rotate `SERVICE_TOKEN` on both Laravel and the bridge host together. Revoke old tokens before decommissioning a host.

## React boundary

The browser never receives bridge tokens or terminal passwords. All MT5 reads pass through authenticated Laravel session APIs.
