# Security

## Authentication and authorization

- Laravel session authentication through Sanctum stateful middleware; no browser bearer token.
- CSRF cookie and `X-XSRF-TOKEN` on writes; session regeneration on login and invalidation on logout.
- Login and reset throttles; non-enumerating reset request response.
- `ACTIVE` user enforcement followed by named backend permission middleware.
- Five roles and seeded named permissions (includes Phase 12 `analytics.*` / `backtest.*` and Phase 13 `intelligence.*`). React permission controls are UX only.
- Current-user ownership checks for accounts, signals, intents, orders, positions and other user-scoped resources.

See `AUTHORIZATION.md` for the exact matrix.

## Execution safety

| Control | Phase 3 value |
|---|---|
| `trading_enabled` | false; not required for local lifecycle simulation |
| `simulation_execution_enabled` | false by default; authorized setting |
| `auto_trading_enabled` | hard false |
| `emergency_stop` | true by default; dedicated SUPER_ADMIN control |
| `allow_demo_execution` | false by default; unlockable for gated DEMO |
| `auto_demo_execution` | hard false |
| `allow_live_execution` | hard false |
| execution adapter | simulation + gated DEMO ExecutionEngine |
| terminal / broker | simulation offline; optional read-only MT5 bridge metadata |
| broker transmission | false |

The execution gate requires SIMULATION environment, enabled simulation account, emergency stop false and the simulation switch true. Placement additionally requires a persisted approved risk decision. PAPER/DEMO/LIVE commands are rejected.

Request contracts prohibit client-assigned environment and broker-transmission fields. Broker metadata requests prohibit password, token, API key, secret and execution/live fields. Phase 4 adds a server-side read-only bridge client only; there is still no broker write path.

## Lifecycle integrity

- User-scoped idempotency constraints for intents and commands.
- Database transactions and row locks around lifecycle mutations.
- Typed application state-transition guards.
- Controlled adapter failures persist safe status/system/audit records without downstream orders/positions.
- Public IDs do not replace ownership checks.
- Position close/protection and pending cancellation require explicit permissions.

These are application/database controls for one deployment. They are not distributed broker exactly-once delivery, tamper-proof audit, or protection against direct privileged database writes.

## Risk limitations

Phase 9 RiskEngine is authoritative for SIMULATION intents and fail-closed on missing data, but it is not broker margin parity, live slippage, or authorization for real funds. DEMO/LIVE execution remain disabled.

## Market data safety

All backend quotes are fixed mocks labeled `MOCK` and `SIMULATION`. Request timestamps do not make them live. UI market/price guidance comes from the backend mock contract; unsupported symbols fail. No stream or external provider is configured.

## Secret handling

- Never commit `.env`, APP key, seed password, database/mail/cloud credentials, terminal paths or broker credentials.
- Supply `DEV_SUPER_ADMIN_PASSWORD` only to the local seed process; do not expose it in documentation, logs or shell history intended for sharing.
- Never expose secrets through `VITE_`, browser storage, bundles, audit before/after payloads or error messages.
- Future integration credentials require encrypted server-side/adapter-host storage, key ownership/rotation, least privilege, redaction and access audit.

Repository verification includes tracked-file secret-pattern and MT5/broker-adapter searches. Automated search reduces risk but is not a complete secret scan.

## Sessions and deployment

Development uses database sessions, HTTP-only cookies and SameSite lax. `.env.example` is development-oriented. Before deploying the authenticated application: HTTPS, secure cookie/domain/stateful-origin/proxy validation, debug-off configuration, database least privilege/backups, writable Laravel storage/cache, session revocation, rate-limit review, reset delivery, audit retention/tamper evidence, monitoring and recovery exercises are required.

The prior Hostinger deployment is a frontend-only simulation build. Phase 3 must not replace it with an API-dependent frontend unless Laravel, database, session authentication, CSRF and same-origin routing are deployed and verified together.

## Audit boundary

Lifecycle mutations and controlled execution failures are audited, and position events preserve execution linkage and before/after state. Audit immutability is enforced by Eloquent hooks, not database triggers or append-only credentials. Reads are generally not audited.

## Phase 4 read-only bridge controls

- Bridge service token remains server-side (`TRADING_BRIDGE_SERVICE_TOKEN`); React uses Laravel session APIs only.
- Python bridge redacts passwords, terminal paths, and tokens from responses/logs.
- Laravel circuit breaker limits abusive retry during bridge outages.
- Static `scripts/phase4-no-execution-audit.sh` guards against `order_send` and execution endpoints in owned sources.
- Real terminal validation is **PENDING WINDOWS ENVIRONMENT**; mock connector is not proof of broker readiness.

Any DEMO write phase still requires a new threat review, account allowlists, durable delivery/reconciliation, signed integration, credential controls and rollback. LIVE requires a separate governance decision.


## Phase 10 DEMO execution controls

- LIVE and UNKNOWN trade modes hard-fail at ExecutionGate, DemoAccountVerifier, bridge independent verification, and request account mode.
- Two-step manual confirmation required; Auto Demo locked off.
- CI uses `FakeDemoBridgeClient` only; real MetaTrader5 `order_send` exists solely in `nexa_mt5.execution.authorized_order_send`.
- Bridge writes require service token + nonce/timestamp replay protection.
- Real Windows DEMO integration requires explicit `NEXA_MT5_DEMO_INTEGRATION` / bridge real mode.


## Phase 11 management security

LIVE modification/partial/full close HARD BLOCKED at gate + verifier + adapter + bridge. CI uses FakeDemoBridge only. `scripts/phase11-trade-management-audit.sh` guards order_send leakage.

## Phase 12 analytics / backtest security

- Phase 12 engines never call `order_send`, DemoBridgeClient, or TradingBridgeDemoClient.
- BACKTEST environment is research-only and never silently labeled DEMO.
- Research promote endpoints refuse (403); StrategySetting/RiskProfile are never auto-mutated from results.
- LIVE remains hard-blocked. Audit: `scripts/phase12-analytics-backtest-audit.sh`.
- Permissions: `analytics.view|manage|export`, `backtest.view|run|export`.

## Phase 13 trade intelligence security

- Intelligence has no path to MT5, `order_send`, position management, or risk/settings/strategy mutation.
- AI chat/analysis is read-only; injection and mutation intents are blocked; `mutation_tools_available=false`.
- CI uses MockAI / MockNews / MockCalendar only; paid providers env-gated and never required for PASS.
- Missing news/calendar providers return UNAVAILABLE (never fabricate as real).
- LIVE remains hard-blocked. Audit: `scripts/phase13-trade-intelligence-audit.sh`.
- Permissions: `intelligence.view|analyze|manage` (viewers can view advisory; admins configure; no LIVE perms).


## Phase 14 automation security

- Default OFF; no boot auto-start of broker execution
- DEMO_AUTO requires verified DEMO trade mode continuously
- LIVE/UNKNOWN → SAFE_MODE; zero broker-changing actions
- `auto_demo_execution` unlockable; `allow_live_execution` locked false
- Phase 14 has zero `order_send` call sites; Phase 10 remains sole path
- Kill switch does not auto close-all
- AI cannot bypass qualification/risk/execution/management


## Phase 15 security notes

- Secret redaction via `SecretRedactor`
- EnvValidator rejects LIVE flags / LIVE_PRODUCTION
- Health probes rate-limited
- Phase 15 order_send = 0; LIVE_AUTO absent


## Phase 16 governance security

- AI cannot approve, deploy, or change active config (`GovernanceSafety::AI_MAY_* = false`)
- Two-step human approvals: bound resource hash, single-use tokens, nonce replay protection, TTL/staleness
- DEMO_AUTO deploy target only; LIVE / LIVE_AUTO endpoints 403
- Governance + Strategy Lab: `order_send` = 0; Phase 10 remains sole path
- Lab never mutates active config and never deploys
- Insufficient samples → `can_auto_approve=false`; human may still reject
- Rollback/suspend/retire preserve positions + history (no Phase 11 abandonment)
- Audit: `scripts/phase16-strategy-governance-audit.sh`
- Permissions: `governance.view|manage|approve|lab`

## Phase 17 advanced intelligence security

- Extends Phase 13 — no parallel conflicting intelligence stack; no path to MT5 / `order_send`
- AI/chat: no risk/config/approval/deployment mutation; `mutation_tools_available=false`
- Deterministic scores cannot be influenced by AI narrative output
- Historical analogs are no-lookahead with sample guards
- Research memory is append-only / immutable
- Phase 14 qualification, Phase 9 risk, Phase 10 sole execution, Phase 16 governance remain mandatory
- LIVE / UNKNOWN hard-blocked; LIVE_AUTO does not exist
- CI: MockAI / MockNews / MockCalendar only — no paid API calls
- Audit: `scripts/phase17-advanced-intelligence-audit.sh`
- Permissions: reuse `intelligence.view|analyze|manage`

## Phase 18 broker fleet security

- Independent account environment verification; LIVE/UNKNOWN hard-blocked for broker-changing actions
- Fingerprint mismatch / foreign positions / split-brain leases → SAFE_MODE
- AI cannot route, allocate, or change risk (403 probes)
- Phase 10 remains sole execution authority; Phase 18 `order_send` sites = 0
- Copy trading absent; LIVE_AUTO does not exist
- Secret refs on connections only (no raw credential persistence); worker isolation metadata
- IDOR: fleet resources user-scoped; cross-user verify returns 404
- Audit: `scripts/phase18-broker-fleet-audit.sh`
- Permissions: `fleet.view|manage|operate|risk|route|reconcile|emergency`
