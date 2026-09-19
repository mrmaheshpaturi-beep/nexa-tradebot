import { useCallback, useState } from 'react'
import { AlertOctagon, Lock, ShieldAlert, Unlock } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseNineApi, phaseTwoApi } from '../api/services'
import type { RiskDecision, RiskLockRecord } from '../api/types'
import { useAuth } from '../auth/authState'
import {
  DataTable, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, RiskGauge, StatusBadge,
} from '../components/ui'
import { useSimulation } from '../context/simulationState'
import { useService } from '../hooks/useService'

const num = (value: string | number | null | undefined) => Number(value ?? 0)
const value = (input: unknown) => (input === null || input === undefined || input === '' ? '—' : String(input))

export function PhaseNineRiskManagement() {
  const { can } = useAuth()
  const { stopped, setStopped } = useSimulation()
  const [busy, setBusy] = useState('')
  const [actionError, setActionError] = useState('')
  const [actionMsg, setActionMsg] = useState('')
  const [selectedDecision, setSelectedDecision] = useState<RiskDecision | null>(null)

  const dashboard = useService(useCallback(() => phaseNineApi.dashboard(), []))
  const status = useService(useCallback(() => phaseTwoApi.status(), []))

  const reloadAll = () => { dashboard.reload(); status.reload() }

  const toggleEmergency = async () => {
    if (!can('emergency_stop.manage')) return
    setBusy('stop'); setActionError('')
    try {
      await setStopped(!stopped)
      setActionMsg(stopped ? 'Emergency stop cleared' : 'Emergency stop enabled')
      reloadAll()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const releaseLock = async (lock: RiskLockRecord) => {
    if (!can('risk_engine.lock')) {
      setActionError('Your role cannot release risk locks.')
      return
    }
    setBusy(lock.public_id); setActionError(''); setActionMsg('')
    try {
      await phaseNineApi.releaseLock(lock.public_id)
      setActionMsg(`Released ${lock.public_id}`)
      dashboard.reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const createManualLock = async () => {
    if (!can('risk_engine.lock')) {
      setActionError('Your role cannot create risk locks.')
      return
    }
    setBusy('lock'); setActionError(''); setActionMsg('')
    try {
      await phaseNineApi.createLock({
        lock_type: 'MANUAL',
        reason_code: 'RISK_LOCK',
        message: 'Manual risk lock from Risk Management UI',
        account_public_id: dashboard.data?.account?.public_id,
      })
      setActionMsg('Manual risk lock created')
      dashboard.reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  if (dashboard.loading && !dashboard.data) return <LoadingState />
  if (dashboard.error && !dashboard.data) return <ErrorState message={dashboard.error} />

  const data = dashboard.data!
  const profile = data.profile
  const ctx = data.account_context
  const engine = data.engine as Record<string, unknown>

  return <>
    <PageHeader
      title="Risk Engine"
      description="Authoritative server-side RiskEngine. UI never decides risk alone. Proposed plans are not broker orders."
      actions={<div className="inline-controls">
        <button className="btn ghost" disabled={!can('risk_engine.lock') || busy === 'lock'} onClick={createManualLock}><Lock /> Manual lock</button>
        <button className="btn danger" disabled={!can('emergency_stop.manage') || busy === 'stop'} onClick={toggleEmergency}><AlertOctagon />{stopped ? 'Resume' : 'Emergency stop'}</button>
      </div>}
    />

    {stopped && <div className="danger-banner"><ShieldAlert /> Emergency stop active — new simulation execution is blocked.</div>}
    {actionError && <div className="danger-banner" role="alert">{actionError}</div>}
    {actionMsg && <div className="info-banner">{actionMsg}</div>}

    <div className="metric-grid">
      <MetricCard label="Engine" value={String(engine.status ?? 'READY')} detail={String(engine.engine_version ?? 'RiskEngine/v1')} />
      <MetricCard label="Fail closed" value={engine.fail_closed ? 'YES' : 'NO'} detail="Insufficient data rejects" />
      <MetricCard label="order_send" value="NONE" detail="No MT5 write path" />
      <MetricCard label="DEMO / LIVE" value="DISABLED" detail="ExecutionGate rejects" />
    </div>

    <div className="health-meta">
      <div><span>Profile version</span><strong>{profile?.version ?? '—'}</strong></div>
      <div><span>Rules bundle</span><strong>{profile?.rules_bundle_version ?? String(engine.rules_bundle_version ?? '—')}</strong></div>
      <div><span>Active locks</span><strong>{data.active_locks.length}</strong></div>
      <div><span>Active reservations</span><strong>{data.active_reservations.length}</strong></div>
      <div><span>Broker routable</span><strong>FALSE</strong></div>
    </div>

    {profile && <div className="gauge-grid">
      <RiskGauge label="Max risk / trade" value={num(profile.max_risk_per_trade)} limit={10} />
      <RiskGauge label="Daily loss %" value={ctx ? Math.max(0, -num(ctx.daily_realized_pnl as number) / Math.max(num(ctx.balance as number), 1) * 100) : 0} limit={num(profile.max_daily_loss)} />
      <RiskGauge label="Drawdown" value={num(ctx?.drawdown as number)} limit={num(profile.max_drawdown)} />
      <RiskGauge label="Open risk %" value={num(ctx?.open_risk_percent as number)} limit={num(profile.max_open_risk)} />
      <RiskGauge label="Margin level" value={num(ctx?.margin_level as number) || 9999} limit={num(profile.min_margin_level)} inverse />
    </div>}

    <div className="split-panels">
      <Panel title="Risk locks" subtitle="Block new intents when limits breach">
        {data.active_locks.length === 0 ? <EmptyState title="No active locks" detail="Locks appear after breach or manual action." /> : (
          <DataTable
            columns={['Lock', 'Type', 'Reason', 'Message', 'Action']}
            rows={data.active_locks.map((lock) => [
              <strong key={lock.public_id}>{lock.public_id}</strong>,
              lock.lock_type,
              lock.reason_code,
              lock.message,
              <button key={`${lock.public_id}-rel`} className="btn ghost" disabled={!can('risk_engine.lock') || busy === lock.public_id} onClick={() => releaseLock(lock)}><Unlock /> Release</button>,
            ])}
          />
        )}
      </Panel>

      <Panel title="Recent risk decisions" subtitle="Immutable once recorded">
        {data.recent_decisions.length === 0 ? <EmptyState title="No decisions yet" detail="Evaluate a trade intent to create one." /> : (
          <DataTable
            columns={['Decision', 'Status', 'Reason', 'Volume', 'Profile ver', 'Detail']}
            rows={data.recent_decisions.map((decision) => [
              <strong key={decision.public_id}>{decision.public_id}</strong>,
              <StatusBadge key={`${decision.public_id}-st`} tone={decision.status === 'APPROVED' ? 'good' : 'bad'}>{decision.status}</StatusBadge>,
              decision.reason_code,
              value(decision.approved_volume ?? decision.requested_volume),
              value(decision.profile_version),
              <button key={`${decision.public_id}-view`} className="btn ghost" onClick={() => setSelectedDecision(decision)}>View</button>,
            ])}
          />
        )}
      </Panel>
    </div>

    {profile && <Panel title={`Profile · ${profile.name}`} subtitle={`${profile.status} · config ${profile.config_hash?.slice(0, 12) ?? '—'}…`}>
      <div className="settings-list">
        <div><span>Max lot</span><strong>{num(profile.max_lot_size)}</strong></div>
        <div><span>Max open positions</span><strong>{profile.max_open_positions}</strong></div>
        <div><span>Min reward/risk</span><strong>{num(profile.min_reward_risk)}</strong></div>
        <div><span>Max correlated exposure</span><strong>{num(profile.max_correlated_exposure)}</strong></div>
        <div><span>Require stop loss</span><strong>{profile.require_stop_loss === false ? 'NO' : 'YES'}</strong></div>
        <div><span>Sizing enabled</span><strong>{profile.sizing_enabled === false ? 'NO' : 'YES'}</strong></div>
        <div><span>Max spread (pips)</span><strong>{num(profile.max_spread)}</strong></div>
        <div><span>Min margin level</span><strong>{num(profile.min_margin_level)}%</strong></div>
      </div>
    </Panel>}

    {selectedDecision && <aside className="drawer lifecycle-drawer" aria-label="Risk decision detail">
      <button className="drawer-close" aria-label="Close" onClick={() => setSelectedDecision(null)}>×</button>
      <span className="eyebrow">PHASE 9 / RISK DECISION</span>
      <h2>{selectedDecision.public_id}</h2>
      <p>{selectedDecision.message}</p>
      <div className="detail-list">
        <div><span>Status</span><strong>{selectedDecision.status}</strong></div>
        <div><span>Reason</span><strong>{selectedDecision.reason_code}</strong></div>
        <div><span>Engine</span><strong>{selectedDecision.engine_version ?? '—'}</strong></div>
        <div><span>Profile version</span><strong>{value(selectedDecision.profile_version)}</strong></div>
        <div><span>Config hash</span><strong>{selectedDecision.config_hash?.slice(0, 16) ?? '—'}</strong></div>
        <div><span>Approved volume</span><strong>{value(selectedDecision.approved_volume)}</strong></div>
        <div><span>Reward/risk</span><strong>{value(selectedDecision.reward_risk)}</strong></div>
        <div><span>Immutable</span><strong>{selectedDecision.immutable === false ? 'NO' : 'YES'}</strong></div>
        <div><span>Proposed plan</span><strong>{selectedDecision.proposed_plan?.public_id ?? '—'}</strong></div>
        <div><span>Broker routable</span><strong>{selectedDecision.proposed_plan?.broker_routable ? 'YES' : 'NO'}</strong></div>
      </div>
      {selectedDecision.rule_results && selectedDecision.rule_results.length > 0 && (
        <Panel title="Rule results" subtitle="Deterministic evidence">
          <DataTable
            columns={['Rule', 'Passed', 'Reason']}
            rows={selectedDecision.rule_results.map((rule) => [
              rule.code,
              rule.passed ? 'YES' : 'NO',
              rule.reason ?? '—',
            ])}
          />
        </Panel>
      )}
    </aside>}
  </>
}
