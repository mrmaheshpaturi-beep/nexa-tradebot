import { useCallback, useRef, useState } from 'react'
import { AlertOctagon, Check, CircleOff, Play, ShieldCheck } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { ConfirmationDialog, DataTable, EnvironmentBadge, ErrorState, LoadingState, MetricCard, PageHeader, Panel, PnLDisplay, StatusBadge } from '../components/ui'
import { EquityChart } from '../components/TradingCharts'
import { useAuth } from '../auth/authState'
import { useTradingSource } from '../context/tradingSourceState'
import { useService } from '../hooks/useService'
import { Mt5Dashboard } from './PhaseFourMt5Pages'
import { persistentServices } from '../services/persistenceServices'
import { services } from '../services/mockServices'

const number = (value: string | number | null | undefined) => Number(value ?? 0)

export function PersistentDashboard() {
  const { source } = useTradingSource()
  if (source === 'MT5_DEMO') return <Mt5Dashboard />
  return <SimulationDashboard />
}

function SimulationDashboard() {
  const summary = useService(useCallback(() => persistentServices.dashboard.getSummary(), []))
  const equity = useService(useCallback(() => services.analytics.getEquitySeries(), []))
  const { user } = useAuth()
  if (summary.loading) return <LoadingState />
  if (summary.error || !summary.data) return <ErrorState message={summary.error ?? 'No dashboard summary is available.'} />
  const snapshot = summary.data.latest_account_snapshot
  return <>
    <PageHeader title="Trading Overview" description={`Persistent operational summary for ${user?.name ?? 'the current user'}.`} actions={<EnvironmentBadge />} />
    <div className="status-ribbon">
      <div><span className="status-dot" /><p><small>TRADING ENVIRONMENT</small><strong>SIMULATION</strong></p></div>
      <div><CircleOff /><p><small>BROKER</small><strong>DISCONNECTED</strong></p></div>
      <div><ShieldCheck /><p><small>ACCOUNT SOURCE</small><strong>DATABASE SNAPSHOT</strong></p></div>
      <div><span className="status-dot" /><p><small>MARKET DATA</small><strong>MOCK</strong></p></div>
    </div>
    <div className="metrics-grid">
      <MetricCard label="Balance" value={snapshot ? `$${number(snapshot.balance).toLocaleString()}` : 'No snapshot'} detail={snapshot?.captured_at} />
      <MetricCard label="Equity" value={snapshot ? `$${number(snapshot.equity).toLocaleString()}` : '—'} />
      <MetricCard label="Floating P/L" value={snapshot ? <PnLDisplay value={number(snapshot.floating_pnl)} /> : '—'} />
      <MetricCard label="Free Margin" value={snapshot ? `$${number(snapshot.free_margin).toLocaleString()}` : '—'} />
      <MetricCard label="Strategies" value={summary.data.strategies} detail="Persisted records" />
      <MetricCard label="Broker Accounts" value={summary.data.broker_accounts} />
      <MetricCard label="Simulation Orders" value={summary.data.simulation_orders} />
      <MetricCard label="Unread Notifications" value={summary.data.unread_notifications} />
    </div>
    <div className="two-thirds-grid">
      <Panel title="Account Growth" subtitle="Illustrative mock series; latest snapshot metrics above are authoritative">{equity.data ? <EquityChart data={equity.data} /> : <LoadingState />}</Panel>
      <Panel title="Persistent activity" subtitle="Database summary"><div className="settings-list">
        <div><span>System events</span><strong>{summary.data.system_events}</strong></div>
        <div><span>Audit records</span><strong>{summary.data.audit_logs}</strong></div>
        <div><span>Current drawdown</span><strong>{snapshot ? `${number(snapshot.drawdown)}%` : '—'}</strong></div>
        <div><span>Margin level</span><strong>{snapshot?.margin_level ? `${number(snapshot.margin_level)}%` : '—'}</strong></div>
      </div></Panel>
    </div>
  </>
}

export function PersistentStrategies() {
  const load = useCallback(() => persistentServices.strategies.getStrategies(), [])
  const { data, loading, error } = useService(load)
  const { can } = useAuth()
  if (loading) return <LoadingState />
  if (error || !data) return <ErrorState message={error ?? 'Strategies are unavailable.'} />
  return <>
    <PageHeader title="Strategy Manager" description="Database-backed signal-only and manual strategies." actions={can('strategies.create') ? <StatusBadge tone="info">CREATE API AVAILABLE</StatusBadge> : undefined} />
    <Panel title="Strategy library" subtitle={`${data.total} persisted strategies`}>
      <DataTable columns={['Strategy', 'Category', 'Symbols', 'Timeframes', 'Mode', 'Status', 'Score', 'Version', 'Execution']}
        rows={data.data.map((strategy) => [
          <strong key={strategy.id}>{strategy.name}</strong>, strategy.category, strategy.symbols.join(', '), strategy.timeframes.join(', '), strategy.mode,
          <StatusBadge key={`${strategy.id}-status`} tone={strategy.enabled ? 'good' : 'neutral'}>{strategy.status}</StatusBadge>,
          number(strategy.minimum_signal_score).toFixed(2), strategy.version,
          <StatusBadge key={`${strategy.id}-exec`} tone="warning">{strategy.auto_trading_enabled ? 'UNEXPECTED' : 'DISABLED'}</StatusBadge>,
        ])} />
    </Panel>
  </>
}

export function PersistentManualTrading() {
  const { can } = useAuth()
  const [direction, setDirection] = useState<'BUY' | 'SELL'>('BUY')
  const [symbol, setSymbol] = useState('XAUUSD')
  const [volume, setVolume] = useState(0.4)
  const [risk, setRisk] = useState(1)
  const [comment, setComment] = useState('')
  const [confirm, setConfirm] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [result, setResult] = useState('')
  const [error, setError] = useState('')
  const identity = useRef(persistentServices.orders.createIdentity())
  const submit = async () => {
    if (submitting) return
    setSubmitting(true)
    setError('')
    try {
      const order = await persistentServices.orders.submit({
        symbol, direction, volume, risk_percent: risk, comment,
        requested_price: 2642.2, stop_loss: 2631.4, take_profit: 2671.8,
      }, identity.current)
      setResult(`${order.public_id}${order.idempotent_replay ? ' (existing request)' : ''}`)
      identity.current = persistentServices.orders.createIdentity()
    } catch (caught) {
      setError(firstValidationError(caught))
    } finally {
      setSubmitting(false)
      setConfirm(false)
    }
  }
  return <>
    <PageHeader title="Manual Trading Terminal" description="Persist a simulation order. The backend cannot transmit it to a broker." actions={<EnvironmentBadge />} />
    {result && <div className="success-banner"><Check /> Simulation order {result} persisted. No broker action occurred.</div>}
    {error && <div className="danger-banner" role="alert"><AlertOctagon />{error}</div>}
    <Panel title="Simulation order ticket" subtitle="UUID command and idempotency keys protect against duplicate submission">
      <div className="direction-toggle"><button className={direction === 'BUY' ? 'buy active' : ''} onClick={() => setDirection('BUY')}>BUY</button><button className={direction === 'SELL' ? 'sell active' : ''} onClick={() => setDirection('SELL')}>SELL</button></div>
      <div className="form-grid">
        <label className="field"><span>Symbol</span><select value={symbol} onChange={(e) => setSymbol(e.target.value)}>{['XAUUSD', 'EURUSD', 'GBPUSD', 'USDJPY', 'NAS100', 'BTCUSD'].map((item) => <option key={item}>{item}</option>)}</select></label>
        <label className="field"><span>Volume</span><input type="number" min=".01" max="5" step=".01" value={volume} onChange={(e) => setVolume(Number(e.target.value))} /></label>
        <label className="field"><span>Risk %</span><input type="number" min="0" max="10" step=".1" value={risk} onChange={(e) => setRisk(Number(e.target.value))} /></label>
        <label className="field wide"><span>Comment</span><input maxLength={255} value={comment} onChange={(e) => setComment(e.target.value)} /></label>
      </div>
      <button className={`btn ${direction === 'BUY' ? 'buy' : 'sell'} persistence-submit`} disabled={!can('simulation_orders.create') || submitting} onClick={() => setConfirm(true)}><Play />{submitting ? 'SUBMITTING…' : `SIMULATE ${direction}`}</button>
      {!can('simulation_orders.create') && <p className="permission-note">Your role does not expose simulation order controls.</p>}
    </Panel>
    <ConfirmationDialog open={confirm} title={`Confirm simulated ${direction}`} onCancel={() => setConfirm(false)} onConfirm={submit}>This writes one simulation-only database record. Repeated network delivery uses the same idempotency key.</ConfirmationDialog>
  </>
}
