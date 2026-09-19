# Nexa TradeBot — Release Candidate RC1

**Release:** `NEXA-TRADEBOT-RC1` · package `1.0.0-rc.1` · branch `cursor/phase-20-demo-release-candidate-56f9`  
**Final status:** **READY_FOR_CONTROLLED_DEMO** (not LIVE; LIVE_AUTO does not exist)

Stack: React 19 / TypeScript / Vite · Laravel / Sanctum · Python FastAPI MT5 bridge.

Phases 1–19 delivered and re-validated in Phase 20 (feature freeze). See `docs/PHASE_20_FINAL_REPORT.md`.

## Local preview (Phase 20 ports)

```bash
# Laravel
cd backend && php artisan serve --host=127.0.0.1 --port=48420

# Vite (proxies /api → 48420)
npm run dev
# → http://127.0.0.1:58420
```

Admin (local seed): `admin@nexa.local` / `NexaLocalDevPass1!`  
Ops UI: `#/ops-control-center`

## Validation

```bash
cd backend && ./vendor/bin/phpunit
cd trading-engine && .venv/bin/pytest
npm test && npx tsc -b && npm run lint && npm run build
bash scripts/phase20-final-validation-audit.sh
```

## Documentation index

| Doc | Purpose |
|---|---|
| `docs/PHASE_20_FINAL_REPORT.md` | 60-section final report |
| `docs/PHASE_1_TO_19_AUDIT.md` | Full re-audit |
| `docs/DEMO_RELEASE_CHECKLIST.md` | Release decision checklist |
| `docs/RELEASE_IDENTITY.md` | RC identity |
| `docs/INSTALLATION.md` | Install |
| `docs/OPERATIONS_MANUAL.md` | Ops |
| `docs/FINAL_SECURITY_CHECKLIST.md` | Security |
| `docs/evidence/phase20/` | Raw evidence |

## Explicit non-certifications

Not `LIVE_READY` · Not `REAL_MONEY_READY` · Not `PROFIT_CERTIFIED` · No Phase 21

Windows MT5 / XM DEMO broker evidence / multi-day soak / VPS restore: **PENDING** (see final report).
