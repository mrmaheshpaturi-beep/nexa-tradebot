# Phase 14 Report — Automated DEMO Trading Orchestrator

## 1. Executive Summary

Phase 14 delivers `AutomatedTradingOrchestrator` coordinating Market Data → Scanner → Signals → Candidates → Phase 13 Intelligence → Deterministic Qualification → Phase 9 Risk → Phase 10 Execution → MT5 DEMO → Phase 11 Management → Phase 12 Analytics — **without** manual approve on every DEMO trade, **only** when actual account trade mode is DEMO. Modes: `OFF` (default) | `DRY_RUN` | `DEMO_AUTO`. **LIVE_AUTO does not exist.** Phase 14 `order_send` sites = **0**. LIVE remains **HARD BLOCKED**. CI uses Fake/Mock brokers only.

## 2. Phase Status

**PASS WITH WARNINGS**

Preview (leave running): Vite [http://127.0.0.1:58414](http://127.0.0.1:58414) · Laravel [http://127.0.0.1:48414](http://127.0.0.1:48414) · admin `admin@nexa.local` / `NexaLocalDevPass1!`

## 3. AutomatedTradingOrchestrator

`App\Automation\AutomatedTradingOrchestrator` — states OFF/STARTING/RUNNING/PAUSED/DEGRADED/SAFE_MODE/STOPPING/ERROR; never auto-starts on boot; modular tick/queues (not `runEverythingForever`).

## 4. Session / Profile / Config hash

`AutomationSession` + configuration snapshot/hash; `AutomationProfile` DRAFT→VALIDATED→ACTIVE→ARCHIVED; strategy version lock via matrix; pause→validate→activate→resume for changes.

## 5. Startup / continuous safety gates

`AutomationStartupGate` + continuous recheck on tick; LIVE/UNKNOWN → SAFE_MODE; entries blocked; no LIVE cleanup; explicit resume.

## 6. Qualification

`AutomatedCandidateQualificationEngine` — AI not final authority; `INTELLIGENCE_REQUIRED` default; AI failure WAIT/EXPIRE/REJECT; calendar fail-closed; anomaly/spread/TTL; no revenge/martingale.

## 7. Risk / Execution / Management

Fresh Phase 9 `RiskDecision`; automation confirmation → Phase 10 `submitDemo` (sole order_send); UNKNOWN → reconcile (no blind retry); post-fill handoff to Phase 11 (orchestrator does not trail/close).

## 8. Locks / kill switch

`AutomationExecutionLockService` precedence; PAUSE vs STOP; kill switch (no auto close-all); CLOSE-ALL remains Phase 11 separate.

## 9. Queues / health / recovery

Priority queues (SAFETY before INTELLIGENCE); heartbeat `AUTOMATED_TRADING_ORCHESTRATOR`; restart recovery forces AUTO ENTRY PAUSED + require resume.

## 10. Frontend

`#/auto-trading` Control Center: mode path OFF→DRY_RUN→AUTO DEMO, pre-flight, pipeline, workflows, rejection/execution feeds, kill switch, SAFE/LIVE banners. Labels: **AUTO DEMO** never **AUTO LIVE**.

## 11. APIs / RBAC / Migrations

`/api/v1/automation/*` · permissions `automation.view|manage|operate|kill` · migration `2026_09_19_240000_create_phase_fourteen_demo_automation.php`.

## 12. Settings

`auto_demo_execution` unlockable (default false) via confirmed enable; `allow_live_execution` + `auto_trading_enabled` remain LOCKED false.

## 13. Docs

`AUTOMATED_TRADING_ORCHESTRATOR.md`, `PHASE_14_AUTOMATION_ORCHESTRATION_CONTRACT.md`, updated ARCHITECTURE/ROADMAP/SECURITY_SCHEMA/AUTHORIZATION/SECURITY, `PHASE_15_CONTRACT.md` (stub only).

## 14. Warnings / Limitations

- Real Windows MT5 DEMO end-to-end automation validation is **PENDING WINDOWS ENVIRONMENT** (CI uses FakeDemoBridge).
- Calendar/news paid providers remain MOCK/UNAVAILABLE fail-closed (Phase 13).
- Notification delivery is IN_APP foundation only (email/Telegram not required).
- Distributed multi-node lock TTL is DB-backed (no Redis cluster required for PASS).

## 15. Phase 15

**NOT STARTED** — contract stub only.
