# AI Analysis Service

## Purpose

Provider-agnostic advisory AI for structured explanations and read-only chat. Never gains mutation tools.

## Guarantees

1. Validated structured output (summary, bias, advisory_stance, confidence_raw, key_points, risks, prompt/model versions).
2. Input hash + output hash on every analysis row.
3. Prompt version `intel-prompt/v1` (+ Phase 17 `intel-prompt/v2-advanced`) + provider model version recorded.
4. Injection / mutation-intent detection blocks dangerous prompts; Phase 17 `PromptBuilder` redacts injurious leaves.
5. Chat is read-only (`mutation_tools_available=false`).
6. Usage budgets fail closed (`BUDGET_EXCEEDED`); Phase 17 also meters `cost_tokens`.
7. Timeouts (`timeout_ms`), response cache TTL, and model/cost tracking in `raw_meta`.
8. CI / `testing` always resolves `MockAIProvider` — no paid API calls required for PASS.
9. Deterministic rank scores are never influenced by AI output (`DeterministicScoringSeparator`).

## Providers

| Provider | CI | Notes |
|---|---|---|
| MOCK | default | Deterministic |
| UNAVAILABLE (news/calendar) | optional | Empty feed, not fabricated-as-real |
| Paid external | env-gated, not shipped | Fail closed to MOCK |

Env: `INTELLIGENCE_AI_PROVIDER`, `INTELLIGENCE_NEWS_PROVIDER`, `INTELLIGENCE_CALENDAR_PROVIDER`, `INTELLIGENCE_PAID_PROVIDERS` (default false).
