# Phase 8 Signal Pipeline Contract (UPDATED)

Phase 8 delivers **Market Scanner + Signal Orchestration** ending at the **Candidate Queue**.

```
Signal / Evaluation → Candidate (ranked opportunity)
```

## Explicitly out of scope for Phase 8

Future phases (not this branch) may map:

`Candidate → TradeIntent → RiskDecision → ExecutionCommand`

Constraints that remain in force:

- Simulation-first
- ExecutionGate DEMO/LIVE reject until explicitly approved
- No autonomous broker trading
- Mark-for-SIMULATE is a flag / future simulation path only — never MT5

**Do not implement Phase 9 (broker write / intent pipeline automation) in this branch.**
