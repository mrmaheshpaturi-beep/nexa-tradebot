# Trade Management Policy

Versioned `TradeManagementPolicy` controls break-even, trailing, partial levels, time/session/weekend exits, risk/emergency exits.

Policy migration is explicit: `KEEP_ORIGINAL` vs `MIGRATE`. Silent policy changes are forbidden. Positions store `management_policy_id` + `management_policy_version`.
