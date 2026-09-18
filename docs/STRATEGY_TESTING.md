# Strategy Testing

- Feature suite: `PhaseSevenStrategyEngineTest`
- Covers catalog, gates, determinism/idempotency, confluence family de-dupe, scanner/matrix, ExecutionGate DEMO/LIVE reject, performance (no fake win rate)
- Frontend: `phase-seven-frontend.test.tsx`
- Prefer simulation market data in CI (`prefer=simulation`)
