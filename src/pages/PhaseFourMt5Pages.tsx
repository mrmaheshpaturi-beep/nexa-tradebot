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

function formatAccountMetric(value: unknown, suffix = '') {
  if (value === null || value === undefined || value === '') return '—'
  const numericValue = Number(value)
  if (!Number.isFinite(numericValue)) return String(value)
  return `${numericValue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}${suffix}`
}

function unwrapAccountPayload(payload: unknown): Record<string, unknown> {
  let value = payload
  for (let depth = 0; depth < 3 && value && typeof value === 'object'; depth += 1) {
    const nested = (value as { data?: unknown }).data
    if (!nested || typeof nested !== 'object') break
    value = nested
  }
  return value && typeof value === 'object' ? value as Record<string, unknown> : {}
}

export function Mt5Dashboard() {
  const { source } = useTradingSource()
  const result = useService(useCallback(async () => {
    const [statusResult, accountResult] = await Promise.allSettled([mt5Api.status(), mt5Api.account()])
    return {
      status: statusResult.status === 'fulfilled' ? statusResult.value : null,
      account: accountResult.status === 'fulfilled' ? accountResult.value : null,
      accountError: accountResult.status === 'rejected' ? firstValidationError(accountResult.reason) : null,
    }
  }, []))
  if (source !== 'MT5_DEMO') return <Mt5Unavailable message="Switch the global source selector to MT5 DEMO to view external read models." />
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'MT5 status is unavailable.'} />
  const { status, account: accountEnvelope, accountError } = result.data
  // Deployments may have one or more Laravel/API client envelope layers.
  // Normalize them before reading the live terminal account fields.
  const account = unwrapAccountPayload(accountEnvelope)
  const accountAvailable = account.balance !== undefined && account.balance !== null
  const currency = typeof account.currency === 'string' && account.currency.trim() ? account.currency : 'MT5 DEMO'
  return <>
    <PageHeader title="MT5 Dashboard" description={accountAvailable ? 'Live account snapshot from the connected MT5 terminal.' : 'Waiting for an MT5 account snapshot.'} actions={<StatusBadge tone={accountAvailable ? 'good' : 'warning'}>{accountAvailable ? 'MT5 CONNECTED' : 'CONNECTING'}</StatusBadge>} />
    <ReadOnlyBanner />
    {!accountAvailable && <div className="warning-box"><p><strong>MT5 account unavailable</strong><span>{accountError ? `Bridge request failed: ${accountError}` : 'The bridge is reconnecting or your session needs to be refreshed.'}</span></p></div>}
    <div className="metric-grid mt5-account-metrics">
      <MetricCard label="Balance" value={formatAccountMetric(account.balance)} detail={accountAvailable ? `${currency} · Live MT5 account` : 'Waiting for connection'} />
      <MetricCard label="Equity" value={formatAccountMetric(account.equity)} detail="Current account value" />
      <MetricCard label="Credit" value={formatAccountMetric(account.credit)} detail="Broker-issued credit" />
      <MetricCard label="Margin" value={formatAccountMetric(account.margin)} detail="Margin currently reserved" />
      <MetricCard label="Free margin" value={formatAccountMetric(account.free_margin ?? account.margin_free)} detail="Available trading margin" />
      <MetricCard label="Margin level" value={formatAccountMetric(account.margin_level, '%')} detail="Equity relative to used margin" />
    </div>
    {accountAvailable && <Panel title="Connection metadata" subtitle="Credential-free server-side bridge boundary">
      <div className="settings-list">
        <div><span>Connection</span><strong>{status?.connection?.name ?? 'MT5 bridge'}</strong></div>
        <div><span>Environment</span><strong>{Number(account.trade_mode) === 0 ? 'DEMO' : String(account.trade_mode ?? status?.environment ?? 'DEMO')}</strong></div>
        <div><span>Execution</span><strong>DISABLED</strong></div>
        <div><span>Broker transmission</span><strong>FALSE</strong></div>
      </div>
    </Panel>}
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
  const create = async () => {
    setActionError('')
    try { await mt5Api.createConnection('Primary MT5 DEMO bridge', false); result.reload() } catch (error) { setActionError(firstValidationError(error)) }
  }
  const test = async (id: number) => {
    setBusyId(id); setActionError('')
    try { await mt5Api.testConnection(id); result.reload() } catch (error) { setActionError(firstValidationError(error)) } finally { setBusyId(undefined) }
  }
  const sync = async (id: number) => {
    setBusyId(id); setActionError('')
    try { await mt5Api.syncConnection(id); result.reload() } catch (error) { setActionError(firstValidationError(error)) } finally { setBusyId(undefined) }
  }
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'MT5 connections are unavailable.'} />
  return <>
    <PageHeader title="MT5 Accounts" description="Read-only bridge connections and sync controls. Credentials remain server-side only."
      actions={can('mt5.connections.manage') ? <button className="btn ghost" onClick={create}>ADD CONNECTION</button> : undefined} />
    {source === 'MT5_DEMO' ? <ReadOnlyBanner /> : <div className="info-banner">Simulation source is active. Switch to MT5 DEMO to inspect external connectivity without mock fallback.</div>}
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
            {can('mt5.sync') && <button className="btn ghost" disabled={busyId === connection.id || !connection.is_enabled} onClick={() => sync(connection.id)}>SYNC</button>}
          </div>,
        ])} />
    </Panel>
  </>
}
