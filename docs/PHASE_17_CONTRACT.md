# Phase 17 Contract — Advanced Market Intelligence

**Status: IMPLEMENTED** on branch `cursor/phase-17-advanced-intelligence-56f9`.

Phase 17 **extends** Phase 13 TradeIntelligenceEngine. It does **not** create a parallel conflicting intelligence stack.

## Scope

1. Versioned `AdvancedIntelligenceOrchestrator` coordinating deeper market features on top of Phase 13
2. Versioned/fresh market features (`market-features/v1`)
3. Regime, structure, S/R zones, trend, momentum, volatility, MTF matrix
4. Approved-strategy ensemble / confluence / conflicts (Phase 16 APPROVED|DEPLOYED_DEMO where applicable)
5. Cross-market rolling context
6. No-lookahead historical analogs with sample guards
7. Event/news/portfolio/execution/session context packs
8. Provider abstraction / prompt builder / schema validation / injection defenses / timeouts / cache / TTL / model tracking / cost budgets
9. Separated deterministic scoring vs AI assessment
10. Calibration / uncertainty / evidence quality
11. Shadow / advisory modes (extended)
12. Immutable pre/post-trade intelligence and research memory
13. Suitability analysis
14. UI: `#/advanced-intelligence` (opportunity / MTF / evidence / chat — ADVISORY labeled)
15. Migrations / APIs / RBAC / observability
16. Phase 18 contract stub only

## Non-negotiable constraints

1. AI/chat: ZERO MT5 / `order_send` access
2. AI/chat: NO risk / config / approval / deployment mutation
3. Phase 14 qualification remains mandatory (AI not final authority)
4. Phase 9 RiskEngine mandatory
5. Phase 10 sole execution authority
6. Phase 16 governance mandatory for promotions
7. LIVE / UNKNOWN hard blocked; LIVE_AUTO absent
8. CI: deterministic mocks only — NO paid API calls

## Explicit non-goals

- No LIVE enablement
- No LIVE_AUTO
- No Phase 18 implementation
- No parallel intelligence stack replacing Phase 13
