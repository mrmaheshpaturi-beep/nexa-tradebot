# Phase 13 Report — Trade Intelligence Engine

## 1. Executive Summary

Phase 13 delivers the TradeIntelligenceEngine: advisory/shadow market intelligence with structured Mock AI analysis, strategy ensemble ranking, market quality/volatility/spread/anomaly engines, replaceable calendar/news providers (fail-closed UNAVAILABLE), confidence calibration with sample guards, usage metering, queues, caching, audit, and health. Intelligence has **no path** to MT5, `order_send`, position management, or risk/settings/strategy mutation. LIVE remains **HARD BLOCKED**. CI uses **MOCK-only** providers (no paid API calls). Phase 14 is stub-only.

## 2. Phase Status

**PASS WITH WARNINGS**

Preview (leave running): Vite [http://127.0.0.1:58413](http://127.0.0.1:58413) · Laravel [http://127.0.0.1:48413](http://127.0.0.1:48413) · admin `admin@nexa.local` / `NexaLocalDevPass1!`

## 3. TradeIntelligenceEngine

`App\Intelligence\TradeIntelligenceEngineService` — versioned `TradeIntelligence/v1`, deterministic assessments with content/input hashes, ADVISORY + SHADOW modes.

## 4. IntelligenceAssessment / Rules

Persisted `intelligence_assessments` + `IntelligenceRules` (`intel-rules/v1`) firing MQ_BLOCK, SPREAD_WIDE, ANOMALY, ENSEMBLE_CONFLICT, CALENDAR/NEWS_UNAVAILABLE, SHADOW_MODE, NO_EXECUTION_PATH.

## 5–6. Technical / MTF / Regime + Ensemble

`TechnicalMtfRegimeIntelligence`, `StrategyEnsemble` (builds on Phase 7 `ConfluenceEngineService` with family diversity + conflicts).

## 7. Opportunity Ranking

`OpportunityRanker` — transparent breakdown (confluence, diversity, MTF, quality, penalties).

## 8. Market Quality / Volatility / Spread / Anomaly

`MarketQualityEngines` — fail-closed UNAVAILABLE on insufficient candles.

## 9. Calendar / News Providers

`MockCalendarProvider` / `MockNewsProvider` (always `is_fabricated=true`) + `Unavailable*` fail-closed empty feeds. Never fabricate as real.

## 10. AIAnalysisService

Validated structured output, input/output hashes, prompt+model versions, injection protection, budgets. Default/`testing` → `MockAIProvider`.

## 11. Read-only Explanation / Chat

`AIAnalysisService::chat` — `mutation_tools_available=false`; injection/mutation intents blocked.

## 12. Confidence Calibration + Sample Guards

`ConfidenceCalibration` — min 20 samples; `INSUFFICIENT_SAMPLES` guard; evidence label scoped.

## 13. Evidence Separation

`EvidenceLabels` — HISTORICAL / DEMO / BACKTEST / OOS / EXECUTION / PORTFOLIO; mixed IDs → `MIXED_LABEL_REFUSED`.

## 14. Shadow / Advisory Modes

Only ADVISORY and SHADOW; UI labeled; never implies live execution.

## 15. Caching / Budgets / Queues / Failure Isolation

`IntelligenceJobQueue` (max 50, per-job try/catch), Cache TTL, `UsageMeter` budgets.

## 16. Usage / Audit / Health

Meters, audit events (`intelligence.*`), heartbeat `TRADE_INTELLIGENCE`, system status `trade_intelligence_engine`.

## 17. Frontend

`#/ai-trade-desk` and `#/news-calendar` → Phase 13 desk: pulse, board, detail, heatmap, calendar, news, calibration, usage, research, read-only chat. ADVISORY / SHADOW / LIVE HARD BLOCKED badges.

## 18. APIs / RBAC / Migrations

`/api/v1/intelligence/*` · permissions `intelligence.view|analyze|manage` · additive migration `2026_09_19_230000_create_phase_thirteen_trade_intelligence.php`. Mutation endpoint always 403.

## 19–25. Tests / Gates

| Suite | Result |
|---|---|
| PHPUnit (full) | 167 passed / 1 skipped / 0 failed |
| PhaseThirteenTradeIntelligenceTest | 18/18 |
| Vitest | 26/26 |
| `tsc -b` | PASS |
| ESLint | PASS (1 pre-existing react-refresh warning) |
| Production build | PASS |
| Python pytest | PASS |
| `scripts/phase13-trade-intelligence-audit.sh` | PASS |

## 26. Docs

`TRADE_INTELLIGENCE_ENGINE.md`, `AI_ANALYSIS_SERVICE.md`, updated ARCHITECTURE/ROADMAP/SECURITY/DATABASE_SCHEMA/AUTHORIZATION, `PHASE_14_CONTRACT.md` (stub only).

## 27. Warnings / Limitations

- Technical/MTF proxies use closed-candle EMA/RSI family (not full live StrategyContext for every bar).
- Paid AI/news/calendar adapters are not shipped; env gate fails closed to MOCK/UNAVAILABLE.
- Calibration requires ≥20 labeled samples per evidence bucket.
- Chart vendor chunk remains large (existing advisory).

## 28. Phase 14

**NOT STARTED** — contract stub only.
