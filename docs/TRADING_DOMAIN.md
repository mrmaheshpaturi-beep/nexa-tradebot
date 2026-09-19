# Trading Domain

## Canonical concepts

- **TradingInstrument:** persisted symbol specification: asset class, currencies, digits, point/tick/contract values, volume bounds/step, stop distance, margin rate and enabled state.
- **BrokerAccount:** current-user-owned simulation metadata with an optional active risk profile and snapshots. It contains no broker credentials or connection behavior.
- **TradingTerminal / TradingSession / ServiceHeartbeat:** operational schema for adapter status. Phase 3 seeds one offline simulation terminal and a readable heartbeat; it opens no external session.
- **Strategy:** user-owned, versioned configuration. It may be linked to signals/intents but cannot execute.
- **Signal:** analytical record with direction, score, timeframe, references, expiry and explicit MOCK/SIMULATION source. It can create one intent but cannot evaluate or execute itself.
- **TradeIntent:** immutable request facts and lifecycle status. It is neither an order nor authorization to execute.
- **RiskDecision:** immutable Phase 9 approval/rejection with reason code, rule evidence, profile/engine versions, and optional ProposedPlan link.
- **ProposedPlan:** symbol-aware sizing proposal only; never a broker order (`broker_routable=false`).
- **RiskReservation / RiskLock:** concurrency margin/exposure reservation and breach locks that block new intents.
- **ExecutionCommand:** idempotent instruction to the simulation adapter with timestamps and safe failure state.
- **Order:** accepted/rejected/filled/cancelled instruction ledger. A MARKET fill creates a deal and position; a pending order does not.
- **Deal:** discrete entry, partial-exit or exit fill.
- **Position:** current exposure, volume, entry/current prices, protection, realized/unrealized P/L and margin.
- **PositionEvent:** append-style timeline item for open, protection changes, partial close and close.
- **AccountSnapshot:** account balance/equity/margin/free-margin/floating P/L/drawdown/open-position state captured after lifecycle mutations.
- **Trade / RiskEvent:** retained Phase 2 reporting and generic-risk foundations; the Phase 3 lifecycle does not produce them.

## Environment semantics

The PHP vocabulary is `SIMULATION`, `PAPER`, `DEMO`, `LIVE`, but only `SIMULATION` is executable. All Phase 3 producers assign SIMULATION server-side. The gate rejects all other environments. PAPER/DEMO/LIVE vocabulary is not evidence of an adapter or capability.

`trading_enabled=false` remains the hard truth for broker/live trading. Phase 3 adds the independent `simulation_execution_enabled` switch so local ledger execution can be tested while broker execution remains nonexistent.

## Order side and type

Directions are BUY and SELL. Types are MARKET, BUY_LIMIT, SELL_LIMIT, BUY_STOP and SELL_STOP. A typed pending order must match its side. Time in force vocabulary is GTC, DAY, IOC and FOK; Phase 3 stores it but has no expiry/trigger scheduler.

## Protection and price semantics

MARKET BUY enters at mock ask and SELL at mock bid. Initial BUY protection requires `SL < entry < TP`; SELL requires `TP < entry < SL`. Pending intents use requested entry. Open positions use close-side quote for modification: bid for BUY, ask for SELL.

The instrument API returns the exact backend mock quote and specification so the UI can explain these rules. The seeded minimum stop distance is zero. Volume min/max/step is enforced by `FinancialCalculator`.

## Risk semantics

Phase 9 `RiskEngineService` is authoritative. It evaluates versioned rule modules covering environment, locks, symbol specs/quality, volume/sizing, stops/R:R, daily/weekly loss, drawdown, loss streak, exposure/correlation, margin, spread, and session allowlists. Fail closed on insufficient data. UI never decides risk alone. See `RISK_ENGINE.md`.

The calculator uses stop distance × volume × contract size plus symbol volume step constraints — still deterministic simulation risk, not broker margin parity.

## Financial semantics

- BUY P/L increases with price; SELL P/L increases when price falls.
- Positions mark at their executable close side, representing spread.
- Partial/full closes create opposite-side MARKET fills.
- Balance changes only by realized P/L.
- Equity is balance plus open-position unrealized P/L.
- Free margin is equity minus used margin.
- Latest snapshot selection is ordered by capture timestamp then ID to handle same-second actions.

## Identity, idempotency and ownership

Lifecycle public IDs use simulation prefixes and are separate from internal integer foreign keys. Intent and command idempotency keys are unique per user. Account, signal, intent, order and position access is scoped to the authenticated owner; public IDs are not authorization.

## Retained mock versus persistent UI

Phase 3 database-backs manual lifecycle, signal records, order ledger/cancellation, open-position management, account snapshots and exact health. Market watch, scanner, chart candles, news, backtests, paper trading and many analytics/report screens remain explicitly frontend mocks.

## Intentional limitations

No real quote feed, automatic strategy/signal execution, pending trigger service, broker, credentials, MT5, DEMO/LIVE execution, commissions/swaps/fees, trailing stop, break-even, reconciliation scheduler or real-money behavior exists.
