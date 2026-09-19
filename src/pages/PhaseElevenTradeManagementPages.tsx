import { useCallback, useState } from 'react'
import { AlertTriangle, Pause, Play, RefreshCw, ShieldAlert } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseElevenApi } from '../api/services'
import { useAuth } from '../auth/authState'
import {
  DataTable, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, StatusBadge,
} from '../components/ui'
import { useService } from '../hooks/useService'

const value = (input: unknown) => (input === null || input === undefined || input === '' ? '—' : String(input))

export function PhaseElevenTradeManagementDashboard() {
  const { can } = useAuth()
  const [busy, setBusy] = useState('')
  const [actionError, setActionError] = useState('')
  const [actionMsg, setActionMsg] = useState('')
  const [selectedId, setSelectedId] = useState('')

  const dashboard = useService(useCallback(() => phaseElevenApi.dashboard(), []))
  const health = useService(useCallback(() => phaseElevenApi.health(), []))
  const status = useService(useCallback(() => phaseElevenApi.status(), []))
  const detail = useService(useCallback(
    () => (selectedId ? phaseElevenApi.show(selectedId) : Promise.resolve(null)),
    [selectedId],
  ))

  const reload = () => {
    dashboard.reload(); health.reload(); status.reload(); detail.reload()
  }

  const pause = async (id: string) => {
    if (!can('trade_management.manage')) return
    setBusy(`pause-${id}`); setActionError('')
    try {
      await phaseElevenApi.pause(id)
      setActionMsg('Auto management paused for position.')
      reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const resume = async (id: string) => {
    if (!can('trade_management.manage')) return
    setBusy(`resume-${id}`); setActionError('')
    try {
      await phaseElevenApi.resume(id)
      setActionMsg('Auto management resumed.')
      reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const evaluate = async (id: string) => {
    if (!can('trade_management.manage')) return
    setBusy(`eval-${id}`); setActionError('')
    try {
      const result = await phaseElevenApi.evaluate(id, { auto_execute: true })
      setActionMsg(`Decision ${String(result.decision.decision_type)} — ${String(result.decision.why ?? '')}`)
      setSelectedId(id)
      reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const tick = async () => {
    if (!can('trade_management.manage')) return
    setBusy('tick'); setActionError('')
    try {
      const result = await phaseElevenApi.monitorTick()
      setActionMsg(`Monitor tick evaluated ${String(result.evaluated ?? 0)} positions.`)
      reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  if (dashboard.loading && !dashboard.data) return <LoadingState />
  if (dashboard.error) return <ErrorState message={dashboard.error} onRetry={reload} />

  const cards = dashboard.data?.cards ?? {}
  const positions = dashboard.data?.positions ?? []
  const decisions = dashboard.data?.recent_decisions ?? []

  return (
    <div className="page-stack">
      <PageHeader
        title="Trade Management"
        subtitle="DEMO-only managed positions — break-even, trail, partial, exits. LIVE hard-blocked."
        actions={(
          <button type="button" className="btn-secondary" onClick={reload} disabled={Boolean(busy)}>
            <RefreshCw size={16} /> Refresh
          </button>
        )}
      />

      <div className="safety-strip demo-execution-banner" role="status">
        <ShieldAlert size={18} />
        <div>
          <strong>DEMO MANAGEMENT ONLY</strong>
          <span> — Foreign/manual positions never auto-managed. LIVE modification/close HARD BLOCKED. Sole order_send path unchanged.</span>
        </div>
      </div>

      <div className="metric-grid">
        <MetricCard label="Managed Positions" value={value(cards.managed_positions)} detail="Nexa-owned DEMO" />
        <MetricCard label="Open Demo" value={value(cards.open_demo_positions)} detail="Active managed" />
        <MetricCard label="Floating P/L" value={value(cards.floating_pnl)} detail="Mark-to-market" />
        <MetricCard label="Protected" value={value(cards.protected_positions)} detail="SL protecting" />
        <MetricCard label="Break-Even Applied" value={value(cards.break_even_applied)} detail="One-time BE" />
        <MetricCard label="Trailing Active" value={value(cards.trailing_active)} detail="Never loosens" />
        <MetricCard label="Partial Targets" value={value(cards.partial_targets_pending)} detail="TP1/TP2/TP3" />
        <MetricCard label="Engine" value={value(cards.engine_status)} detail={value(health.data?.status)} />
      </div>

      {(actionError || actionMsg) && (
        <Panel title={actionError ? 'Action error' : 'Action result'}>
          <p className={actionError ? 'text-danger' : 'text-success'}>{actionError || actionMsg}</p>
        </Panel>
      )}

      <Panel
        title="Open managed positions"
        actions={can('trade_management.manage') ? (
          <button type="button" className="btn-secondary" onClick={tick} disabled={busy === 'tick'}>
            Run monitor tick
          </button>
        ) : undefined}
      >
        {positions.length === 0 ? (
          <EmptyState title="No managed DEMO positions" description="Positions opened via Phase 10 DEMO execution become managed here." />
        ) : (
          <DataTable
            columns={['Symbol', 'Side', 'Volume', 'SL', 'TP', 'R', 'Status', 'Why', 'Actions']}
            rows={positions.map((p) => [
              value(p.symbol),
              value(p.direction),
              value(p.current_volume),
              value(p.current_stop_loss),
              value(p.current_take_profit),
              value(p.r_multiple),
              <StatusBadge key={`${p.public_id}-st`} tone="info">{value(p.management_status)}</StatusBadge>,
              value(p.why),
              <div key={`${p.public_id}-act`} className="inline-actions">
                <button type="button" className="btn-secondary" onClick={() => setSelectedId(String(p.public_id))}>Detail</button>
                {can('trade_management.manage') && (
                  <>
                    <button type="button" className="btn-secondary" onClick={() => evaluate(String(p.public_id))} disabled={Boolean(busy)}>
                      Evaluate
                    </button>
                    {p.auto_management_paused ? (
                      <button type="button" className="btn-secondary" onClick={() => resume(String(p.public_id))} disabled={Boolean(busy)}>
                        <Play size={14} /> Resume
                      </button>
                    ) : (
                      <button type="button" className="btn-secondary" onClick={() => pause(String(p.public_id))} disabled={Boolean(busy)}>
                        <Pause size={14} /> Pause
                      </button>
                    )}
                  </>
                )}
              </div>,
            ])}
          />
        )}
      </Panel>

      {selectedId && detail.data && (
        <Panel title={`Position detail · ${selectedId}`}>
          <div className="metric-grid">
            <MetricCard label="WHY" value={value(detail.data.why)} detail="Latest decision explanation" />
            <MetricCard label="MAE" value={value(detail.data.position.mae)} detail="Max adverse" />
            <MetricCard label="MFE" value={value(detail.data.position.mfe)} detail="Max favorable" />
            <MetricCard label="Ownership" value={value(detail.data.position.ownership)} detail="Must be NEXA_MANAGED" />
          </div>
          <h3 className="section-title">Chart markers</h3>
          <DataTable
            columns={['Type', 'Price', 'At', 'Status/Reason']}
            rows={(detail.data.chart_markers ?? []).map((m) => [
              value(m.type),
              value(m.price),
              value(m.at),
              value(m.status ?? m.reason),
            ])}
          />
          <h3 className="section-title">Lifecycle events</h3>
          <DataTable
            columns={['Event', 'Severity', 'At']}
            rows={(detail.data.events ?? []).slice(0, 15).map((e) => [
              value(e.event_type),
              value(e.severity),
              value(e.occurred_at),
            ])}
          />
        </Panel>
      )}

      <Panel title="Recent management decisions">
        {decisions.length === 0 ? (
          <EmptyState title="No decisions yet" description="Evaluate a managed position to produce HOLD / BE / TRAIL / CLOSE decisions." />
        ) : (
          <DataTable
            columns={['Type', 'Rule', 'Status', 'Why', 'At']}
            rows={decisions.map((d) => [
              value(d.decision_type),
              value(d.rule_code),
              value(d.status),
              value(d.why),
              value(d.decided_at),
            ])}
          />
        )}
      </Panel>

      <Panel title="Safety posture">
        <div className="inline-actions">
          <StatusBadge tone="success">LIVE MODIFICATION HARD BLOCKED</StatusBadge>
          <StatusBadge tone="success">LIVE PARTIAL CLOSE HARD BLOCKED</StatusBadge>
          <StatusBadge tone="success">LIVE FULL CLOSE HARD BLOCKED</StatusBadge>
          <StatusBadge tone="info">{value(status.data?.order_send_location)}</StatusBadge>
        </div>
        <p className="muted" style={{ marginTop: 12 }}>
          <AlertTriangle size={14} style={{ display: 'inline', marginRight: 6 }} />
          RiskLock blocks NEW entries but does not block protective DEMO closes when policy requires. ExecutionLock BLOCK_NEW_ENTRIES ≠ BLOCK_ALL_BROKER_ACTIONS.
        </p>
      </Panel>
    </div>
  )
}
