# Authentication and Authorization

## Authentication architecture

Phase 3 retains first-party browser authentication with Laravel's `web` session guard and Sanctum's stateful API middleware. It does not issue bearer tokens. The React client:

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
| `trading.read` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `signals.view` | ✓ | ✓ | ✓ | ✓ | — |
| `simulation_lifecycle.create` | ✓ | ✓ | ✓ | — | — |
| `simulation_lifecycle.evaluate` | ✓ | ✓ | ✓ | — | — |
| `simulation_lifecycle.execute` | ✓ | ✓ | ✓ | — | — |
| `simulation_orders.cancel` | ✓ | ✓ | ✓ | — | — |
| `simulation_positions.manage` | ✓ | ✓ | ✓ | — | — |
| `mt5.read` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `mt5.sync` | ✓ | ✓ | ✓ | — | — |
| `mt5.reconcile` | ✓ | ✓ | ✓ | — | — |
| `mt5.connections.manage` | ✓ | ✓ | — | — | — |

`SUPER_ADMIN` receives all 34 permissions. `ADMIN` receives all except `emergency_stop.manage`.

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
| `GET /api/v1/instruments`, `/trade-intents`, `/orders`, `/positions`, `/heartbeats` and detail routes | `trading.read` |
| `GET /api/v1/signals` and `/signals/{signal}` | `signals.view` |
| `POST /api/v1/trade-intents`, `/signals/{signal}/trade-intent` | `simulation_lifecycle.create` |
| `POST /api/v1/trade-intents/{intent}/evaluate` | `simulation_lifecycle.evaluate` |
| `POST /api/v1/trade-intents/{intent}/execute` | `simulation_lifecycle.execute` |
| `POST /api/v1/orders/{order}/cancel` | `simulation_orders.cancel` |
| Position close, partial-close and protection routes | `simulation_positions.manage` |
| `GET /api/v1/mt5/status`, bridge proxies, mapping reads, reconciliation run reads | `mt5.read` |
| `POST /api/v1/mt5/connections`, `/connections/{id}/test` | `mt5.connections.manage` |
| `POST /api/v1/mt5/connections/{id}/sync` | `mt5.sync` |
| `POST /api/v1/mt5/mappings/{id}/reconcile` | `mt5.reconcile` |

Public routes are `POST /api/v1/auth/login`, `POST /api/v1/auth/password/request`, `POST /api/v1/auth/password/reset`, `GET /api/v1/system/status`, and compatibility alias `GET /api/v1/simulation/status`. `GET /api/v1/auth/me` and `POST /api/v1/auth/logout` require an active authenticated session but no additional named permission.

## Ownership and operation-level enforcement

- Strategy, risk-profile, and broker-account list queries are scoped to the current user. Their update controllers return `404` for another user's record.
- Broker-account and strategy risk-profile references must belong to the current user.
- Notifications are scoped by `user_id`; marking another user's notification read returns `404`.
- Simulation orders always receive the current `user_id`. Referenced broker accounts and signals must be available to that user.
- Phase 3 signal, intent, order and position queries are current-user scoped; mutation services repeat ownership checks before execution.
- The users list and audit log are intentionally global for roles holding their permissions.
- Application settings are global. Only `SUPER_ADMIN` can change emergency-stop state; the general settings endpoint refuses the `emergency_stop` key.
- The server, not React, forces broker-account and order environment to `SIMULATION`, forces strategy `auto_trading_enabled=false`, rejects credential/execution broker fields, and rejects enabling locked execution settings.
- The legacy simulation-order endpoint additionally requires `emergency_stop=false` and `trading_enabled=true`. Phase 3 lifecycle execution instead requires `emergency_stop=false`, `simulation_execution_enabled=true`, an enabled SIMULATION account and an approved risk decision; permission alone is insufficient.

React's `can(permission)` checks the permission objects returned in `roles.permissions` to expose or disable controls. It does not replace backend checks. The users page searches and filters only the currently fetched paginated page in the browser; the users endpoint has no server-side search or status filter.

## Administration limitations

- No roles or permissions discovery endpoint exists.
- No delete endpoints exist for users, strategies, risk profiles, broker accounts, notifications, settings, or orders.
- User status buttons avoid changing the current user in the UI, but the backend route itself does not contain a self-status prohibition.
- Role assignment accepts any role present in the database; the shipped seeder defines the five roles above.
- API pagination uses Laravel defaults and the frontend does not yet expose page navigation.

## Phase 13 intelligence permissions

| Permission | SUPER_ADMIN | ADMIN | TRADER | ANALYST | VIEWER |
|---|:---:|:---:|:---:|:---:|:---:|
| `intelligence.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `intelligence.analyze` | ✓ | ✓ | ✓ | ✓ | — |
| `intelligence.manage` | ✓ | ✓ | ✓ | — | — |

Viewers can see advisory desk/pulse/calendar/news. Analyze creates assessments/AI chat. Manage updates intelligence settings and queue. No LIVE execution permissions are granted by Phase 13.

## Phase 14 automation permissions

| Permission | Purpose |
|---|---|
| `automation.view` | Control Center, sessions, workflows, feeds |
| `automation.manage` | Profiles, enable AUTO DEMO setting |
| `automation.operate` | Two-step start, pause/resume/stop, tick, recover |
| `automation.kill` | Kill switch |

LIVE_AUTO endpoints always 403. Browser never receives raw MT5 write payloads.


## Phase 15 permissions

- `observability.view`
- `observability.manage`
- `observability.operate`
