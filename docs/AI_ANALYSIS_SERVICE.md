# AI Analysis Service

## Purpose

Provider-agnostic advisory AI for structured explanations and read-only chat. Never gains mutation tools.

## Guarantees

1. Validated structured output (summary, bias, advisory_stance, confidence_raw, key_points, risks, prompt/model versions).
2. Input hash + output hash on every analysis row.
3. Prompt version `intel-prompt/v1` + provider model version recorded.
4. Injection / mutation-intent detection blocks dangerous prompts.
5. Chat is read-only (`mutation_tools_available=false`).
6. Usage budgets fail closed (`BUDGET_EXCEEDED`).
7. CI / `testing` always resolves `MockAIProvider` — no paid API calls required for PASS.

## Providers

| Provider | CI | Notes |
|---|---|---|
| MOCK | default | Deterministic |
| UNAVAILABLE (news/calendar) | optional | Empty feed, not fabricated-as-real |
| Paid external | env-gated, not shipped | Fail closed to MOCK |

Env: `INTELLIGENCE_AI_PROVIDER`, `INTELLIGENCE_NEWS_PROVIDER`, `INTELLIGENCE_CALENDAR_PROVIDER`, `INTELLIGENCE_PAID_PROVIDERS` (default false).
