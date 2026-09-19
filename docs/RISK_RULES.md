# Risk Rules

Modular, versioned rule plugins under `App\Risk\Rules`. Bundle version: `risk-rules/v1`.

| Priority | Code | Behavior |
|---|---|---|
| 10 | `ENVIRONMENT_ACCOUNT` | SIMULATION only; emergency stop; simulation switch; active account/profile |
| 15 | `RISK_LOCK` | Active locks block new intents |
| 20 | `SYMBOL_SPECS_QUALITY` | Enabled instrument; required specs; quote quality; equity context |
| 30 | `VOLUME_SIZING` | Instrument min/max/step; max lot; max risk % |
| 40 | `STOP_AND_RR` | Protection sides; min stop distance; optional ATR multiplier; min R:R |
| 50 | `LOSS_DRAWDOWN` | Daily/weekly loss %; drawdown; consecutive losses |
| 60 | `EXPOSURE_CORRELATION` | Max positions; trades/day; open risk; correlated exposure |
| 70 | `MARGIN_SPREAD_SESSION` | Free margin + reservations; margin level; spread (pips→points); session allowlist |

Rules short-circuit on first failure (fail closed). Results are stored on `RiskDecision.rule_results`.

## Extension

Implement `App\Risk\Contracts\RiskRule`, register in `RiskRuleRegistry`, bump bundle version when semantics change.
