# Trading Domain

## Implemented concepts

- **User:** authenticated operator with an `ACTIVE`, `SUSPENDED`, or `DISABLED` status and one or more database roles. The shipped administration workflow assigns one role.
- **Broker Account:** user-owned, credential-free metadata with an optional risk profile. Phase 2 forces `SIMULATION`, starts disconnected, accepts only `NONE` or `SIMULATION` platform metadata, and has no adapter or connection behavior.
- **Strategy:** user-owned, versionable manual or signal-only configuration. Each configuration update can append a `StrategyVersion`; arbitrary keyed JSON can exist in `StrategySetting`. A strategy can produce signals but cannot execute. `auto_trading_enabled` is always forced false.
- **Signal:** analytical data with direction, score, timeframe, price levels, source, expiry, explanation, and optional strategy. The table exists, but Phase 2 has no signal ingestion or list/create API; displayed AI signals remain deterministic mocks.
- **Order:** an instruction record. The only implemented order mutation is `POST /api/v1/simulation/orders`, which writes a user-owned `SIMULATED` record with `simulated=true` and `broker_transmitted=false`. It requires a globally unique UUID command ID and user-scoped idempotency key.
- **Deal:** a discrete fill linked to an order and optionally a position/account. The table and model exist; Phase 2 creates no deals and exposes no deal API.
- **Position:** current exposure linked optionally to an opening order/account. It is distinct from an order. The table and model exist; Phase 2 creates no positions and displayed positions are mocks.
- **Trade:** user-owned reporting projection linked optionally to a position, with entry/exit, P/L, and lifecycle timestamps. The table exists; Phase 2 has no trade API and displayed history is mock data.
- **Risk Profile:** user-owned set of 13 limits: risk per trade, lot size, daily loss, weekly loss, drawdown, open-position count, open risk, trades/day, consecutive losses, minimum margin level, spread, slippage, and minimum reward/risk. Profiles persist, but no authoritative risk-evaluation engine exists.
- **Risk Event:** decision record linked optionally to a risk profile/order. The table exists but no engine or API creates/exposes it.
- **Account Snapshot:** point-in-time persisted account balance, equity, margin, free margin, margin level, floating P/L, and drawdown. The dashboard reads the latest snapshot; there is no snapshot mutation/list API.
- **Notification:** user-owned persistent message with category, severity, payload, and read state. Individual read mutation exists; mark-all does not.
- **Audit Log:** append-only application activity record. Selected user, setting, preference, strategy, risk-profile, broker-account, and simulation-order mutations are audited. This is not a claim that every read or framework event is audited.
- **System Event:** operational record currently used for known-user password-reset requests. Counts appear on the dashboard; there is no list endpoint.

## Environment semantics

The implemented backend `TradingEnvironment` enum contains exactly one value: `SIMULATION`. The `broker_accounts`, `signals`, `orders`, `deals`, `positions`, and `trades` tables default their environment columns to `SIMULATION`. `PAPER`, `DEMO`, and `LIVE` are not accepted persistence values in Phase 2.

Some retained frontend Phase 1 types contain future-capable environment vocabulary, and a paper-trading demonstration screen exists, but those do not add a backend environment or execution path. The server returns:

- market data: `MOCK / MOCK MARKET DATA`;
- broker: `DISCONNECTED`;
- execution: unavailable, no broker transmission;
- demo/live execution: false.

## Simulation-order behavior

The API accepts only `XAUUSD`, `EURUSD`, `GBPUSD`, `USDJPY`, `NAS100`, or `BTCUSD`; `BUY` or `SELL`; volume 0.01–5; optional positive prices; risk percent 0–10; and optional user-owned broker/signal references. The server ignores no hidden execution switch: execution and live fields are not part of the request contract.

Creation is rejected unless `emergency_stop` is exactly false and `trading_enabled` is exactly true. Defaults are stop on and trading off. A retry with the same user/idempotency key or command ID returns the existing order and creates no duplicate audit entry. Creation never creates a deal, position, or trade.

## Persistence and display boundaries

Persisted: users/roles/permissions, preferences, global settings, risk profiles, broker metadata, strategies/versions/settings, account snapshots, notifications/read state, audit/system events, and submitted simulation orders.

Schema/model foundation only: signals, deals, positions, trades, and risk events. Their Phase 1 screen data remains mock data because no corresponding Phase 2 APIs or producers exist. Market prices, candles, scanner results, AI scores, news, backtests, paper trades, analytics series, pending-order lists, open-position lists, and trade history are retained mocks.

## Future lifecycle recommendation

A later phase may implement Strategy → Signal → AI Analysis → authoritative Risk Engine → Execution Engine → MT5 Adapter. That is not implemented Phase 2 behavior. Signal, Order, Deal, Position, and Trade must remain distinct, every command must remain auditable/idempotent, and AI must never bypass the future risk engine or call MT5 directly.
