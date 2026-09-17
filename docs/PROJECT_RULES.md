# Nexa TradeBot Project Rules

These rules are immutable unless the product owner explicitly approves a new project phase.

1. Phase 1 is simulation-only. No feature may connect to a broker, MetaTrader terminal, or real funds.
2. `SIMULATION` must remain visually prominent on all trading-sensitive surfaces.
3. Manual actions are named `SIMULATE BUY` and `SIMULATE SELL`; auto trading cannot be enabled.
4. AI scores are mock display values and must never be represented as predictions or guarantees.
5. The future risk engine is authoritative. AI and strategy modules must never bypass it or call MT5 directly.
6. Signal, Order, Deal, and Position are distinct domain concepts.
7. UI modules consume typed service interfaces, never scattered fixtures or transport details.
8. Secrets, credentials, broker passwords, keys, and `.env` files must never enter source control or frontend variables.
9. Laravel persistence must remain compatible with MySQL and PostgreSQL; vendor-specific SQL requires review.
10. Loading, empty, error, and success states are first-class states.
11. Type, lint, test, backend test, and production-build gates cannot be disabled to obtain a pass.
12. No work from Phase 2 or later may begin without explicit approval.
