# Risk Profiles

Persistent, user-owned risk configuration attached to simulation broker accounts.

## Core limits (Phase 2+)

Max risk/trade, lot, daily/weekly loss, drawdown, open positions, open risk, trades/day, consecutive losses, min margin level, max spread (pips), max slippage, min reward/risk.

## Phase 9 additions

| Field | Purpose |
|---|---|
| `version` | Auto-increments on material config changes |
| `rules_bundle_version` | Which rule pack evaluates the profile |
| `config_hash` | Reproducibility fingerprint |
| `rule_config` | Optional rule toggles (e.g. `enforce_market_hours`) |
| `session_allowlist` | Optional UTC session name allowlist |
| `max_correlated_exposure` | Correlated-symbol risk cap (%) |
| `atr_stop_multiplier` | Optional ATR-aware stop floor |
| `require_stop_loss` | Default true |
| `sizing_enabled` | Equity/stop-distance sizing (default true) |

## Audit

Profile create/update remains audited. Decisions stamp `profile_version` + `config_hash`.
