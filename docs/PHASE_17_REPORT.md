# Phase 17 Report — Advanced Market Intelligence

## 0. Meta

- Branch: `cursor/phase-17-advanced-intelligence-56f9`
- Worktree: `/tmp/nexa-phase17-56f9`
- Base: Phase 16 tip `a32c737` (`cursor/phase-16-strategy-governance-56f9`)
- Preview: Vite [http://127.0.0.1:58417](http://127.0.0.1:58417) · Laravel [http://127.0.0.1:48417](http://127.0.0.1:48417)
- Admin: `admin@nexa.local` / `NexaLocalDevPass1!`
- UI: `#/advanced-intelligence` (extends `#/ai-trade-desk`)

## Phase verdict

**PASS WITH WARNINGS** — advanced intelligence complete in code; Windows MT5 DEMO soak and Hostinger deployment remain pending (carried from prior phases). Phase 18 NOT STARTED (stub only).

## 1. Executive Summary

Phase 17 delivers `AdvancedIntelligenceOrchestrator` (`AdvancedIntelligence/v1`) that **extends** Phase 13 `TradeIntelligenceEngineService` — it does not create a parallel conflicting intelligence stack. Adds versioned/fresh market features, deep structure/S-R/trend/momentum/volatility/MTF matrix, Phase 16 governance-approved strategy ensemble, cross-market context, no-lookahead historical analogs with sample guards, event/news/portfolio/execution/session context, provider/prompt/schema/injection/timeout/cache/TTL/model/cost controls, deterministic-vs-AI scoring separation, uncertainty/evidence quality, shadow/advisory modes, immutable pre/post-trade research memory, suitability analysis, Advanced Intelligence UI, migrations/APIs/RBAC/observability, and Phase 18 contract stub only.

## 2. Phase Status

**PASS WITH WARNINGS**

## 3. Intelligence Orchestrator

`App\Intelligence\Advanced\AdvancedIntelligenceOrchestrator` — versioned coordinator; calls Phase 13 `assess()` then layers Phase 17 engines; safety flags include phase 9/10/14/16 mandates.

## 4. Phase 13 Extension (not duplicate)

Reuses `TradeIntelligenceEngineService`, `AIAnalysisService`, Mock AI/News/Calendar, `EvidenceLabels`, shadow/advisory modes, `OpportunityRanker`, `StrategyEnsemble` (via approved filter), `ConfidenceCalibration` (via uncertainty engine).

## 5. Versioned / Fresh Market Features

`MarketFeatureEngine` — schema `market-features/v1`, feature hash, freshness TTL (`MAX_AGE_SECONDS=900`), `lookahead_safe=true`.

## 6. Regime / Structure / S-R / Trend / Momentum / Volatility / MTF

`DeepMarketStructureEngine` wraps Phase 13 `TechnicalMtfRegimeIntelligence` and adds swings, S/R zones, trend strength, momentum, volatility bands, MTF matrix with agreement.

## 7. Approved-Strategy Ensemble / Confluence / Conflicts

`ApprovedStrategyEnsemble` filters evaluations to Phase 16 `APPROVED` / `DEPLOYED_DEMO` versions (intel proxies allowed); rejects non-approved keys; `execution_authority=false`.

## 8. Cross-Market Rolling Context

`CrossMarketContextEngine` — rolling Pearson correlations; `lookahead_safe=true`.

## 9. No-Lookahead Historical Analogs + Sample Guards

`HistoricalAnalogEngine` — candidate windows must end before query window; `MIN_SAMPLES` guard → `INSUFFICIENT_SAMPLES`.

## 10. Event / News / Portfolio / Execution / Session Context

`ContextPackEngine` packs calendar/news + optional portfolio/execution/session; all `mutable=false`, `order_send=false`.

## 11. Provider / Prompt / Schema / Injection / Timeouts / Cache / TTL / Model / Cost

Extended `AIAnalysisService` (timeout, cache TTL, cost_tokens meter, model tracking); `PromptBuilder` with schema + injection redaction; Mock providers only in CI.

## 12. Deterministic Scoring Separated from AI

`DeterministicScoringSeparator` — `ai_influences_score=false` / `influences_deterministic_score=false`.

## 13. Calibration / Uncertainty / Evidence Quality

`UncertaintyEvidenceEngine` extends Phase 13 `ConfidenceCalibration` with evidence quality score + uncertainty band.

## 14. Shadow / Advisory Modes

Only `ADVISORY` / `SHADOW`; UI labeled; LIVE hard blocked.

## 15. Immutable Pre/Post-Trade Intelligence + Research Memory

`ResearchMemoryService` + `intelligence_memory_records` — append-only, `immutable=true`; mutation refused.

## 16. Suitability Analysis

`SuitabilityAnalysisEngine` — advisory fit label; never mutates risk/approvals; `execution_authority=false`.

## 17. Intelligence / Opportunity / MTF / Evidence / Chat UI

`PhaseSeventeenIntelligencePages` at `#/advanced-intelligence` — desk, opportunity, MTF, evidence, memory, ADVISORY chat; mutate probe expects 403.

## 18. Migrations / APIs / RBAC / Observability

- Migration `2026_09_19_270000_create_phase_seventeen_advanced_intelligence.php`
- Routes `/api/v1/intelligence/advanced/*`
- Permissions reuse `intelligence.view|analyze|manage`
- System status `advanced_intelligence`; heartbeat `ADVANCED_INTELLIGENCE`; optional dependency in Phase 15 health graph

## 19. Docs / Phase 18 Contract

Updated: ARCHITECTURE, SECURITY, ROADMAP, DATABASE_SCHEMA, AUTHORIZATION, TRADE_INTELLIGENCE_ENGINE, AI_ANALYSIS_SERVICE.  
New: PHASE_17_REPORT, ADVANCED_MARKET_INTELLIGENCE, PHASE_17_CONTRACT (implemented), PHASE_18_CONTRACT (**stub only**).

## 20. Safety matrix

| Control | Value |
|---|---|
| AI/Chat → MT5/order_send | NONE |
| AI/Chat → Risk/Config/Approval/Deployment | NONE |
| Phase 14 qualification | mandatory |
| Phase 9 RiskEngine | mandatory |
| Phase 10 sole execution | YES |
| Phase 16 governance | mandatory |
| LIVE/UNKNOWN | HARD BLOCKED |
| LIVE_AUTO | DOES NOT EXIST |
| CI providers | MOCK ONLY |

## 21. Tests / Gates

| Suite | Result |
|---|---|
| PHPUnit (full) | 215 passed / 1 skipped / 0 failed |
| PhaseSeventeenAdvancedIntelligenceTest | 14/14 |
| Vitest | 26/26 |
| `tsc -b` | PASS |
| ESLint | PASS (1 pre-existing react-refresh warning) |
| Production build | PASS |
| Python pytest | PASS (25) |
| `scripts/phase17-advanced-intelligence-audit.sh` | PASS |
| `scripts/phase13-trade-intelligence-audit.sh` | PASS |

## 22. Warnings / Limitations

- Technical/MTF/analog proxies use closed-candle deterministic features (not full live StrategyContext for every bar).
- Paid AI/news/calendar adapters are not shipped; env gate fails closed to MOCK/UNAVAILABLE.
- Calibration/analog sample guards require minimum samples.
- Chart vendor chunk remains large (existing advisory).
- Windows MT5 DEMO soak / Hostinger deployment still pending from prior phases.

## 23. Phase 18

**NOT STARTED** — contract stub only (`PHASE_18_CONTRACT.md`).
