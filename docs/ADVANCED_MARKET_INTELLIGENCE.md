# Advanced Market Intelligence (Phase 17)

## Purpose

Evidence-first extensions to Phase 13 Trade Intelligence. Advisory / shadow only. Never executes trades.

## Orchestrator

- Version: `AdvancedIntelligence/v1`
- Entry: `App\Intelligence\Advanced\AdvancedIntelligenceOrchestrator`
- Extends: `App\Intelligence\TradeIntelligenceEngineService` (`TradeIntelligence/v1`)
- Modes: `ADVISORY`, `SHADOW` only
- Duplicate stack: **false**

## Components

| Module | Role |
|---|---|
| `MarketFeatureEngine` | Versioned feature vectors + freshness TTL |
| `DeepMarketStructureEngine` | Structure, S/R, trend, momentum, vol, MTF matrix (wraps Phase 13 technical) |
| `ApprovedStrategyEnsemble` | Phase 16 APPROVED/DEPLOYED_DEMO filter on Phase 13 ensemble |
| `CrossMarketContextEngine` | Rolling correlation context |
| `HistoricalAnalogEngine` | No-lookahead analogs + sample guards |
| `ContextPackEngine` | Event/news/portfolio/execution/session (read-only) |
| `DeterministicScoringSeparator` | Deterministic scores independent of AI |
| `UncertaintyEvidenceEngine` | Extends Phase 13 calibration |
| `SuitabilityAnalysisEngine` | Advisory fit — no risk mutation |
| `ResearchMemoryService` | Immutable pre/post-trade + research memory |
| `PromptBuilder` | Schema + injection redaction |

## Safety

| Capability | Value |
|---|---|
| order_send | false |
| MT5 / DemoBridge writes | none |
| Risk / config / approval / deployment mutation | refused |
| LIVE | HARD_BLOCKED |
| LIVE_AUTO | does not exist |
| Phase 14 qualification | mandatory |
| Phase 9 RiskEngine | mandatory |
| Phase 10 sole execution | mandatory |
| Phase 16 governance | mandatory for promotions |
| CI providers | MOCK only |

Static audit: `scripts/phase17-advanced-intelligence-audit.sh`.

## Persistence

- `intelligence_advanced_snapshots`
- `intelligence_memory_records` (append-only / immutable)
- `intelligence_feature_versions`
- Additive columns on `intelligence_settings`

## APIs

`/api/v1/intelligence/advanced/*` — permissions `intelligence.view|analyze|manage`. Mutation endpoint always 403.

## UI

`#/advanced-intelligence` — desk, opportunity, MTF, evidence, memory, ADVISORY chat.
