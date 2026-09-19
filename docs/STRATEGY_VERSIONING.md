# Strategy Versioning

## Phase 7 baseline

- `trading_strategies.version` increments on configuration / parameter / plugin changes
- `strategy_versions` stores configuration snapshots + change summary
- Signal fingerprint includes `configuration_version`

## Phase 16 governance versions

- `governed_strategy_versions` — immutable `semantic_version` (MAJOR.MINOR.PATCH)
- `code_hash` — sha256 of plugin key/class/defaults/category
- `config_hash` — sha256 of canonical configuration snapshot
- Lifecycle enforced by `LifecycleGuard` (see `PHASE_16_CONTRACT.md`)
- Illegal jumps rejected; APPROVED / DEPLOYED_DEMO require two-step human approval
- DEMO promotion binds AutomationProfile matrix rows to governed version + hashes
