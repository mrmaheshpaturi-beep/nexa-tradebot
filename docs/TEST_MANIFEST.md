# Phase 20 Test Manifest

**Captured:** 2026-09-19T16:01:22Z

| Suite | Command | Result | Exact count |
|---|---|---|---|
| Laravel PHPUnit | `./vendor/bin/phpunit` | PASS | 250 tests · 249 passed · 1 skipped · 0 failed · 2164 assertions |
| Phase 19 hardening subset | included in PHPUnit | PASS | 18/18 |
| Vitest (frontend) | `npm test` | PASS | 13 files · 27 tests |
| TypeScript | `npx tsc -b` | PASS | 0 errors |
| ESLint | `npm run lint` | PASS | 0 errors · 1 warning |
| Production build | `npm run build` | PASS | built successfully |
| Python pytest | `pytest` in trading-engine | PASS | 25 passed · 0 failed |
| Migrations | `php artisan migrate --force` | PASS | 21 migrations |
| Local backup/restore | BackupService + SQLite drill | PASS | verified=true · restore_tested=true · 21 migrations / 173 tables restored |
| Phase audit scripts 4–19 | `scripts/phase*-*.sh` | PASS | all PASS after P5 fix |
| Phase 20 audit | `scripts/phase20-final-validation-audit.sh` | PASS | all checks OK |
| XM DEMO broker orders | — | PENDING REAL DEMO TEST | 0 broker fills claimed |
| Windows MT5 | — | PENDING | — |
| Soak 24h/72h/7d | SoakChaosHarness | PENDING (framework only) | elapsed runtime not claimed |

## Evidence paths

See `docs/evidence/phase20/`.
