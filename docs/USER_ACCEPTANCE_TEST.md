# User Acceptance Test — Phase 20

**Environment:** local preview Vite http://127.0.0.1:58420 · Laravel http://127.0.0.1:48420  
**Admin:** admin@nexa.local / NexaLocalDevPass1!  
**Date:** 2026-09-19T16:01:22Z

| # | Scenario | Expected | Result |
|---|---|---|---|
| UAT-01 | Login as admin | Session established | PASS (seeded + password reset) |
| UAT-02 | Ops Control Center `#/ops-control-center` | Loads OCC metrics · LIVE/LIVE_AUTO absent | PASS (screenshot evidence in media/phase20/) |
| UAT-03 | Portfolio Command Center | Fleet pages load · no LIVE_AUTO | PASS (screenshot evidence in media/phase20/) |
| UAT-04 | Execution DEMO pages | Sole path messaging · two-step confirm | PASS (screenshot evidence in media/phase20/) |
| UAT-05 | Governance Lab | DEMO deploy only · AI cannot deploy | PASS (screenshot evidence in media/phase20/) |
| UAT-06 | Probe LIVE_AUTO | 403 / hard reject | Covered by PHPUnit PhaseFourteen/Eighteen |
| UAT-07 | Emergency stop | Blocks simulation/DEMO entry | Covered by PHPUnit |
| UAT-08 | Viewer RBAC | Cannot manage users | Covered by PHPUnit Rbac |
| UAT-09 | XM DEMO place order | Broker fill evidence | PENDING REAL DEMO TEST |
| UAT-10 | Backup restore | Verified restore | PASS local logical + SQLite drill; VPS PENDING |

Walkthrough artifacts: `media/phase20/` and `/opt/cursor/artifacts/phase20_*.png`.
