# Phase 19 Contract — Production Hardening / Security / Ops / DR / Trading-Aware Deploy

**Status: IMPLEMENTED** as `ProductionHardening/v1` on branch `cursor/phase-19-production-hardening-56f9`.

## Scope

1. Explicit app-vs-broker environments + config validation + safe defaults
2. Secret inventory / provider / rotation / redaction (no frontend or Git secrets)
3. Auth/RBAC/MFA foundation + service/node identity + replay/TLS/headers/CORS/CSRF/XSS/SQLi/IDOR/SSRF defenses
4. Dependency and reproducible builds posture
5. Database durability / backups / verification / isolated restore / DR
6. Redis-optional queue abstraction + prioritized / DLQ / idempotent jobs
7. Supervised workers (Laravel/Python/MT5 metadata) + graceful shutdown + restart reconciliation
8. Windows/node/network/time hardening checklists
9. Leases / split-brain / safe failover (extends Phase 18)
10. Trading-aware deploy / maintenance / rollback / versioning
11. Separated liveness / readiness / trading-readiness
12. Metrics / logging / correlation / audit / alerts / incidents / runbooks (extends Phase 15)
13. Operations Control Center + scoped safe modes
14. Performance / capacity signals
15. Soak / chaos frameworks in TEST/DEMO only — never LIVE; multi-day soak not claimed in CI
16. CI / static / dependency / secret / bundle audits
17. Docs + Phase 20 contract stub only

## Non-negotiable constraints

1. No blind retry on UNKNOWN execution — reconcile first
2. AI: no execution / risk mutation
3. Phase 9 RiskEngine mandatory
4. Phase 10 sole execution authority (`authorized_order_send` only)
5. Phase 16 governance intact
6. Phase 18 account/fleet isolation intact
7. LIVE/UNKNOWN hard-blocked; LIVE_AUTO does not exist
8. Honest PENDING for soak/restore/VPS not actually run

## Explicit non-goals

- No LIVE enablement
- No LIVE_AUTO
- No Phase 20 implementation
- No rewrite of trading engines (Risk/Execution/Management/Governance/Fleet)
