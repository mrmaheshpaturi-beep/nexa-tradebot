import { useCallback, useMemo, useState } from 'react'
import { indicatorApi, marketApi } from '../api/services'
import type { IndicatorResult, MarketCandleBar, MarketQuote, MarketSnapshot } from '../api/types'
import { CandlestickTerminal, type ChartOverlaySeries } from '../components/TradingCharts'
import { DataTable, EmptyState, ErrorState, FilterBar, LoadingState, MetricCard, PageHeader, Panel, StatusBadge } from '../components/ui'
import { useTradingSource } from '../context/tradingSourceState'
import type { LegacyMockCandle } from '../domain/types'
import { useService } from '../hooks/useService'

function qualityTone(status: string): 'good' | 'info' | 'warning' | 'bad' | 'neutral' {
  if (status === 'EXCELLENT' || status === 'GOOD' || status === 'READY') return 'good'
  if (status === 'DEGRADED') return 'warning'
  if (status === 'POOR' || status === 'INVALID' || status === 'BAD' || status === 'UNAVAILABLE' || status === 'REFUSED') return 'bad'
  return 'neutral'
}

function freshnessTone(status: string): 'good' | 'warning' | 'bad' | 'neutral' | 'info' {
  if (status === 'FRESH') return 'good'
  if (status === 'AGING') return 'info'
  if (status === 'STALE' || status === 'UNAVAILABLE') return 'warning'
  return 'neutral'
}

/** MT5 DEMO never silently falls back to mock. */
function preferFromSource(source: string): 'bridge' | 'simulation' {
  return source === 'MT5_DEMO' ? 'bridge' : 'simulation'
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

const OVERLAY_COLORS = ['#3794ff', '#f0b429', '#a78bfa', '#27d7a1', '#ff8f6b']

function seriesToOverlays(results: IndicatorResult[]): ChartOverlaySeries[] {
  const overlays: ChartOverlaySeries[] = []
  let colorIndex = 0
  for (const result of results) {
    if (result.status === 'REFUSED' || !result.series.length) continue
    if (result.indicator === 'BBANDS') {
      for (const key of ['upper', 'middle', 'lower'] as const) {
        const points = result.series
          .map((point) => {
            const raw = point[key]
            const value = raw == null ? NaN : Number(raw)
            const time = Math.floor(new Date(point.time).getTime() / 1000)
            return { time, value }
          })
          .filter((point) => Number.isFinite(point.time) && Number.isFinite(point.value))
        overlays.push({
          id: `BB ${key}`,
          color: key === 'middle' ? '#f0b429' : '#64748b',
          points,
        })
      }
      continue
    }
    if (!result.overlay && result.indicator !== 'SMA' && result.indicator !== 'EMA') {
      continue
    }
    const points = result.series
      .map((point) => {
        const raw = point.value
        const value = raw == null ? NaN : Number(raw)
        const time = Math.floor(new Date(point.time).getTime() / 1000)
        return { time, value }
      })
      .filter((point) => Number.isFinite(point.time) && Number.isFinite(point.value))
    overlays.push({
      id: result.indicator,
      color: OVERLAY_COLORS[colorIndex % OVERLAY_COLORS.length],
      points,
    })
    colorIndex += 1
  }
  return overlays
}

export function PhaseFiveMarketWatch() {
  const { source } = useTradingSource()
  const prefer = preferFromSource(source)
  const [search, setSearch] = useState('')
  const [asset, setAsset] = useState('All')
  const loader = useCallback(
    () => marketApi.snapshot({ prefer, candle_count: 40, persist: true }),
    [prefer],
  )
  const result = useService(loader)
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) {
    return (
      <>
        <PageHeader title="Market Watch" description="Phase 5 Market Data Engine." />
        <ErrorState message={prefer === 'bridge' ? 'MT5 DATA UNAVAILABLE' : (result.error ?? 'Market snapshot unavailable.')} />
      </>
    )
  }
  const snapshot = result.data
  const quotes = snapshot.quotes.filter((quote) => {
    if (!quote.symbol.includes(search.toUpperCase())) return false
    if (asset === 'All') return true
    const cls = quote.market?.asset_class ?? 'FOREX'
    return cls === asset.toUpperCase() || (asset === 'Forex' && cls === 'FOREX')
  })
  return (
    <>
      <PageHeader
        title="Market Watch"
        description="Phase 5 Market Data Engine — validated quotes with freshness, session, and quality."
        actions={<StatusBadge tone={qualityTone(snapshot.summary.overall_quality_status)}>{snapshot.summary.overall_quality_status}</StatusBadge>}
      />
      <div className="info-banner">
        Read-only · source {snapshot.source} · environment {snapshot.environment}
        {snapshot.ingestion ? ` · ingestion ${snapshot.ingestion}` : ''} · no silent mock fallback in MT5 DEMO · no order_send
      </div>
      <div className="metric-grid">
        <MetricCard label="Quotes" value={String(snapshot.summary.quote_count)} detail={`${snapshot.summary.usable_quote_count} usable`} />
        <MetricCard label="Stale" value={String(snapshot.summary.stale_quote_count)} detail="Freshness gate" />
        <MetricCard label="Quality" value={String(snapshot.summary.overall_quality_score)} detail={snapshot.data_quality?.status ?? snapshot.summary.overall_quality_status} />
        <MetricCard label="Analysis gate" value={snapshot.data_quality_gate?.allowed ? 'OPEN' : 'BLOCKED'} detail="Indicator / strategy prep" />
      </div>
      <Panel title="Validated quotes" subtitle={`Generated ${snapshot.generated_at}`}>
        <FilterBar search={search} searchId="phase5-market-watch-search" onSearch={setSearch}>
          {['All', 'Forex', 'METAL', 'INDEX', 'CRYPTO'].map((item) => (
            <button key={item} className={`chip ${asset === item ? 'active' : ''}`} onClick={() => setAsset(item)}>{item}</button>
          ))}
          <button className="btn ghost" type="button" onClick={() => result.reload()}>REFRESH</button>
        </FilterBar>
        {!quotes.length ? <EmptyState title="No quotes" detail="No symbols matched the current filter." /> : (
          <QuoteQualityTable quotes={quotes} />
        )}
      </Panel>
      <Panel title="Phase 6 / 7 extension hooks" subtitle="Indicator engine READY · strategies READY">
        <div className="settings-list">
          <div><span>Indicator engine</span><strong>{snapshot.summary.extension_hooks.phase_6_indicator_engine}</strong></div>
          <div><span>get_closed_candles</span><strong>{snapshot.summary.extension_hooks.phase_6_get_closed_candles ?? 'READY'}</strong></div>
          <div><span>Strategies</span><strong>{snapshot.summary.extension_hooks.phase_7_strategies}</strong></div>
        </div>
      </Panel>
    </>
  )
}

function QuoteQualityTable({ quotes }: { quotes: MarketQuote[] }) {
  return (
    <DataTable
      columns={['Symbol', 'Bid', 'Ask', 'Spread', 'Chg %', 'High', 'Low', 'Session', 'Status', 'Freshness', 'Quality', 'Source']}
      rows={quotes.map((quote) => [
        <strong key={`${quote.symbol}-sym`}>{quote.symbol}</strong>,
        quote.bid ?? '—',
        quote.ask ?? '—',
        quote.spread ?? '—',
        quote.change_percent ?? '—',
        quote.daily_high ?? '—',
        quote.daily_low ?? '—',
        quote.market?.session ?? '—',
        <StatusBadge key={`${quote.symbol}-mkt`} tone={quote.market?.status === 'OPEN' ? 'good' : 'warning'}>{quote.market?.status ?? 'UNKNOWN'}</StatusBadge>,
        <StatusBadge key={`${quote.symbol}-fresh`} tone={freshnessTone(quote.freshness.status)}>{quote.freshness.status}</StatusBadge>,
        <StatusBadge key={`${quote.symbol}-quality`} tone={qualityTone(quote.quality.status)}>{`${quote.quality.status} (${quote.quality.score})`}</StatusBadge>,
        `${quote.source}/${quote.environment}`,
      ])}
    />
  )
}

export function PhaseFiveLiveCharts() {
  const { source } = useTradingSource()
  const prefer = preferFromSource(source)
  const [symbol, setSymbol] = useState('EURUSD')
  const [timeframe, setTimeframe] = useState('M5')
  const [activeOverlays, setActiveOverlays] = useState<string[]>(['SMA', 'EMA', 'BBANDS'])
  const [panelIndicators, setPanelIndicators] = useState<string[]>(['RSI', 'MACD', 'ATR'])

  const marketLoader = useCallback(
    () => marketApi.snapshot({
      prefer,
      candle_symbol: symbol,
      timeframe,
      candle_count: 120,
      persist: false,
    }),
    [prefer, symbol, timeframe],
  )
  const marketResult = useService(marketLoader)

  const indicatorLoader = useCallback(async () => {
    const names = [...new Set([...activeOverlays, ...panelIndicators])]
    if (!names.length) return [] as IndicatorResult[]
    const batch = await indicatorApi.batch({
      indicators: names,
      symbol,
      timeframe,
      count: 120,
      prefer,
    })
    return batch.results
  }, [activeOverlays, panelIndicators, prefer, symbol, timeframe])
  const indicatorResult = useService(indicatorLoader)

  const chartCandles = useMemo(
    () => (marketResult.data ? toChartCandles(marketResult.data.candles.bars) : []),
    [marketResult.data],
  )
  const overlays = useMemo(
    () => seriesToOverlays((indicatorResult.data ?? []).filter((row) => activeOverlays.includes(row.indicator))),
    [activeOverlays, indicatorResult.data],
  )
  const panelRows = useMemo(
    () => (indicatorResult.data ?? []).filter((row) => panelIndicators.includes(row.indicator)),
    [indicatorResult.data, panelIndicators],
  )

  if (marketResult.loading) return <LoadingState />
  if (marketResult.error || !marketResult.data) {
    return (
      <>
        <PageHeader title="Live Charts" description="Phase 5 candle service + Phase 6 indicators." />
        <ErrorState message={prefer === 'bridge' ? 'MT5 DATA UNAVAILABLE' : (marketResult.error ?? 'Market candles unavailable.')} />
      </>
    )
  }
  const snapshot: MarketSnapshot = marketResult.data
  const usableBars = snapshot.candles.bars.filter((bar) => bar.quality.usable)
  const closed = usableBars.filter((bar) => bar.is_closed !== false)
  const forming = usableBars.filter((bar) => bar.is_closed === false)
  const indicatorError = prefer === 'bridge' && indicatorResult.error?.includes('MT5')
    ? 'MT5 DATA UNAVAILABLE'
    : indicatorResult.error

  return (
    <>
      <PageHeader
        title="Live Charts"
        description="OHLCV from Market Data Engine with Indicator Engine overlays. Closed candles only for indicators."
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
        · closed {snapshot.candles.closed_count ?? closed.length} · forming {snapshot.candles.forming_count ?? forming.length}
        · Phase 6 indicators {snapshot.summary.extension_hooks.phase_6_indicator_engine}
      </div>
      <Panel title="Indicator overlays" subtitle="Toggle SMA / EMA / Bollinger on the price chart">
        <div className="inline-controls" style={{ flexWrap: 'wrap', gap: 8 }}>
          {['SMA', 'EMA', 'BBANDS'].map((name) => {
            const active = activeOverlays.includes(name)
            return (
              <button
                key={name}
                type="button"
                className={`chip ${active ? 'active' : ''}`}
                onClick={() => setActiveOverlays((current) => (
                  active ? current.filter((item) => item !== name) : [...current, name]
                ))}
              >
                {name}
              </button>
            )
          })}
          <button className="btn ghost" type="button" onClick={() => { marketResult.reload(); indicatorResult.reload() }}>REFRESH</button>
        </div>
      </Panel>
      <Panel title={`${symbol} · ${timeframe}`} subtitle={`${usableBars.length} usable / ${snapshot.candles.bars.length} bars · ${overlays.length} overlay series`}>
        {chartCandles.length ? (
          <CandlestickTerminal
            candles={chartCandles}
            overlays={overlays}
            showGuides={false}
            label={`${symbol} market-data chart with indicators`}
          />
        ) : (
          <EmptyState title="No usable candles" detail="Quality validation removed all bars for this request." />
        )}
      </Panel>
      <Panel title="Indicator panel" subtitle="Oscillators / volatility from closed candles · source & freshness shown">
        <div className="inline-controls" style={{ flexWrap: 'wrap', gap: 8, marginBottom: 12 }}>
          {['RSI', 'MACD', 'ATR'].map((name) => {
            const active = panelIndicators.includes(name)
            return (
              <button
                key={name}
                type="button"
                className={`chip ${active ? 'active' : ''}`}
                onClick={() => setPanelIndicators((current) => (
                  active ? current.filter((item) => item !== name) : [...current, name]
                ))}
              >
                {name}
              </button>
            )
          })}
        </div>
        {indicatorResult.loading ? <LoadingState /> : null}
        {indicatorError ? <ErrorState message={indicatorError} /> : null}
        {!indicatorResult.loading && !indicatorError && !panelRows.length ? (
          <EmptyState title="No panel indicators" detail="Enable RSI, MACD, or ATR above." />
        ) : null}
        {panelRows.length ? (
          <DataTable
            columns={['Indicator', 'Status', 'Latest', 'Points', 'Source', 'Freshness', 'Quality', 'Gate']}
            rows={panelRows.map((row) => [
              <strong key={`${row.indicator}-name`}>{row.indicator}</strong>,
              <StatusBadge key={`${row.indicator}-status`} tone={qualityTone(row.status)}>{row.status}</StatusBadge>,
              formatLatest(row),
              String(row.point_count),
              `${row.source}/${row.environment}`,
              <StatusBadge key={`${row.indicator}-fresh`} tone={freshnessTone(row.freshness.status)}>{row.freshness.status}</StatusBadge>,
              <StatusBadge key={`${row.indicator}-quality`} tone={qualityTone(row.quality.status)}>{row.quality.status}</StatusBadge>,
              row.gate.allowed ? 'OPEN' : (row.gate.reason ?? 'BLOCKED'),
            ])}
          />
        ) : null}
      </Panel>
      <Panel title="Recent bars" subtitle="Including closed/forming + quality metadata">
        <DataTable
          columns={['Open time', 'State', 'Open', 'High', 'Low', 'Close', 'Volume', 'Quality']}
          rows={usableBars.slice(-12).map((bar) => [
            bar.open_time,
            bar.is_closed === false ? 'FORMING' : 'CLOSED',
            bar.open ?? '—',
            bar.high ?? '—',
            bar.low ?? '—',
            bar.close ?? '—',
            bar.tick_volume ?? '—',
            bar.quality.status,
          ])}
        />
      </Panel>
    </>
  )
}

function formatLatest(row: IndicatorResult): string {
  if (row.status === 'REFUSED') return row.reason ?? 'REFUSED'
  if (row.indicator === 'MACD') {
    return `macd ${row.values.macd ?? '—'} / sig ${row.values.signal ?? '—'}`
  }
  if (row.indicator === 'BBANDS') {
    return `mid ${row.values.middle ?? '—'}`
  }
  return row.values.value ?? '—'
}
