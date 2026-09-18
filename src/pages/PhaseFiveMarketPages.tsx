import { useCallback, useMemo, useState } from 'react'
import { marketApi } from '../api/services'
import type { MarketCandleBar, MarketQuote, MarketSnapshot } from '../api/types'
import { CandlestickTerminal } from '../components/TradingCharts'
import { DataTable, EmptyState, ErrorState, FilterBar, LoadingState, MetricCard, PageHeader, Panel, StatusBadge } from '../components/ui'
import { useTradingSource } from '../context/tradingSourceState'
import type { LegacyMockCandle } from '../domain/types'
import { useService } from '../hooks/useService'

function qualityTone(status: string): 'good' | 'info' | 'warning' | 'bad' | 'neutral' {
  if (status === 'EXCELLENT' || status === 'GOOD') return 'good'
  if (status === 'DEGRADED') return 'warning'
  if (status === 'POOR' || status === 'INVALID') return 'bad'
  return 'neutral'
}

function freshnessTone(status: string): 'good' | 'warning' | 'bad' | 'neutral' {
  if (status === 'FRESH') return 'good'
  if (status === 'STALE') return 'warning'
  return 'neutral'
}

function preferFromSource(source: string): 'auto' | 'bridge' | 'simulation' {
  return source === 'MT5_DEMO' ? 'auto' : 'simulation'
}

function toChartCandles(bars: MarketCandleBar[]): LegacyMockCandle[] {
  return bars
    .filter((bar) => bar.quality.usable && bar.open && bar.high && bar.low && bar.close)
    .map((bar) => ({
      time: Math.floor(new Date(bar.open_time).getTime() / 1000),
      open: Number(bar.open),
      high: Number(bar.high),
      low: Number(bar.low),
      close: Number(bar.close),
      volume: Number(bar.tick_volume ?? 0),
    }))
    .filter((bar) => Number.isFinite(bar.time) && bar.high >= bar.low)
}

export function PhaseFiveMarketWatch() {
  const { source } = useTradingSource()
  const prefer = preferFromSource(source)
  const [search, setSearch] = useState('')
  const loader = useCallback(
    () => marketApi.snapshot({ prefer, candle_count: 20, persist: true }),
    [prefer],
  )
  const result = useService(loader)
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'Market snapshot unavailable.'} />
  const snapshot = result.data
  const quotes = snapshot.quotes.filter((quote) => quote.symbol.includes(search.toUpperCase()))
  return (
    <>
      <PageHeader
        title="Market Watch"
        description="Phase 5 Market Data Engine snapshot — quotes with freshness validation and quality scores."
        actions={<StatusBadge tone={qualityTone(snapshot.summary.overall_quality_status)}>{snapshot.summary.overall_quality_status}</StatusBadge>}
      />
      <div className="info-banner">
        Read-only market data engine · source {snapshot.source} · environment {snapshot.environment}
        {snapshot.ingestion ? ` · ingestion ${snapshot.ingestion}` : ''}. No order_send / broker execution.
      </div>
      <div className="metric-grid">
        <MetricCard label="Quotes" value={String(snapshot.summary.quote_count)} detail={`${snapshot.summary.usable_quote_count} usable`} />
        <MetricCard label="Stale" value={String(snapshot.summary.stale_quote_count)} detail="Freshness gate" />
        <MetricCard label="Quality" value={String(snapshot.summary.overall_quality_score)} detail={snapshot.summary.overall_quality_status} />
        <MetricCard label="Symbols" value={String(snapshot.summary.symbol_count)} detail={snapshot.read_only ? 'READ-ONLY' : 'UNSAFE'} />
      </div>
      <Panel title="Validated quotes" subtitle={`Generated ${snapshot.generated_at}`}>
        <FilterBar search={search} searchId="phase5-market-watch-search" onSearch={setSearch}>
          <button className="btn ghost" type="button" onClick={() => result.reload()}>REFRESH SNAPSHOT</button>
        </FilterBar>
        {!quotes.length ? <EmptyState title="No quotes" detail="No symbols matched the current filter." /> : (
          <QuoteQualityTable quotes={quotes} />
        )}
      </Panel>
      <Panel title="Phase 6 / 7 extension hooks" subtitle="Stub only — not implemented in Phase 5">
        <div className="settings-list">
          <div><span>Indicator engine</span><strong>{snapshot.summary.extension_hooks.phase_6_indicator_engine}</strong></div>
          <div><span>Strategies</span><strong>{snapshot.summary.extension_hooks.phase_7_strategies}</strong></div>
        </div>
      </Panel>
    </>
  )
}

function QuoteQualityTable({ quotes }: { quotes: MarketQuote[] }) {
  return (
    <DataTable
      columns={['Symbol', 'Bid', 'Ask', 'Spread', 'Freshness', 'Quality', 'Issues', 'Usable']}
      rows={quotes.map((quote) => [
        <strong key={`${quote.symbol}-sym`}>{quote.symbol}</strong>,
        quote.bid ?? '—',
        quote.ask ?? '—',
        quote.spread ?? '—',
        <StatusBadge key={`${quote.symbol}-fresh`} tone={freshnessTone(quote.freshness.status)}>{quote.freshness.status}</StatusBadge>,
        <StatusBadge key={`${quote.symbol}-quality`} tone={qualityTone(quote.quality.status)}>{`${quote.quality.status} (${quote.quality.score})`}</StatusBadge>,
        quote.quality.issues.length ? quote.quality.issues.join(', ') : '—',
        quote.quality.usable ? 'YES' : 'NO',
      ])}
    />
  )
}

export function PhaseFiveLiveCharts() {
  const { source } = useTradingSource()
  const prefer = preferFromSource(source)
  const [symbol, setSymbol] = useState('EURUSD')
  const [timeframe, setTimeframe] = useState('M5')
  const loader = useCallback(
    () => marketApi.snapshot({
      prefer,
      candle_symbol: symbol,
      timeframe,
      candle_count: 80,
      persist: false,
    }),
    [prefer, symbol, timeframe],
  )
  const result = useService(loader)
  const chartCandles = useMemo(
    () => (result.data ? toChartCandles(result.data.candles.bars) : []),
    [result.data],
  )
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'Market candles unavailable.'} />
  const snapshot: MarketSnapshot = result.data
  const usableBars = snapshot.candles.bars.filter((bar) => bar.quality.usable)
  return (
    <>
      <PageHeader
        title="Live Charts"
        description="OHLCV from the Market Data Engine snapshot. Bad/stale bars are excluded from the chart series."
        actions={(
          <div className="inline-controls">
            <select className="select-btn" value={symbol} onChange={(event) => setSymbol(event.target.value)} aria-label="Chart symbol">
              {(snapshot.symbols.length ? snapshot.symbols.map((item) => item.symbol) : ['EURUSD', 'XAUUSD']).map((item) => (
                <option key={item} value={item}>{item}</option>
              ))}
            </select>
            <select className="select-btn" value={timeframe} onChange={(event) => setTimeframe(event.target.value)} aria-label="Chart timeframe">
              {['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1'].map((item) => <option key={item} value={item}>{item}</option>)}
            </select>
          </div>
        )}
      />
      <div className="info-banner">
        {snapshot.source} · {snapshot.environment} · quality {snapshot.summary.overall_quality_status}
        · Phase 6 indicators PENDING
      </div>
      <Panel title={`${symbol} · ${timeframe}`} subtitle={`${usableBars.length} usable / ${snapshot.candles.bars.length} bars`}>
        {chartCandles.length ? (
          <CandlestickTerminal candles={chartCandles} showGuides={false} label={`${symbol} market-data chart`} />
        ) : (
          <EmptyState title="No usable candles" detail="Quality validation removed all bars for this request." />
        )}
      </Panel>
      <Panel title="Recent bars" subtitle="Including quality metadata">
        <DataTable
          columns={['Open time', 'Open', 'High', 'Low', 'Close', 'Volume', 'Quality']}
          rows={usableBars.slice(-12).map((bar) => [
            bar.open_time,
            bar.open ?? '—',
            bar.high ?? '—',
            bar.low ?? '—',
            bar.close ?? '—',
            String(bar.tick_volume),
            <StatusBadge key={`${bar.open_time}-q`} tone={qualityTone(bar.quality.status)}>{bar.quality.status}</StatusBadge>,
          ])}
        />
      </Panel>
    </>
  )
}
