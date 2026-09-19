# Phase 18 Contract — Multi-Broker / Multi-Account Fleet Architecture

**Status: IMPLEMENTED** on branch `cursor/phase-18-broker-fleet-56f9`.

## Scope

1. Normalized broker provider / account / fingerprint / connection / capability models
2. Connector + MT5 adapter abstraction (routes into Phase 10; no execution duplicate)
3. Terminal registry / supervisor / isolation + SAFE_MODE on account-change mismatch
4. Canonical + broker instruments + mapping / spec freshness
5. Trading portfolios / memberships + deterministic versioned allocation + Phase 16 approved strategy assignments
6. Phase 9 account / portfolio / global risk locks extended for multi-account
7. Deterministic account-aware ExecutionRouter → Phase 10 with account-bound idempotency
8. Correct-account Phase 11 management gate (ticket + account bound)
9. Per-account reconciliation, foreign-position safety, restart recovery
10. Broker fleet health, account-scoped Phase 14 automation, emergency controls
11. Portfolio Command Center UI + connections / wizard / exposure / risk / allocation / routing / reconciliation
12. Valuation / currency normalization + account/portfolio analytics
13. Trading-node identity / leases / split-brain foundation
14. Migrations / API / RBAC / IDOR / secret & worker isolation / audit

## Non-negotiable constraints

1. Independent account environment verification before broker-changing readiness
2. LIVE / UNKNOWN hard-blocked for broker-changing actions
3. AI cannot route, allocate, or change risk
4. Phase 10 remains sole execution authority
5. No new `order_send` paths (sole site: `trading-engine/src/nexa_mt5/execution.py::authorized_order_send`)
6. LIVE_AUTO does not exist
7. Copy trading is absent
8. Mocks used where real terminals unavailable; Windows MT5 manual validation remains pending

## Explicit non-goals

- LIVE enablement
- LIVE_AUTO
- Copy trading / multi-account mirror execution
- Phase 19 implementation (contract stub only)
- Duplicating ExecutionEngine
