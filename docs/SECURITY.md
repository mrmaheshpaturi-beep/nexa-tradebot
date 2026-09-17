# Security

## Implemented Phase 2 controls

- Laravel session authentication through Sanctum's stateful API middleware; no browser bearer-token storage.
- CSRF cookie initialization and `X-XSRF-TOKEN` on state-changing React requests.
- Session regeneration at login and invalidation plus CSRF-token regeneration at logout.
- Login throttling at five attempts/minute per normalized email and IP; reset request/reset routes have separate throttles.
- Password hashing through Laravel's hashed model cast; 12-character minimum for user creation, update replacements, password reset, and development seed.
- Exactly five seeded roles and 23 granular permissions, enforced per backend route after authentication and active-user checks.
- `SUSPENDED` and `DISABLED` login rejection; inactive authenticated sessions receive `403` and are invalidated.
- Current-user ownership checks for user-scoped strategies, risk profiles, broker metadata, notifications, and simulation-order references.
- Validated allowlists/ranges for strategies, preferences, risk profiles, broker metadata, users, and simulation orders.
- Database transactions for user writes, settings, risk-profile mutations, strategy/version writes, and simulation-order plus audit creation.
- User-scoped idempotency key, globally unique command ID, and replay response for simulation orders.
- Audit model rejects application-level update/delete; audit records capture actor, entity, before/after values, IP, user agent, and timestamp.
- Non-enumerating reset-request response.

Frontend route guards and permission-based controls are not trusted as authorization; the Laravel middleware and ownership checks are authoritative.

## Trading safety defaults

The server defaults are:

| Setting | Default | Mutability |
|---|---:|---|
| `trading_enabled` | `false` | Authorized settings users can change it |
| `auto_trading_enabled` | `false` | Hard-locked against `true` |
| `emergency_stop` | `true` | Only `SUPER_ADMIN` through the dedicated endpoint |
| `allow_demo_execution` | `false` | Hard-locked against `true` |
| `allow_live_execution` | `false` | Hard-locked against `true` |

Simulation orders are rejected unless trading is enabled and the emergency stop is disabled. Successful records are server-forced to `SIMULATION`, `SIMULATED`, `simulated=true`, and `broker_transmitted=false`; they do not create deals or positions.

Broker-account requests prohibit `password`, `token`, `api_key`, `secret`, `execution_enabled`, and `live_enabled`; controllers force environment `SIMULATION`. Strategy requests prohibit `auto_trading_enabled`, and the controller also forces it false. There is no credential vault, broker connectivity, MT5 adapter, real execution, or real-funds path.

## Session and cookie configuration

Development defaults use database sessions, 120-minute idle lifetime, HTTP-only cookies, SameSite `lax`, JSON session serialization, and the root path. `.env.example` leaves `SESSION_SECURE_COOKIE` unspecified through the framework default and has `APP_DEBUG=true`, so it is a local-development file, not production configuration.

Any future hosted configuration must use HTTPS, set secure cookies, disable debug output, set correct same-site/domain values, restrict trusted stateful domains/origins, rotate `APP_KEY` only with an explicit session/data migration plan, and verify proxy headers. This work was not deployed or production-validated in Phase 2.

## Password-reset limitation

`POST /api/v1/auth/password/request` creates a real Laravel broker token only for a known account and records a system event, but no notification/mail delivery is invoked or configured. Its response explicitly says delivery is not configured. The React UI can request a reset but cannot accept a token/new password. `POST /api/v1/auth/password/reset` exists and is tested with a valid out-of-band token. Do not describe reset email as delivered until an approved provider, templates, queue/retry behavior, expiry handling, and end-to-end tests exist.

## Secret handling

- Never commit `.env`, `APP_KEY`, database credentials, mail/AWS secrets, tokens, passwords, terminal data paths, or broker credentials.
- `DEV_SUPER_ADMIN_PASSWORD` must be supplied at seed time, be at least 12 characters, and never be embedded in source or shell history intended for sharing.
- Never expose secrets through `VITE_` variables, local/session storage, client bundles, logs, analytics, audit before/after data, or exception messages.
- The login page stores only an optional remembered email in local storage.
- Future broker credentials require approved encrypted server-side storage, narrowly scoped decryption, key rotation, access audit, and redaction before any connectivity work.

## Data and audit limitations

- Audit immutability is enforced by Eloquent model hooks, not database triggers or append-only database privileges. Direct SQL or a privileged database account can alter records.
- Successful, failed, and blocked login attempts plus logout are audited without credentials. User/role/status, strategy, risk-profile, broker metadata, settings, emergency-stop, and simulation-order changes are also audited. Notification reads, password resets, and ordinary reads are not represented in `audit_logs`.
- Global application settings and audit logs are visible to anyone holding their named permission. User and audit list APIs use default pagination.
- Session payloads are not encrypted by the development default (`SESSION_ENCRYPT=false`); database access must be protected. Passwords are hashed separately.
- SQLite development is confirmed. MySQL/PostgreSQL portability is designed but not security- or behavior-tested.

## Required future hardening

Before any public deployment: production environment review, HTTPS/cookie/CORS validation, reset delivery, rate-limit and session-revocation review, database least privilege/backups, audit retention and tamper evidence, monitoring, dependency scanning, and MySQL/PostgreSQL target testing. Before any execution phase: authoritative risk approval, account/environment allowlists, replay protection beyond local persistence, signed integration boundaries, reconciliation, immutable execution audit, and a separate privileged live-activation governance workflow.

No deployment or security certification occurred in Phase 2.
