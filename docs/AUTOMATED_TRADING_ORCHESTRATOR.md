# Automated Trading Orchestrator (Phase 14)

**Engine:** `AutomatedTradingOrchestrator/v1`  
**Default state:** `OFF` (never auto-starts broker execution on app boot)  
**Modes:** `OFF` | `DRY_RUN` | `DEMO_AUTO` — **LIVE_AUTO does not exist**

## Pipeline

```text
Market Data → Scanner → Signals → Candidates
  → Phase 13 Intelligence (advisory; AI NOT authority)
  → Deterministic Qualification
  → Phase 9 Risk (fresh RiskDecision only)
  → Phase 10 Execution (sole order_send)
  → MT5 DEMO (when mode=DEMO_AUTO and account verified DEMO)
  → Phase 11 Management (orchestrator does not trail/close)
  → Close → Phase 12 Analytics / calibration link
```

## Safety

- LIVE / REAL / UNKNOWN / AMBIGUOUS / UNVERIFIED → SAFE_MODE, zero broker-changing actions, no LIVE cleanup
- Explicit enable + two-step start; UI shows **AUTO DEMO** never **AUTO LIVE**
- Kill switch blocks entries; does **not** auto close-all
- Restart → AUTO ENTRY PAUSED → require resume
- No revenge / martingale / online self-optimization
- Phase 14 `order_send` call sites: **0**
- Sole path: `trading-engine/src/nexa_mt5/execution.py::authorized_order_send`

## Control Center

`#/auto-trading` — pre-flight, pipeline, workflows, rejection/execution feeds, kill switch, safe/live banners.

APIs: `/api/v1/automation/*` with RBAC `automation.view|manage|operate|kill`.
