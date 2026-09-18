# Security

## Authentication and authorization

- Laravel session authentication through Sanctum stateful middleware; no browser bearer token.
- CSRF cookie and `X-XSRF-TOKEN` on writes; session regeneration on login and invalidation on logout.
- Login and reset throttles; non-enumerating reset request response.
- `ACTIVE` user enforcement followed by named backend permission middleware.
- Five roles and 30 seeded permissions. React permission controls are UX only.
- Current-user ownership checks for accounts, signals, intents, orders, positions and other user-scoped resources.

See `AUTHORIZATION.md` for the exact matrix.

## Execution safety

| Control | Phase 3 value |
|---|---|
| `trading_enabled` | false; not required for local lifecycle simulation |
| `simulation_execution_enabled` | false by default; authorized setting |
| `auto_trading_enabled` | hard false |
| `emergency_stop` | true by default; dedicated SUPER_ADMIN control |
| `allow_demo_execution` | hard false |
| `allow_live_execution` | hard false |
| execution adapter | simulation only |
| terminal / broker | offline / disconnected |
| broker transmission | false |

The execution gate requires SIMULATION environment, enabled simulation account, emergency stop false and the simulation switch true. Placement additionally requires a persisted approved risk decision. PAPER/DEMO/LIVE commands are rejected.

Request contracts prohibit client-assigned environment and broker-transmission fields. Broker metadata requests prohibit password, token, API key, secret and execution/live fields. There is no credential vault, MT5 adapter, broker endpoint or network execution path.

## Lifecycle integrity

- User-scoped idempotency constraints for intents and commands.
- Database transactions and row locks around lifecycle mutations.
- Typed application state-transition guards.
- Controlled adapter failures persist safe status/system/audit records without downstream orders/positions.
- Public IDs do not replace ownership checks.
- Position close/protection and pending cancellation require explicit permissions.

These are application/database controls for one deployment. They are not distributed broker exactly-once delivery, tamper-proof audit, or protection against direct privileged database writes.

## Risk limitations

The Phase 3 risk evaluator is deterministic simulation code. It checks core account/instrument/volume/protection/risk/reward gates, but does not implement every enumerated institutional risk rule, market freshness, news/session limits, portfolio correlation, broker margin parity or real slippage. It must never be treated as authorization for live funds.

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

## Phase 4 prerequisite

Any initial MT5 work must be separately approved and read-only as defined in `MT5_INTEGRATION_CONTRACT.md`. Any DEMO write phase requires a new threat review, account allowlists, durable delivery/reconciliation, signed integration, credential controls and rollback. LIVE requires a separate governance decision.
