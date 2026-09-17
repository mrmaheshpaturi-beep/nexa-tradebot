# Architecture

## Phase 1

The React 19 + TypeScript + Vite client presents 22 operational screens. Pages depend on typed service contracts in `src/services/mockServices.ts`; the active implementations are deterministic `Mock*Service` classes. Components do not know whether future data comes from mock memory, REST, WebSockets, or MT5.

Laravel 13 is isolated in `backend/`. Its versioned simulation API uses controller → `SimulationRepository` contract → `InMemorySimulationRepository`. Dependency injection makes a persistent repository replaceable later. The only write endpoint creates a simulation record and returns `broker_transmitted: false`.

```
React UI
  ├─ typed domain models
  ├─ service interfaces
  └─ mock services
        │ future REST/WebSocket boundary
Laravel API
  └─ simulation repository
```

## Future target (documentation only)

```
React → Laravel → Future Trading API → Python engine
                                      ├─ Strategy Engine
                                      ├─ Signal → AI Analysis
                                      ├─ Risk Engine (authoritative)
                                      └─ Execution Engine → MT5 Adapter → MetaTrader 5
```

AI never calls MT5 directly. No execution engine, Python service, Redis, WebSocket stream, MT5 adapter, or broker integration exists in Phase 1.

## Boundaries

- `src/domain/types.ts`: transport-independent trading language.
- `src/services/`: contracts and mock adapters.
- `src/components/`: shell, reusable display primitives, charts.
- `src/pages/`: route-level composition.
- `backend/app/Contracts`: backend ports.
- `backend/app/Repositories`: adapters.
- `backend/app/Http`: validated HTTP boundary.

The Laravel database layer remains portable across SQLite development, MySQL, and PostgreSQL.
