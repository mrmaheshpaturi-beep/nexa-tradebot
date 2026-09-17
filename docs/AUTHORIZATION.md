# Authentication and Authorization

## Authentication architecture

Phase 2 implements first-party browser authentication with Laravel's `web` session guard and Sanctum's stateful API middleware. It does not issue bearer tokens. The React client:

1. requests `GET /sanctum/csrf-cookie` before a state-changing request;
2. sends cookies with `credentials: include`;
3. decodes the `XSRF-TOKEN` cookie into `X-XSRF-TOKEN`;
4. calls `POST /api/v1/auth/login`;
5. restores identity with `GET /api/v1/auth/me`; and
6. clears client identity after logout or any API `401`.

The backend regenerates the session after login, stores sessions in the database by default, records `last_login_at`, and invalidates the session and regenerates its CSRF token on logout. `remember=true` is passed to Laravel's session authentication; the UI separately stores only the remembered email in local storage. Passwords and session credentials are never stored by React.

Login is throttled to five attempts per minute by normalized email and IP. Password-reset request and reset endpoints are separately throttled. Reset requests are non-enumerating and create a real broker token for a known user, but there is no configured delivery provider; the frontend only registers a request and has no reset-token form. The backend `POST /auth/password/reset` endpoint works when a valid token is supplied out of band.

Every protected route uses `auth:sanctum`, then `EnsureActiveUser`, then its named permission middleware. `SUSPENDED` and `DISABLED` users cannot log in; an already authenticated non-`ACTIVE` user receives `403` and has the session invalidated. React route protection and hidden/disabled controls improve UX only. Laravel middleware is the security boundary.

## Exact roles

`RolePermissionSeeder` defines exactly five roles:

1. `SUPER_ADMIN`
2. `ADMIN`
3. `TRADER`
4. `ANALYST`
5. `VIEWER`

There is no roles-discovery endpoint. The React user editor therefore contains this exact list client-side. Roles and permissions must be seeded before user administration can assign them.

## Exact permission grants

| Permission | SUPER_ADMIN | ADMIN | TRADER | ANALYST | VIEWER |
|---|:---:|:---:|:---:|:---:|:---:|
| `dashboard.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `users.view` | ✓ | ✓ | — | — | — |
| `users.create` | ✓ | ✓ | — | — | — |
| `users.update` | ✓ | ✓ | — | — | — |
| `users.status` | ✓ | ✓ | — | — | — |
| `strategies.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `strategies.create` | ✓ | ✓ | ✓ | — | — |
| `strategies.update` | ✓ | ✓ | ✓ | — | — |
| `risk_profiles.view` | ✓ | ✓ | ✓ | ✓ | — |
| `risk_profiles.create` | ✓ | ✓ | — | — | — |
| `risk_profiles.update` | ✓ | ✓ | — | — | — |
| `broker_accounts.view` | ✓ | ✓ | ✓ | ✓ | — |
| `broker_accounts.create` | ✓ | ✓ | — | — | — |
| `broker_accounts.update` | ✓ | ✓ | — | — | — |
| `settings.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `settings.update` | ✓ | ✓ | — | — | — |
| `emergency_stop.manage` | ✓ | — | — | — | — |
| `preferences.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `preferences.update` | ✓ | ✓ | ✓ | ✓ | — |
| `notifications.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `notifications.update` | ✓ | ✓ | ✓ | ✓ | — |
| `audit_logs.view` | ✓ | ✓ | — | — | — |
| `simulation_orders.create` | ✓ | ✓ | ✓ | — | — |

`SUPER_ADMIN` receives all 23 permissions. `ADMIN` receives all except `emergency_stop.manage`.

## Route enforcement

| Route(s) | Permission |
|---|---|
| `GET /api/v1/dashboard` | `dashboard.view` |
| `GET /api/v1/users` | `users.view` |
| `POST /api/v1/users` | `users.create` |
| `PUT /api/v1/users/{user}` | `users.update` |
| `POST /api/v1/users/{user}/activate`, `/suspend`, `/disable` | `users.status` |
| `GET /api/v1/strategies` | `strategies.view` |
| `POST /api/v1/strategies` | `strategies.create` |
| `PUT /api/v1/strategies/{strategy}` | `strategies.update` |
| `GET /api/v1/risk-profiles` | `risk_profiles.view` |
| `POST /api/v1/risk-profiles` | `risk_profiles.create` |
| `PUT /api/v1/risk-profiles/{riskProfile}` | `risk_profiles.update` |
| `GET /api/v1/broker-accounts` | `broker_accounts.view` |
| `POST /api/v1/broker-accounts` | `broker_accounts.create` |
| `PUT /api/v1/broker-accounts/{brokerAccount}` | `broker_accounts.update` |
| `GET /api/v1/settings` | `settings.view` |
| `PUT /api/v1/settings/{key}` | `settings.update` |
| `PUT /api/v1/emergency-stop` | `emergency_stop.manage` |
| `GET /api/v1/preferences` | `preferences.view` |
| `PUT /api/v1/preferences` | `preferences.update` |
| `GET /api/v1/notifications` | `notifications.view` |
| `POST /api/v1/notifications/{notification}/read` | `notifications.update` |
| `GET /api/v1/audit-logs` | `audit_logs.view` |
| `POST /api/v1/simulation/orders` | `simulation_orders.create` |

Public routes are `POST /api/v1/auth/login`, `POST /api/v1/auth/password/request`, `POST /api/v1/auth/password/reset`, `GET /api/v1/system/status`, and compatibility alias `GET /api/v1/simulation/status`. `GET /api/v1/auth/me` and `POST /api/v1/auth/logout` require an active authenticated session but no additional named permission.

## Ownership and operation-level enforcement

- Strategy, risk-profile, and broker-account list queries are scoped to the current user. Their update controllers return `404` for another user's record.
- Broker-account and strategy risk-profile references must belong to the current user.
- Notifications are scoped by `user_id`; marking another user's notification read returns `404`.
- Simulation orders always receive the current `user_id`. Referenced broker accounts and signals must be available to that user.
- The users list and audit log are intentionally global for roles holding their permissions.
- Application settings are global. Only `SUPER_ADMIN` can change emergency-stop state; the general settings endpoint refuses the `emergency_stop` key.
- The server, not React, forces broker-account and order environment to `SIMULATION`, forces strategy `auto_trading_enabled=false`, rejects credential/execution broker fields, and rejects enabling locked execution settings.
- Simulation order creation additionally requires `emergency_stop=false` and `trading_enabled=true`; permission alone is insufficient.

React's `can(permission)` checks the permission objects returned in `roles.permissions` to expose or disable controls. It does not replace backend checks. The users page searches and filters only the currently fetched paginated page in the browser; the users endpoint has no server-side search or status filter.

## Administration limitations

- No roles or permissions discovery endpoint exists.
- No delete endpoints exist for users, strategies, risk profiles, broker accounts, notifications, settings, or orders.
- User status buttons avoid changing the current user in the UI, but the backend route itself does not contain a self-status prohibition.
- Role assignment accepts any role present in the database; the shipped seeder defines the five roles above.
- API pagination uses Laravel defaults and the frontend does not yet expose page navigation.
