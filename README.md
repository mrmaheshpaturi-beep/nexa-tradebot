# Nexa TradeBot

Phase 1 is a simulation-only Laravel + React/TypeScript/Vite trading operations terminal. It includes 22 routed screens, deterministic mock services, typed trading-domain models, charts, risk controls, a safe Laravel simulation API, and project documentation.

No MT5 connection, broker credential flow, real AI, or execution capability exists.

## Run locally

Requirements: Node.js 22+, PHP 8.3+, Composer 2.

```bash
npm install
npm run dev -- --host 0.0.0.0 --port 43127
```

The frontend uses in-memory services and works without the backend. To run the versioned Laravel API separately:

```bash
cd backend
composer install
php artisan serve --host 0.0.0.0 --port 43128
```

Useful checks:

```bash
npm run typecheck
npm run lint
npm test
npm run build
cd backend && php artisan test
```

See `docs/PROJECT_RULES.md` before changing trading behavior and `docs/ARCHITECTURE.md` for future integration boundaries.
# React + TypeScript + Vite

This template provides a minimal setup to get React working in Vite with HMR and some Oxlint rules.

Currently, two official plugins are available:

- [@vitejs/plugin-react](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react) uses [Oxc](https://oxc.rs)
- [@vitejs/plugin-react-swc](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react-swc) uses [SWC](https://swc.rs/)

## React Compiler

The React Compiler is not enabled on this template because of its impact on dev & build performances. To add it, see [this documentation](https://react.dev/learn/react-compiler/installation).

## Expanding the Oxlint configuration

If you are developing a production application, we recommend enabling type-aware lint rules by installing `oxlint-tsgolint` and editing `.oxlintrc.json`:

```json
{
  "$schema": "./node_modules/oxlint/configuration_schema.json",
  "plugins": ["react", "typescript", "oxc"],
  "options": {
    "typeAware": true
  },
  "rules": {
    "react/rules-of-hooks": "error",
    "react/only-export-components": ["warn", { "allowConstantExport": true }]
  }
}
```

See the [Oxlint rules documentation](https://oxc.rs/docs/guide/usage/linter/rules) for the full list of rules and categories.
