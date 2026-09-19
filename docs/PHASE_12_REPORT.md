# Phase 12 Report — Analytics + Backtesting / Research Engine

## 1. Executive Summary

Phase 12 delivers AnalyticsEngine and BacktestEngine on Phase 11 foundations. DEMO/SIMULATION TradeSummaries feed reproducible datasets/snapshots and deterministic metrics. Backtests run exclusively under the **BACKTEST** environment label with full lineage, closed-candle/no-lookahead/MTF protections, explicit intrabar policy, cost modeling, Phase 9/11 logic reuse in simulation, bounded queues, walk-forward/OOS, overfitting-controlled optimization, seeded Monte Carlo, portfolio testing, strategy evaluation, and BACKTEST-vs-DEMO comparison. Phase 12 makes **zero broker-changing calls**. Strategies/risk are **never auto-promoted**. LIVE remains **HARD BLOCKED**. Phase 13 is stub-only.

## 2. Phase Status

**PASS WITH WARNINGS**

Preview (leave running): Vite [http://127.0.0.1:58412](http://127.0.0.1:58412) · Laravel [http://127.0.0.1:48412](http://127.0.0.1:48412) · admin `admin@nexa.local` / `NexaLocalDevPass1!`

## 3–6. AnalyticsEngine / Datasets / Core / Risk-Adjusted

`App\Analytics\*` — DatasetBuilder, MetricsCalculator, AnalyticsEngineService. Snapshots are immutable. Win rate N/A without completed outcomes. Sharpe/Sortino/max DD/Calmar included when data allows.

## 7–9. R / MAE / MFE / Cost / Execution / Management

Metrics include avg/median/best/worst R, MAE/MFE ratios, spread/commission/slippage/swap aggregates, BE/trail/partial rates.

## 10–20. BacktestEngine

`App\Backtest\*` — deterministic replay, CostModel, IntrabarPolicy, ClosedCandleTechnicalBuilder, SimulatedRiskAdapter, SimulatedManagementAdapter, BacktestJobQueue, OverfittingControls, walk-forward/MC/optimization/portfolio kinds. Lineage hash on every completed run.

## 21–24. Separation / Safety

BACKTEST ≠ DEMO enforced on `BacktestRun` and `ResearchComparison`. Promote API returns 403. LIVE hard-blocked. Audit script `scripts/phase12-analytics-backtest-audit.sh` **PASS**. Sole `order_send` unchanged at `trading-engine/src/nexa_mt5/execution.py::authorized_order_send`. Phase 12 engines contain zero broker-changing call sites (boolean safety flags only).

## 25. Database

Additive migration `2026_09_19_220000_create_phase_twelve_analytics_backtest.php`.

## 26. APIs / RBAC / Audit / Health

`/api/v1/analytics/*`, `/api/v1/backtest/*` with permissions `analytics.*` / `backtest.*`. Audit events on dataset/snapshot/run/export/compare/promote-refuse. Heartbeats `ANALYTICS_ENGINE` / `BACKTEST_ENGINE`. System status includes both engines.

## 27. Frontend

`#/analytics` and `#/backtesting` → Phase 12 console: performance, backtest console, research, comparison, trade explorer.

Walkthrough: `media/phase-12/` screenshots + `phase12_analytics_backtest_ui_walkthrough.mp4`.

## 28. Exports

CSV/JSON for analytics snapshots and backtest runs.

## 29–35. Tests / Gates (final)

| Suite | Result |
|---|---|
| PHPUnit (full) | 149 passed / 1 skipped / 0 failed |
| PhaseTwelveAnalyticsBacktestTest | 10/10 passed |
| Vitest | 26/26 passed |
| `tsc -b` | PASS |
| ESLint | PASS (1 pre-existing react-refresh warning) |
| Production build | PASS |
| Python pytest (trading-engine) | PASS |
| `scripts/phase12-analytics-backtest-audit.sh` | PASS |

## 36. Docs

`ANALYTICS_ENGINE.md`, `BACKTEST_ENGINE.md`, updated `PHASE_12_ANALYTICS_INPUT_CONTRACT.md`, `PHASE_13_CONTRACT.md` (stub), ARCHITECTURE/ROADMAP/SECURITY/DATABASE_SCHEMA updates.

## 37. Warnings / Limitations

- Backtest signals use closed-candle technical proxies (EMA/RSI family) rather than full live StrategyContext/MarketDataEngine wiring for every bar (deterministic research path).
- Portfolio mode shares one candle snapshot across sleeves with parameter offsets.
- Cost model uses configurable points/commission approximations, not broker account statements.
- Real MT5 DEMO outcome volume for analytics depends on Phase 10/11 Windows DEMO usage — empty TradeSummaries correctly yield win-rate N/A.
- `phpunit.xml` sets `APP_BASE_PATH` for worktree isolation when vendor is shared/copied.
- Browser cookie login via Playwright can be flaky; API session cookie inject is reliable for UI capture.

## 38. Phase 13

**NOT STARTED** — contract stub only.
