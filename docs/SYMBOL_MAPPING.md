# Symbol Mapping

Canonical instruments live in `trading_instruments`. Broker aliases use Phase 4 `instrument_aliases`.

Market engine sync upserts `market_symbols` without deleting historical rows when a broker temporarily omits a symbol.
