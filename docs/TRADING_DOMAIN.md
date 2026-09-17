# Trading Domain

- **Broker Account:** configuration and status of a trading account. Phase 1 stores no credentials and exposes one disconnected demo example.
- **Strategy:** a versionable set of market rules that may produce a Signal. A strategy does not place an order.
- **Signal:** an analytical observation with direction, score, timeframe, price levels, and explanation. It has no execution authority.
- **Order:** an instruction to transact. Phase 1 orders are simulation records only.
- **Deal:** a discrete fill generated from an order. One order may create multiple deals in a real execution domain.
- **Position:** current net market exposure resulting from one or more deals. It is not interchangeable with an order.
- **Trade:** reporting projection for a completed trading outcome; it aggregates entry, exit, costs, duration, and P/L.
- **Risk Profile:** account-level limits including per-trade risk, loss ceilings, drawdown, exposure, margin, spread, slippage, and minimum reward.
- **Risk Event:** observable result of a risk rule, classified as informational, warning, or critical.
- **Account Snapshot:** point-in-time balance, equity, margin, drawdown, activity, and risk metrics.

`TradingEnvironment` allows `SIMULATION`, `PAPER`, `DEMO`, and `LIVE` as a future-capable domain vocabulary. The Phase 1 application is hard-locked to `SIMULATION`; merely changing a frontend value cannot create execution infrastructure.

The future lifecycle is Strategy → Signal → AI Analysis → authoritative Risk Engine → Execution Engine → MT5 Adapter. Each boundary must be independently auditable and idempotent.
