# Strategy Plugin Contract

Plugins implement `App\Contracts\TradingStrategyPlugin`.

- `key()`, `name()`, `category()`, `description()`, `evidenceFamily()`, `defaultParameters()`, `evaluate(StrategyContext): StrategyEvaluation`
- Built-in registry only — **no arbitrary code upload**
- Deterministic; closed candles only; no look-ahead; no randomness
- Evidence families prevent confluence double-counting
