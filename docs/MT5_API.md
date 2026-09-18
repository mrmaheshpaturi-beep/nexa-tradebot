# MT5 API Surface

## Laravel (`/api/v1/mt5/*`)

All routes require `auth:sanctum`, active user, and listed permission.

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/mt5/status` | `mt5.read` | Bridge configuration and connection summary |
| GET | `/mt5/bridge/health` | `mt5.read` | Proxy bridge health |
| GET | `/mt5/bridge/terminal` | `mt5.read` | Proxy terminal metadata |
| GET | `/mt5/bridge/account` | `mt5.read` | Proxy account snapshot |
| GET | `/mt5/bridge/symbols` | `mt5.read` | Proxy symbol list |
| GET | `/mt5/bridge/symbols/{symbol}` | `mt5.read` | Proxy symbol spec |
| GET | `/mt5/bridge/quotes/{symbol}` | `mt5.read` | Proxy quote |
| GET | `/mt5/bridge/candles/{symbol}` | `mt5.read` | Proxy candles |
| GET | `/mt5/bridge/positions` | `mt5.read` | Proxy open positions |
| GET | `/mt5/bridge/orders` | `mt5.read` | Proxy open orders |
| GET | `/mt5/bridge/history/orders` | `mt5.read` | Proxy bounded order history |
| GET | `/mt5/bridge/history/deals` | `mt5.read` | Proxy bounded deal history |
| GET | `/mt5/connections` | `mt5.read` | List user connections |
| POST | `/mt5/connections` | `mt5.connections.manage` | Create connection metadata |
| POST | `/mt5/connections/{id}/test` | `mt5.connections.manage` | Test bridge connectivity |
| POST | `/mt5/connections/{id}/sync` | `mt5.sync` | Persist external read models |
| GET | `/mt5/mappings/{id}/positions` | `mt5.read` | Persisted external positions |
| GET | `/mt5/mappings/{id}/orders` | `mt5.read` | Persisted external orders |
| GET | `/mt5/mappings/{id}/deals` | `mt5.read` | Persisted external deals |
| POST | `/mt5/mappings/{id}/reconcile` | `mt5.reconcile` | Report-only reconciliation |
| GET | `/mt5/reconciliation-runs` | `mt5.read` | List reconciliation runs |
| GET | `/mt5/reconciliation-runs/{id}` | `mt5.read` | Reconciliation run detail |

No execution, order placement, or broker write route exists.

## Python bridge (`/v1/*`)

Authenticated GET-only endpoints returning `{ data, meta }` envelopes. See `trading-engine/README.md` and OpenAPI-disabled production surface in `nexa_mt5/api.py`.
