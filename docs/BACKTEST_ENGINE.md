# Backtest Engine

Deterministic research simulation. Environment label is always **BACKTEST**.

## Lineage

Every completed run stores strategy version, config version, data snapshot hash, parameters, cost model, intrabar policy, timestamps, and `lineage_hash`.

## Protections

- Closed-candle signals only (no lookahead)
- MTF bars included only when HTF `close_time` ≤ LTF bar close
- Explicit intrabar policy (default `OHLC_PATH`: BUY O-L-H-C, SELL O-H-L-C; first touch wins)
- Realistic spread / commission / slippage / swap via `CostModel`
- Phase 9 risk sizing + Phase 11 BE/trail/partial **simulated in-process** — never calls MT5
- Bounded job queue (per-user + global caps)
- Walk-forward IS/OOS folds
- Parameter grid optimization with overfitting controls (trial cap, OOS trade floor, expectancy degradation)
- Seeded Monte Carlo trade reshuffles (`seed + path_index`)
- Portfolio multi-sleeve runs
- Strategy evaluation reports (research only)
- BACKTEST vs DEMO comparison — labels never mixed

## Non-goals

- No broker `order_send` / modify / close
- No auto-promote of StrategySetting / RiskProfile / TradeManagementPolicy
- LIVE remains hard-blocked
