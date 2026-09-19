# Requirements Traceability Matrix — Phase 20 RC

| Req ID | Requirement | Phase | Verification | Status |
|---|---|---|---|---|
| R-SIM | Simulation trading lifecycle | 3 | PHPUnit PhaseThree* | PASS |
| R-AUTH | Auth + RBAC | 2 | PHPUnit Rbac | PASS |
| R-MT5RO | MT5 read-only bridge | 4 | pytest + PHPUnit + audit | PASS_WITH_WARNINGS (Windows PENDING) |
| R-MD | Market data quality/freshness | 5 | PHPUnit PhaseFive | PASS_WITH_WARNINGS |
| R-IND | Indicators closed-candle only | 6 | PHPUnit PhaseSix | PASS |
| R-STR | Strategy signals no execution | 7 | PHPUnit PhaseSeven | PASS |
| R-SCN | Scanner candidates only | 8 | PHPUnit PhaseEight | PASS |
| R-RISK | Fail-closed risk mandatory | 9 | PHPUnit PhaseNine | PASS |
| R-EXEC | Sole DEMO order_send path | 10 | static + PHPUnit + pytest | PASS_WITH_WARNINGS (XM PENDING) |
| R-MGMT | Position mgmt via sole path | 11 | PHPUnit PhaseEleven | PASS_WITH_WARNINGS |
| R-AN | Analytics/backtest no broker write | 12 | PHPUnit PhaseTwelve | PASS |
| R-AI | AI advisory only | 13/17 | PHPUnit PhaseThirteen/Seventeen | PASS |
| R-AUTO | DEMO_AUTO only; no LIVE_AUTO | 14 | PHPUnit PhaseFourteen | PASS |
| R-OBS | Health/alerts/soak framework | 15 | PHPUnit PhaseFifteen | PASS_WITH_WARNINGS |
| R-GOV | Governance DEMO deploy only | 16 | PHPUnit PhaseSixteen | PASS |
| R-FLEET | Multi-account isolation | 18 | PHPUnit PhaseEighteen | PASS_WITH_WARNINGS |
| R-HARD | Secrets/DR/OCC/deploy | 19 | PHPUnit PhaseNineteen | PASS_WITH_WARNINGS |
| R-LIVEBLOCK | LIVE hard-disabled | all | ExecutionGate + audits | PASS |
| R-UNKNOWN | UNKNOWN → reconcile never blind retry | 10/15/19 | crash recovery tests | PASS |
| R-RC | DEMO release candidate evidence | 20 | this phase | READY_FOR_CONTROLLED_DEMO (not full RC) |
