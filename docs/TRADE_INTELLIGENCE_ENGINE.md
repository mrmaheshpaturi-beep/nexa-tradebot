# Trade Intelligence Engine

## Purpose

Advisory / shadow market intelligence. Produces assessments, opportunity rankings, market quality views, and structured AI explanations. **Never executes trades.**

## Engine

- Version: `TradeIntelligence/v1`
- Modes: `ADVISORY`, `SHADOW` only
- Entry: `App\Intelligence\TradeIntelligenceEngineService`

## Safety

| Capability | Value |
|---|---|
| order_send | false |
| MT5 / DemoBridge writes | none |
| Risk / settings / strategy mutation | refused |
| LIVE | HARD_BLOCKED |
| Mutation tools on AI | false |

Static audit: `scripts/phase13-trade-intelligence-audit.sh`.

## Components

- `IntelligenceRules` — versioned advisory rules
- `TechnicalMtfRegimeIntelligence` — technical / MTF / regime
- `StrategyEnsemble` — Phase 7 confluence + family diversity + conflicts
- `OpportunityRanker` — transparent score breakdown
- `MarketQualityEngines` — quality / volatility / spread / anomaly
- `EvidenceLabels` — strict Historical/DEMO/Backtest/OOS/Execution/Portfolio separation
- `ConfidenceCalibration` — sample guards
- `IntelligenceJobQueue` + `UsageMeter` — budgets, cache, failure isolation
- Providers: Mock AI / News / Calendar (+ UNAVAILABLE fail-closed)

## Persistence

`intelligence_assessments`, `intelligence_opportunities`, `intelligence_ai_analyses`, `intelligence_chat_messages`, `intelligence_calendar_events`, `intelligence_news_items`, `intelligence_jobs`, `intelligence_usage_meters`, `intelligence_calibration_samples`, `intelligence_settings`.

## APIs

See `/api/v1/intelligence/*` in `routes/api.php`. Viewers: `intelligence.view`. Analyze: `intelligence.analyze`. Admin config: `intelligence.manage`. No LIVE permissions.
