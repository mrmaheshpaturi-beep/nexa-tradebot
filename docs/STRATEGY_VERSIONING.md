# Strategy Versioning

- `trading_strategies.version` increments on configuration / parameter / plugin changes
- `strategy_versions` stores configuration snapshots + change summary
- Signal fingerprint includes `configuration_version` so config changes do not collide with prior fingerprints
