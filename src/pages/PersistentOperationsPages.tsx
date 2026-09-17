import { useCallback, useState } from 'react'
import { AlertOctagon, Bell, Check, ShieldAlert } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { useAuth } from '../auth/authState'
import { DataTable, EmptyState, ErrorState, LoadingState, PageHeader, Panel, RiskGauge, StatusBadge } from '../components/ui'
import { useSimulation } from '../context/simulationState'
import { useService } from '../hooks/useService'
import { persistentServices } from '../services/persistenceServices'
import { phaseTwoApi } from '../api/services'

const num = (value: string | number) => Number(value)

export function PersistentRiskManagement() {
  const result = useService(useCallback(() => Promise.all([persistentServices.risk.getProfiles(), phaseTwoApi.status()]), []))
  const { stopped, setStopped } = useSimulation()
  const { can } = useAuth()
  const [busy, setBusy] = useState(false)
  const [simulationBusy, setSimulationBusy] = useState(false)
  const [actionError, setActionError] = useState('')
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'Risk data is unavailable.'} />
  const [profiles, status] = result.data
  const profile = profiles.data.find((item) => item.is_default) ?? profiles.data[0]
  const toggle = async () => {
    setBusy(true); setActionError('')
    try { await setStopped(!stopped); result.reload() } catch (error) { setActionError(firstValidationError(error)) } finally { setBusy(false) }
  }
  const toggleSimulationExecution = async () => {
    setSimulationBusy(true); setActionError('')
    try {
      await phaseTwoApi.updateSetting('simulation_execution_enabled', !status.simulation_execution_enabled, true)
      result.reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setSimulationBusy(false)
    }
  }
  return <>
    <PageHeader title="Risk Management" description="Persistent risk profiles and the server-enforced emergency stop."
      actions={<div className="inline-controls"><button className="btn ghost" disabled={!can('settings.update') || simulationBusy} onClick={toggleSimulationExecution}>{status.simulation_execution_enabled ? 'DISABLE SIMULATION EXECUTION' : 'ENABLE SIMULATION EXECUTION'}</button><button className="btn danger" disabled={!can('emergency_stop.manage') || busy} onClick={toggle}><AlertOctagon />{stopped ? 'RESUME SIMULATION' : 'EMERGENCY STOP'}</button></div>} />
    {stopped && <div className="danger-banner"><ShieldAlert /> Simulation stopped. New simulation orders are rejected by the backend.</div>}
    {actionError && <div className="danger-banner" role="alert">{actionError}</div>}
    <div className="health-meta">
      <div><span>Persistent emergency stop</span><strong>{status.emergency_stop ? 'ENABLED' : 'DISABLED'}</strong></div>
      <div><span>Simulation execution enabled</span><strong>{status.simulation_execution_enabled ? 'ENABLED' : 'DISABLED'}</strong></div>
      <div><span>Trading enabled</span><strong>{status.trading_enabled ? 'UNEXPECTEDLY ENABLED' : 'FALSE / LIVE LOCKED'}</strong></div>
      <div><span>Broker execution</span><strong>DISABLED</strong></div>
    </div>
    <div className="info-banner">Simulation execution is a separate, permission-controlled ledger feature. It cannot enable broker or live execution.</div>
    {!can('emergency_stop.manage') && <p className="permission-note">Emergency-stop state is visible; your role cannot change it.</p>}
    {!profile ? <EmptyState title="No risk profile" detail="Create a profile through an authorized workflow." /> : <>
      <div className="gauge-grid">
        <RiskGauge label="Max risk / trade" value={num(profile.max_risk_per_trade)} limit={10} />
        <RiskGauge label="Daily loss limit" value={num(profile.max_daily_loss)} limit={100} />
        <RiskGauge label="Weekly loss limit" value={num(profile.max_weekly_loss)} limit={100} />
        <RiskGauge label="Max drawdown" value={num(profile.max_drawdown)} limit={100} />
        <RiskGauge label="Open risk limit" value={num(profile.max_open_risk)} limit={100} />
      </div>
      <Panel title={profile.name} subtitle={`${profile.status} · ${profile.is_default ? 'Default profile' : 'Profile'}`}>
        <div className="settings-list">
          <div><span>Max lot size</span><strong>{num(profile.max_lot_size)}</strong></div>
          <div><span>Max open positions</span><strong>{profile.max_open_positions}</strong></div>
          <div><span>Max trades / day</span><strong>{profile.max_trades_per_day}</strong></div>
          <div><span>Minimum margin level</span><strong>{num(profile.min_margin_level)}%</strong></div>
          <div><span>Minimum reward / risk</span><strong>{num(profile.min_reward_risk)}</strong></div>
        </div>
      </Panel>
    </>}
  </>
}

export function PersistentAccounts() {
  const result = useService(useCallback(() => persistentServices.accounts.getAccounts(), []))
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'Broker metadata is unavailable.'} />
  return <>
    <PageHeader title="MT5 / Broker Accounts" description="Credential-free database metadata. No connection adapter exists." actions={<StatusBadge tone="bad">DISCONNECTED</StatusBadge>} />
    <Panel title="Account inventory" subtitle={`${result.data.total} persisted metadata records`}>
      <DataTable columns={['Name', 'Broker', 'Platform', 'Server', 'Reference', 'Environment', 'Currency', 'Leverage', 'Status']}
        rows={result.data.data.map((account) => [<strong key={account.id}>{account.name}</strong>, account.broker ?? '—', account.platform, account.server ?? '—', account.account_reference ?? '—', account.environment, account.currency, `1:${account.leverage}`, <StatusBadge key={`${account.id}-status`} tone={['CONFIGURED', 'CONNECTED'].includes(account.status) ? 'good' : account.status === 'CONNECTING' ? 'warning' : 'bad'}>{account.status}</StatusBadge>])} />
    </Panel>
    <div className="warning-box"><ShieldAlert /><p><strong>Credential-safe boundary</strong><span>The API prohibits password, token, API key, secret, and live-execution fields.</span></p></div>
  </>
}

export function PersistentNotifications() {
  const loader = useCallback(() => persistentServices.notifications.getNotifications(), [])
  const result = useService(loader)
  const { can } = useAuth()
  const [working, setWorking] = useState<string>()
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'Notifications are unavailable.'} />
  const markRead = async (id: string) => {
    setWorking(id)
    try { await persistentServices.notifications.markRead(id); result.reload() } finally { setWorking(undefined) }
  }
  return <>
    <PageHeader title="Notifications" description="Persistent read state for the current user." />
    <Panel title="Notification center" subtitle={`${result.data.data.filter((item) => !item.is_read).length} unread on this page`}>
      <div className="notification-list">{result.data.data.map((item) => <div key={item.id} className={item.is_read ? '' : 'unread'}>
        <span className="notification-icon"><Bell /></span><StatusBadge tone={item.severity === 'CRITICAL' ? 'bad' : item.severity === 'WARNING' ? 'warning' : 'info'}>{item.category}</StatusBadge>
        <p><strong>{item.title}</strong><span>{item.message}</span></p><time>{new Date(item.created_at).toLocaleString()}</time>
        {!item.is_read && can('notifications.update') && <button className="btn ghost" disabled={working === item.id} onClick={() => markRead(item.id)}><Check /> Read</button>}
      </div>)}</div>
    </Panel>
  </>
}

export function PersistentSystemHealth() {
  const result = useService(useCallback(() => phaseTwoApi.status(), []))
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'System status is unavailable.'} />
  const status = result.data
  const services = [
    ['Web App', status.web_application.status, 'React + Laravel'],
    ['DB verified', status.database.status === 'CONNECTED' ? 'VERIFIED' : 'UNAVAILABLE', status.database.source],
    ['Auth', status.authentication.status, 'Sanctum session guard'],
    ['Trading Domain', 'ONLINE', 'Persistent Phase 3 lifecycle APIs'],
    ['Simulation Adapter', 'AVAILABLE', status.terminal.adapter],
    ['Risk Simulator', 'AVAILABLE', status.risk_execution.mode],
    ['Market Data', status.market_data.status, status.market_data.source],
    ['Trading Engine', 'NOT IMPLEMENTED', 'No live trading engine'],
    ['MT5', 'NOT CONNECTED', status.terminal.status],
    ['Broker', 'NOT CONNECTED', 'No broker adapter'],
    ['Live Execution', 'DISABLED', 'Broker transmission is false'],
  ]
  return <>
    <PageHeader title="System Health" description="Exact Phase 3 capability boundary from backend status." actions={<StatusBadge tone={status.database.status === 'CONNECTED' ? 'good' : 'bad'}>PHASE 3</StatusBadge>} />
    <div className="health-meta"><div><span>Last simulation heartbeat</span><strong>{status.simulation_engine.last_heartbeat_at ? new Date(status.simulation_engine.last_heartbeat_at).toLocaleString() : 'NONE'}</strong></div><div><span>Environment</span><strong>SIMULATION</strong></div><div><span>Broker transmission</span><strong>FALSE</strong></div><div><span>Live execution</span><strong>DISABLED</strong></div></div>
    <div className="health-grid">{services.map(([name, state, detail]) => <article key={name}><div className={`health-icon ${state.toLowerCase().replaceAll(' ', '-')}`}><span /></div><p><strong>{name}</strong><span>{detail}</span></p><StatusBadge tone={['ONLINE', 'CONNECTED', 'VERIFIED', 'AVAILABLE'].includes(state) ? 'good' : state === 'MOCK' ? 'info' : state === 'STOPPED' ? 'warning' : 'bad'}>{state}</StatusBadge></article>)}</div>
  </>
}

export function PersistentAuditLogs() {
  const result = useService(useCallback(() => persistentServices.audit.getEvents(), []))
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'Audit logs are unavailable.'} />
  return <>
    <PageHeader title="Audit Logs" description="Immutable database activity ledger." />
    <Panel title="Activity ledger" subtitle={`${result.data.total} persisted events`}>
      <DataTable columns={['Timestamp', 'User', 'Action', 'Module', 'Description', 'IP', 'Result']}
        rows={result.data.data.map((event) => [new Date(event.occurred_at).toLocaleString(), event.user?.name ?? 'System', event.action, event.module, event.description, event.ip_address ?? '—', <StatusBadge key={event.id} tone={event.result === 'SUCCESS' ? 'good' : 'bad'}>{event.result}</StatusBadge>])} />
    </Panel>
  </>
}
