# Troubleshooting — Nexa TradeBot RC1

Updated: 2026-09-19T16:02:05Z

| Symptom | Likely cause | Action |
|---|---|---|
| PHPUnit DB errors | Missing sqlite file | `touch backend/database/database.sqlite && php artisan migrate` |
| pytest TestClient import error | Missing httpx/httpx2 | `pip install httpx` in trading-engine venv |
| Vite proxy 502 | Laravel not on 48420 | Start `php artisan serve --port=48420` |
| DEMO execution 422 | allow_demo_execution false / unverified trade mode | Verify DEMO account mapping + settings |
| LIVE_AUTO 403 | Expected | LIVE_AUTO does not exist |
| MT5 data unavailable | Bridge/Windows offline | Do not enable silent mock fallback in DEMO mode |
| UNKNOWN order state | Crash mid-send | Run reconciliation; never blind retry |
| Chunk size warning | Chart vendor | Accepted advisory; not a release blocker |
