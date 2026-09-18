import { useCallback, useMemo, useState } from 'react'
import { Radar, RefreshCw, ShieldAlert } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseEightApi } from '../api/services'
import type { SignalCandidate } from '../api/types'
import { useAuth } from '../auth/authState'
import { DataTable, DirectionBadge, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, StatusBadge } from '../components/ui'
import { useService } from '../hooks/useService'

const value = (v: string | number | null | undefined) => (v === null || v === undefined || v === '' ? '—' : String(v))

export function PhaseEightMarketScanner() {
  const { can } = useAuth()
  const [symbolFilter, setSymbolFilter] = useState('ALL')
  const [directionFilter, setDirectionFilter] = useState('ALL')
  const [busy, setBusy] = useState('')
  const [actionError, setActionError] = useState('')
  const [actionMsg, setActionMsg] = useState('')

  const boardLoader = useCallback(() => phaseEightApi.board({
    symbol: symbolFilter === 'ALL' ? undefined : symbolFilter,
    direction: directionFilter === 'ALL' ? undefined : directionFilter,
    limit: 50,
  }), [symbolFilter, directionFilter])
  const board = useService(boardLoader)
  const health = useService(useCallback(() => phaseEightApi.health(), []))

  const candidates: SignalCandidate[] = board.data?.queue?.candidates ?? []
  const matrix = board.data?.matrix ?? []
  const universe = board.data?.universe
  const lastRun = board.data?.last_run as Record<string, unknown> | null | undefined

  const symbols = useMemo(() => {
    const fromUniverse = (universe?.symbols as string[] | undefined) ?? ['EURUSD', 'XAUUSD']
    return ['ALL', ...fromUniverse]
  }, [universe])

  const runScan = async (trigger: 'MANUAL' | 'ON_CANDLE_CLOSE' = 'MANUAL') => {
    if (!can('strategies.update')) {
      setActionError('Your role cannot start scanner runs.')
      return
    }
    setBusy('scan'); setActionError(''); setActionMsg('')
    try {
      const result = await phaseEightApi.run({
        trigger,
        prefer: 'simulation',
        symbols: symbolFilter === 'ALL' ? undefined : [symbolFilter],
        timeframes: ['M5', 'M15'],
        create_signals: true,
        create_candidates: true,
      })
      const created = (result.orchestrator as { created?: number } | undefined)?.created ?? 0
      setActionMsg(result.idempotent
        ? 'Idempotent replay — same candle/interval key.'
        : `Scan complete · ${created} new candidates`)
      board.reload(); health.reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const act = async (label: string, fn: () => Promise<unknown>) => {
    setBusy(label); setActionError(''); setActionMsg('')
    try {
      await fn()
      setActionMsg(`${label} ok`)
      board.reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  if (board.loading && !board.data) return <LoadingState />
  if (board.error && !board.data) return <ErrorState message={board.error} />

  const healthData = health.data as Record<string, unknown> | null

  return <>
    <PageHeader
      title="Market Scanner"
      description="Multi-symbol / multi-timeframe opportunity board. Candidates are ranked setups — not broker orders."
      actions={<StatusBadge tone="info">PHASE 8 · CANDIDATES ONLY</StatusBadge>}
    />

    <div className="metrics-grid">
      <MetricCard label="Universe symbols" value={String(universe?.symbols?.length ?? 0)} detail={(universe?.symbols ?? []).join(', ') || '—'} />
      <MetricCard label="Active candidates" value={board.data?.queue?.count ?? 0} detail="Ranked queue (not orders)" />
      <MetricCard label="Last scan" value={lastRun?.status ? String(lastRun.status) : 'NONE'} detail={lastRun?.finished_at ? new Date(String(lastRun.finished_at)).toLocaleString() : 'Run a scan'} />
      <MetricCard label="order_send" value="NONE" detail="Broker routing disabled" />
    </div>

    {(actionError || actionMsg) && (
      <div className={actionError ? 'danger-banner' : 'info-banner'} role="status">
        {actionError ? <><ShieldAlert /> {actionError}</> : actionMsg}
      </div>
    )}

    <Panel title="Scanner controls" subtitle="ON_CANDLE_CLOSE · ON_INTERVAL · MANUAL">
      <div className="inline-controls" style={{ flexWrap: 'wrap', gap: '0.75rem' }}>
        <label className="field">
          <span>Symbol</span>
          <select value={symbolFilter} onChange={(e) => setSymbolFilter(e.target.value)}>
            {symbols.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </label>
        <label className="field">
          <span>Direction</span>
          <select value={directionFilter} onChange={(e) => setDirectionFilter(e.target.value)}>
            {['ALL', 'BUY', 'SELL'].map((d) => <option key={d} value={d}>{d}</option>)}
          </select>
        </label>
        <button className="btn" type="button" disabled={!!busy || !can('strategies.update')} onClick={() => runScan('MANUAL')}>
          <Radar /> {busy === 'scan' ? 'Scanning…' : 'Run scan'}
        </button>
        <button className="btn ghost" type="button" disabled={!!busy || !can('strategies.update')} onClick={() => runScan('ON_CANDLE_CLOSE')}>
          Candle-close scan
        </button>
        <button className="btn ghost" type="button" disabled={!!busy} onClick={() => { board.reload(); health.reload() }}>
          <RefreshCw /> Refresh
        </button>
      </div>
      <p className="muted" style={{ marginTop: '0.75rem' }}>{board.data?.disclaimer}</p>
    </Panel>

    <Panel title="Opportunity matrix" subtitle="Freshness + confluence per symbol/timeframe">
      {!matrix.length ? <EmptyState title="No matrix yet" detail="Run a scan to populate the multi-symbol board." /> : (
        <DataTable
          columns={['Symbol', 'TF', 'Direction', 'Confluence', 'Plugins', 'Quality', 'Tech', 'Candle key']}
          rows={matrix.map((row, i) => [
            <strong key={`s-${i}`}>{String(row.symbol)}</strong>,
            String(row.timeframe),
            <DirectionBadge key={`d-${i}`} value={String(row.direction) === 'BUY' || String(row.direction) === 'SELL' ? String(row.direction) as 'BUY' | 'SELL' : 'NO TRADE'} />,
            <b key={`c-${i}`}>{value(row.confluence_score as number)}</b>,
            Array.isArray(row.signal_plugins) ? (row.signal_plugins as string[]).join(', ') || '—' : '—',
            value(row.data_quality as string | number),
            <StatusBadge key={`t-${i}`} tone={String(row.technical_status) === 'READY' ? 'good' : 'warning'}>{String(row.technical_status ?? '—')}</StatusBadge>,
            <span key={`k-${i}`} className="muted" style={{ fontSize: '0.75rem' }}>{String(row.candle_close_key ?? '—').slice(0, 24)}</span>,
          ])}
        />
      )}
    </Panel>

    <Panel title="Candidate queue" subtitle="Orchestrated · conflict-aware · not an order book">
      {!candidates.length ? <EmptyState title="No active candidates" detail="Scans that find actionable setups enqueue ranked candidates here." /> : (
        <DataTable
          columns={['Rank', 'Symbol', 'TF', 'Dir', 'Plugin', 'Score', 'Conflicts', 'Simulate', 'Actions']}
          rows={candidates.map((c) => [
            <b key={`${c.public_id}-r`}>{Number(c.rank_score).toFixed(1)}</b>,
            <strong key={`${c.public_id}-s`}>{c.symbol}</strong>,
            c.timeframe,
            <DirectionBadge key={`${c.public_id}-d`} value={c.direction === 'BUY' || c.direction === 'SELL' ? c.direction : 'NO TRADE'} />,
            c.plugin_key ?? '—',
            value(c.confluence_score),
            (c.conflict_flags?.length ?? 0) > 0
              ? <StatusBadge key={`${c.public_id}-cf`} tone="warning">{c.conflict_flags!.map((f) => f.code).join(', ')}</StatusBadge>
              : <StatusBadge key={`${c.public_id}-ok`} tone="good">CLEAR</StatusBadge>,
            c.marked_for_simulate ? <StatusBadge key={`${c.public_id}-sim`} tone="info">MARKED</StatusBadge> : '—',
            <div key={`${c.public_id}-a`} className="inline-controls">
              {can('simulation_lifecycle.create') && !c.marked_for_simulate && (
                <button className="btn ghost" type="button" disabled={!!busy} onClick={() => act('Mark SIM', () => phaseEightApi.markSimulate(c.public_id))}>SIM</button>
              )}
              {can('signals.view') && (
                <button className="btn ghost" type="button" disabled={!!busy} onClick={() => act('Dismiss', () => phaseEightApi.dismiss(c.public_id))}>Dismiss</button>
              )}
            </div>,
          ])}
        />
      )}
    </Panel>

    <Panel title="Scanner health" subtitle="Heartbeat · last scan · alert foundation">
      {health.loading ? <LoadingState /> : health.error ? <ErrorState message={health.error} /> : (
        <div className="settings-list">
          <div><span>Status</span><strong>{String(healthData?.status ?? '—')}</strong></div>
          <div><span>Phase</span><strong>{String(healthData?.phase ?? 8)}</strong></div>
          <div><span>Last scan symbols</span><strong>{String((healthData?.last_scan as { symbols_scanned?: number } | undefined)?.symbols_scanned ?? '—')}</strong></div>
          <div><span>Last candidates</span><strong>{String((healthData?.last_scan as { candidates_created?: number } | undefined)?.candidates_created ?? '—')}</strong></div>
          <div><span>Alert pipeline</span><strong>{String((healthData?.alerts as { status?: string } | undefined)?.status ?? '—')}</strong></div>
          <div><span>Broker routing</span><strong>FALSE</strong></div>
        </div>
      )}
    </Panel>
  </>
}
