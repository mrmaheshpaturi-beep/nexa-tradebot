import { useCallback, useState } from 'react'
import { firstValidationError } from '../api/client'
import { mt5Api } from '../api/services'
import { useAuth } from '../auth/authState'
import { DataTable, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, StatusBadge } from '../components/ui'
import { useTradingSource } from '../context/tradingSourceState'
import { useService } from '../hooks/useService'

function ReadOnlyBanner() {
  return <div className="info-banner">MT5 DEMO read-only source — no order placement, modification, cancellation, or broker execution is available.</div>
}

function Mt5Unavailable({ message }: { message: string }) {
  return <ErrorState message={message} />
}

export function Mt5Dashboard() {
  const { source } = useTradingSource()
  const result = useService(useCallback(() => Promise.all([mt5Api.status(), mt5Api.account().catch(() => null)]), []))
  if (source !== 'MT5_DEMO') return <Mt5Unavailable message="Switch the global source selector to MT5 DEMO to view external read models." />
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'MT5 status is unavailable.'} />
  const [status, accountEnvelope] = result.data
  const account = accountEnvelope?.data ?? {}
  return <>
    <PageHeader title="MT5 DEMO Dashboard" description="External account snapshot from the authenticated read-only bridge." actions={<StatusBadge tone={status.configured ? 'info' : 'warning'}>{status.configured ? status.circuit_state : 'NOT CONFIGURED'}</StatusBadge>} />
    <ReadOnlyBanner />
    {!status.configured && <div className="warning-box"><p><strong>Bridge not configured</strong><span>Laravel requires TRADING_BRIDGE_URL and TRADING_BRIDGE_SERVICE_TOKEN. No mock fallback is used in MT5 mode.</span></p></div>}
    <div className="metric-grid">
      <MetricCard label="Balance" value={String(account.balance ?? '—')} detail="MT5 DEMO external read model" />
      <MetricCard label="Equity" value={String(account.equity ?? '—')} />
      <MetricCard label="Free margin" value={String(account.free_margin ?? '—')} />
      <MetricCard label="Freshness" value={accountEnvelope?.meta.freshness ?? 'UNKNOWN'} />
    </div>
    <Panel title="Connection metadata" subtitle="Credential-free server-side bridge boundary">
      <div className="settings-list">
        <div><span>Mode</span><strong>{status.mode}</strong></div>
        <div><span>Environment</span><strong>{status.environment}</strong></div>
        <div><span>Execution</span><strong>DISABLED</strong></div>
        <div><span>Broker transmission</span><strong>FALSE</strong></div>
      </div>
    </Panel>
  </>
}

export function Mt5MarketWatch() {
  const { source } = useTradingSource()
  const result = useService(useCallback(() => mt5Api.symbols(), []))
  if (source !== 'MT5_DEMO') return <Mt5Unavailable message="Switch the global source selector to MT5 DEMO to view bridge quotes." />
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'MT5 symbols are unavailable.'} />
  return <>
    <PageHeader title="MT5 Market Watch" description="Symbol specifications and quotes from the read-only bridge." />
    <ReadOnlyBanner />
    <Panel title="Symbols" subtitle={`${result.data.data.length} external symbols · freshness ${result.data.meta.freshness}`}>
      <DataTable columns={['Symbol', 'Description', 'Digits', 'Min volume', 'Max volume']}
        rows={result.data.data.map((item) => [
          <strong key={String(item.symbol)}>{String(item.symbol ?? item.name ?? '—')}</strong>,
          String(item.description ?? '—'),
          String(item.digits ?? '—'),
          String(item.volume_min ?? '—'),
          String(item.volume_max ?? '—'),
        ])} />
    </Panel>
  </>
}

export function Mt5LiveCharts() {
  const { source } = useTradingSource()
  const [symbol, setSymbol] = useState('EURUSD')
  const loader = useCallback(() => mt5Api.candles(symbol, 'M5', 60), [symbol])
  const result = useService(loader)
  if (source !== 'MT5_DEMO') return <Mt5Unavailable message="Switch the global source selector to MT5 DEMO to view bridge candles." />
  return <>
    <PageHeader title="MT5 Live Charts" description="Historical candles from the read-only bridge. Not a live tick stream." actions={
      <select className="select-btn" value={symbol} onChange={(event) => setSymbol(event.target.value)} aria-label="Symbol">
        {['EURUSD', 'XAUUSD'].map((item) => <option key={item} value={item}>{item}</option>)}
      </select>
    } />
    <ReadOnlyBanner />
    {result.loading ? <LoadingState /> : result.error || !result.data ? <ErrorState message={result.error ?? 'MT5 candles are unavailable.'} /> : (
      <Panel title={`${symbol} · M5`} subtitle={`${result.data.data.length} candles · ${result.data.meta.freshness}`}>
        <DataTable columns={['Time', 'Open', 'High', 'Low', 'Close', 'Volume']}
          rows={result.data.data.slice(-12).map((item) => [
            String(item.time ?? '—'),
            String(item.open ?? '—'),
            String(item.high ?? '—'),
            String(item.low ?? '—'),
            String(item.close ?? '—'),
            String(item.tick_volume ?? item.volume ?? '—'),
          ])} />
      </Panel>
    )}
  </>
}

export function Mt5ReadModels() {
  const { source } = useTradingSource()
  const statusResult = useService(useCallback(() => mt5Api.status(), []))
  const mappingId = statusResult.data?.connection?.account_mappings?.[0]?.id
  const loader = useCallback(() => mappingId ? mt5Api.mappingPositions(mappingId) : Promise.reject(new Error('No synced MT5 mapping is available.')), [mappingId])
  const result = useService(loader)
  if (source !== 'MT5_DEMO') return <Mt5Unavailable message="Switch the global source selector to MT5 DEMO to view persisted external projections." />
  if (statusResult.loading || result.loading) return <LoadingState />
  if (!mappingId) return <EmptyState title="No synced mapping" detail="Create, test, enable, and sync an MT5 connection from MT5 Accounts." />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'Persisted MT5 positions are unavailable.'} />
  return <>
    <PageHeader title="MT5 Read Models" description="Persisted external positions synchronized from the bridge." />
    <ReadOnlyBanner />
    <Panel title="External positions" subtitle={`${result.data.total} persisted records`}>
      <DataTable columns={['Ticket', 'Symbol', 'Side', 'Volume', 'Open', 'Current', 'Profit', 'Last seen']}
        rows={result.data.data.map((item) => [
          item.external_id,
          item.symbol,
          item.side ?? '—',
          String(item.volume),
          String(item.price_open ?? '—'),
          String(item.price_current ?? '—'),
          String(item.profit),
          item.last_seen_at,
        ])} />
    </Panel>
  </>
}

export function Mt5ReconciliationPage() {
  const { can } = useAuth()
  const { source } = useTradingSource()
  const statusResult = useService(useCallback(() => mt5Api.status(), []))
  const runsResult = useService(useCallback(() => mt5Api.reconciliationRuns(), []))
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState('')
  const mappingId = statusResult.data?.connection?.account_mappings?.[0]?.id
  const runReconcile = async () => {
    if (!mappingId) return
    setBusy(true); setActionError('')
    try { await mt5Api.reconcileMapping(mappingId); runsResult.reload() } catch (error) { setActionError(firstValidationError(error)) } finally { setBusy(false) }
  }
  if (source !== 'MT5_DEMO') return <Mt5Unavailable message="Switch the global source selector to MT5 DEMO for report-only reconciliation." />
  if (statusResult.loading || runsResult.loading) return <LoadingState />
  return <>
    <PageHeader title="MT5 Reconciliation" description="Report-only comparison between persisted projections and live bridge reads."
      actions={can('mt5.reconcile') ? <button className="btn ghost" disabled={!mappingId || busy} onClick={runReconcile}>RUN RECONCILIATION</button> : undefined} />
    <ReadOnlyBanner />
    {actionError && <div className="danger-banner" role="alert">{actionError}</div>}
    {!can('mt5.reconcile') && <p className="permission-note">Your role can view reconciliation output but cannot start a run.</p>}
    <Panel title="Recent runs" subtitle={`${runsResult.data?.total ?? 0} persisted reconciliation runs`}>
      {!runsResult.data?.data.length ? <EmptyState title="No reconciliation runs" detail="Sync an MT5 mapping, then run report-only reconciliation." /> : (
        <DataTable columns={['Run', 'Status', 'Matched', 'Mismatches', 'Started', 'Completed']}
          rows={runsResult.data.data.map((run) => [
            run.public_id,
            <StatusBadge key={run.public_id} tone={run.status === 'MATCHED' ? 'good' : 'warning'}>{run.status}</StatusBadge>,
            String(run.matched_count),
            String(run.mismatch_count),
            run.started_at,
            run.completed_at ?? '—',
          ])} />
      )}
    </Panel>
  </>
}

export function Mt5AccountsPage() {
  const { can } = useAuth()
  const { source } = useTradingSource()
  const result = useService(useCallback(() => mt5Api.connections(), []))
  const [busyId, setBusyId] = useState<number>()
  const [actionError, setActionError] = useState('')
  const [actionInfo, setActionInfo] = useState('')
  const create = async () => {
    setActionError('')
    setActionInfo('')
    const name = window.prompt('Connection name', 'Primary MT5 DEMO bridge')
    if (!name?.trim()) return
    try {
      await mt5Api.createConnection(name.trim(), false)
      setActionInfo('Connection created. Run TEST, then ENABLE, then SYNC.')
      result.reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    }
  }
  const test = async (id: number) => {
    setBusyId(id); setActionError(''); setActionInfo('')
    try {
      const response = await mt5Api.testConnection(id)
      setActionInfo(`Test finished: ${response.connection.status}. ${response.connection.status === 'CONNECTED' ? 'Click ENABLE, then SYNC.' : 'Check Windows bridge + Cloudflare tunnel.'}`)
      result.reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusyId(undefined)
    }
  }
  const setEnabled = async (id: number, isEnabled: boolean) => {
    setBusyId(id); setActionError(''); setActionInfo('')
    try {
      await mt5Api.setConnectionEnabled(id, isEnabled)
      setActionInfo(isEnabled ? 'Enabled. Click SYNC to pull DEMO account data.' : 'Connection disabled.')
      result.reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusyId(undefined)
    }
  }
  const sync = async (id: number) => {
    setBusyId(id); setActionError(''); setActionInfo('')
    try {
      await mt5Api.syncConnection(id)
      setActionInfo('Sync completed. Open MT5 Dashboard / Read Models and set Source to MT5 DEMO.')
      result.reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusyId(undefined)
    }
  }
  const remove = async (id: number, name: string) => {
    if (!window.confirm(`Delete connection “${name}”? This cannot be undone.`)) return
    setBusyId(id); setActionError(''); setActionInfo('')
    try {
      await mt5Api.deleteConnection(id)
      setActionInfo('Connection deleted.')
      result.reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusyId(undefined)
    }
  }
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'MT5 connections are unavailable.'} />
  return <>
    <PageHeader title="MT5 Accounts" description="Read-only bridge connections and sync controls. Credentials remain server-side only."
      actions={can('mt5.connections.manage') ? <button className="btn ghost" onClick={create}>ADD CONNECTION</button> : undefined} />
    {source === 'MT5_DEMO' ? <ReadOnlyBanner /> : <div className="info-banner">Simulation source is active. Switch to MT5 DEMO to inspect external connectivity without mock fallback.</div>}
    <div className="info-banner">Flow: ADD CONNECTION → TEST → ENABLE → SYNC. Keep Windows MT5 DEMO + bridge + cloudflared tunnel running.</div>
    {actionInfo && <div className="info-banner" role="status">{actionInfo}</div>}
    {actionError && <div className="danger-banner" role="alert">{actionError}</div>}
    <Panel title="Connections" subtitle={`${result.data.total} persisted records`}>
      <DataTable columns={['Name', 'Environment', 'Status', 'Enabled', 'Last tested', 'Actions']}
        rows={result.data.data.map((connection) => [
          connection.name,
          connection.environment,
          <StatusBadge key={`${connection.id}-status`} tone={connection.status === 'CONNECTED' ? 'good' : 'warning'}>{connection.status}</StatusBadge>,
          connection.is_enabled ? 'YES' : 'NO',
          connection.last_tested_at ?? '—',
          <div key={`${connection.id}-actions`} className="inline-controls">
            {can('mt5.connections.manage') && <button className="btn ghost" disabled={busyId === connection.id} onClick={() => test(connection.id)}>TEST</button>}
            {can('mt5.connections.manage') && !connection.is_enabled && (
              <button className="btn ghost" disabled={busyId === connection.id || connection.status !== 'CONNECTED'} onClick={() => setEnabled(connection.id, true)}>ENABLE</button>
            )}
            {can('mt5.connections.manage') && connection.is_enabled && (
              <button className="btn ghost" disabled={busyId === connection.id} onClick={() => setEnabled(connection.id, false)}>DISABLE</button>
            )}
            {can('mt5.sync') && <button className="btn ghost" disabled={busyId === connection.id || !connection.is_enabled} onClick={() => sync(connection.id)}>SYNC</button>}
            {can('mt5.connections.manage') && (
              <button className="btn ghost" disabled={busyId === connection.id} onClick={() => remove(connection.id, connection.name)}>DELETE</button>
            )}
          </div>,
        ])} />
    </Panel>
  </>
}
